<?php

/**
 * Phase E — operation ledger for agent (wholesale) bot purchases/extends.
 *
 * One row in reseller_operation per purchase/extend attempt. States:
 *
 *   started -> charged -> delivering -> succeeded
 *   charged|delivering -> refund_pending -> refunded   (definitive delivery failure)
 *   charged|delivering -> unknown                      (unverifiable: NO auto-refund)
 *   started -> failed                                  (definitive failure before charge)
 *
 * Money rules:
 *  - Charge = ONE transaction: conditional balance UPDATE + journal INSERT
 *    (ref wholesale_{type}_{op_id}) + conditional op status UPDATE.
 *    All-or-nothing: journal-without-status or status-without-journal is
 *    impossible by construction.
 *  - Refund = ONE transaction with ref refund_{type}_{op_id}; idempotent.
 *  - Concurrent workers on the same op_id: conditional UPDATEs claim the
 *    state; only one advances, the other rolls back.
 *  - Nothing in 'unknown' is refunded or retried automatically.
 */

const LEDGER_OP_PURCHASE = 'purchase';
const LEDGER_OP_EXTEND = 'extend';

/** Fresh row read straight from PDO (bypasses the select() request cache). */
function ledgerGetOperation(string $opId): ?array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM reseller_operation WHERE op_id = ? LIMIT 1");
    $stmt->execute([$opId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Create the operation row. op_id must be stable for the whole cycle
 * (purchase: derived from the invoice id; extend: generated once per tap).
 * Returns the op row, or the existing row when the op_id already exists.
 */
function ledgerCreateOperation(string $opId, string $type, string $agentId, ?int $botId, ?string $buyerId, ?string $invoiceId, ?string $panelName, ?string $panelUsername, int $amount, array $meta = []): ?array
{
    global $pdo;
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO reseller_operation
            (op_id, type, agent_id, bot_id, buyer_id, invoice_id, panel_name, panel_username, amount, status, meta, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'started', ?, ?, ?)"
    );
    $stmt->execute([
        $opId, $type, $agentId, $botId, $buyerId, $invoiceId,
        $panelName, $panelUsername, $amount,
        json_encode($meta, JSON_UNESCAPED_UNICODE), $now, $now,
    ]);
    return ledgerGetOperation($opId);
}

/**
 * Conditional status transition. Only succeeds when the current status is
 * one of $from — this is the concurrency guard for every state change.
 */
function ledgerTransition(string $opId, array $from, string $to, array $fields = []): bool
{
    global $pdo;
    $set = "status = ?, updated_at = ?";
    $params = [$to, date('Y-m-d H:i:s')];
    foreach (['attempts', 'last_error', 'expected_data_limit', 'expected_expire', 'meta'] as $col) {
        if (array_key_exists($col, $fields)) {
            $set .= ", $col = ?";
            $params[] = $col === 'meta' && is_array($fields[$col])
                ? json_encode($fields[$col], JSON_UNESCAPED_UNICODE)
                : $fields[$col];
        }
    }
    $placeholders = implode(',', array_fill(0, count($from), '?'));
    $stmt = $pdo->prepare("UPDATE reseller_operation SET $set WHERE op_id = ? AND status IN ($placeholders)");
    $stmt->execute(array_merge($params, [$opId], $from));
    return $stmt->rowCount() === 1;
}

/**
 * Charge the wholesale wallet for an operation (atomic with the status
 * claim). Returns the new agent balance, or null when refused.
 * Idempotent replay: an op already in 'charged' returns the current balance
 * so a crashed caller can safely continue to delivery.
 */
function ledgerChargeOperation(string $opId): ?int
{
    global $pdo;
    $op = ledgerGetOperation($opId);
    if ($op === null) {
        return null;
    }
    if ($op['status'] === 'charged') {
        $stmt = $pdo->prepare("SELECT Balance FROM user WHERE id = ?");
        $stmt->execute([$op['agent_id']]);
        $bal = $stmt->fetchColumn();
        return $bal === false ? null : (int) $bal;
    }
    if ($op['status'] !== 'started') {
        return null;
    }
    $amount = (int) $op['amount'];
    if ($amount <= 0) {
        // Free operation: nothing to charge, just claim the state.
        return ledgerTransition($opId, ['started'], 'charged') ? 0 : null;
    }
    $agentStmt = $pdo->prepare("SELECT * FROM user WHERE id = ? LIMIT 1");
    $agentStmt->execute([$op['agent_id']]);
    $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);
    if ($agent === false) {
        return null;
    }
    $minBalance = ($agent['agent'] ?? 'f') === 'n2' && intval($agent['maxbuyagent'] ?? 0) !== 0
        ? -intval($agent['maxbuyagent'])
        : 0;
    $ref = 'wholesale_' . $op['type'] . '_' . $opId;

    $pdo->beginTransaction();
    try {
        // 1) Claim the operation (concurrency guard).
        $claim = $pdo->prepare("UPDATE reseller_operation SET status = 'charged', updated_at = ? WHERE op_id = ? AND status = 'started'");
        $claim->execute([date('Y-m-d H:i:s'), $opId]);
        if ($claim->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        // 2) Atomic balance guard.
        $debit = $pdo->prepare("UPDATE user SET Balance = Balance - ? WHERE id = ? AND Balance - ? >= ?");
        $debit->execute([$amount, $op['agent_id'], $amount, $minBalance]);
        if ($debit->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        // 3) Journal (duplicate ref inside the tx = rollback everything).
        $newBalance = (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote((string) $op['agent_id']))->fetchColumn();
        $journalId = resellerJournal((string) $op['agent_id'], 'wholesale_' . $op['type'], -$amount, $ref, [
            'op_id' => $opId,
            'invoice' => $op['invoice_id'],
            'buyer' => $op['buyer_id'],
            'bot_id' => $op['bot_id'],
        ], $newBalance);
        if ($journalId === 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        clearSelectCache('user');
        return $newBalance;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ledgerChargeOperation failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Idempotent refund for a definitively failed delivery.
 * Only ops that actually reached 'charged'/'delivering' can be refunded.
 * Returns true when the refund is in place (including replay on an already
 * refunded op), false when the op is not refundable.
 */
function ledgerRefundOperation(string $opId, string $reason): bool
{
    global $pdo;
    $op = ledgerGetOperation($opId);
    if ($op === null) {
        return false;
    }
    if ($op['status'] === 'refunded') {
        return true; // idempotent replay
    }
    if (!in_array($op['status'], ['charged', 'delivering', 'refund_pending'], true)) {
        return false; // never charged, or unknown/succeeded/failed — no auto-refund
    }
    $amount = (int) $op['amount'];
    if ($op['status'] !== 'refund_pending'
        && !ledgerTransition($opId, ['charged', 'delivering'], 'refund_pending', ['last_error' => $reason])) {
        return false;
    }
    if ($amount <= 0) {
        return ledgerTransition($opId, ['refund_pending'], 'refunded', ['last_error' => $reason]);
    }
    $ref = 'refund_' . $op['type'] . '_' . $opId;

    $pdo->beginTransaction();
    try {
        $credit = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
        $credit->execute([$amount, $op['agent_id']]);
        if ($credit->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $newBalance = (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote((string) $op['agent_id']))->fetchColumn();
        $journalId = resellerJournal((string) $op['agent_id'], 'refund_' . $op['type'], $amount, $ref, [
            'op_id' => $opId,
            'invoice' => $op['invoice_id'],
            'reason' => $reason,
        ], $newBalance);
        if ($journalId === 0) {
            // Refund journal already exists (crashed earlier attempt): accept
            // only when the recorded amount matches this operation exactly.
            $check = $pdo->prepare("SELECT amount FROM agent_transaction WHERE ref = ? LIMIT 1");
            $check->execute([$ref]);
            $existing = $check->fetchColumn();
            if ($existing === false || (int) $existing !== $amount) {
                $pdo->rollBack();
                return false;
            }
        }
        $done = $pdo->prepare("UPDATE reseller_operation SET status = 'refunded', updated_at = ? WHERE op_id = ? AND status = 'refund_pending'");
        $done->execute([date('Y-m-d H:i:s'), $opId]);
        if ($done->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        clearSelectCache('user');
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ledgerRefundOperation failed: ' . $e->getMessage());
        return false;
    }
}

function ledgerMarkDelivering(string $opId): bool
{
    global $pdo;
    $pdo->prepare("UPDATE reseller_operation SET attempts = attempts + 1 WHERE op_id = ?")->execute([$opId]);
    return ledgerTransition($opId, ['charged'], 'delivering');
}

function ledgerMarkSucceeded(string $opId): bool
{
    return ledgerTransition($opId, ['charged', 'delivering'], 'succeeded');
}

function ledgerMarkUnknown(string $opId, string $error): bool
{
    return ledgerTransition($opId, ['charged', 'delivering'], 'unknown', ['last_error' => $error]);
}

/** Definitive failure before any charge (e.g. wallet refused). No refund. */
function ledgerMarkFailed(string $opId, string $error): bool
{
    return ledgerTransition($opId, ['started'], 'failed', ['last_error' => $error]);
}

/* ----------------------------------------------------------------------- */
/*  Panel verification (Marzban)                                            */
/* ----------------------------------------------------------------------- */

/**
 * Classify a raw getuser() response (CurlRequest format).
 * 'absent' is returned ONLY for a reliable HTTP 404 from the panel;
 * timeouts, curl errors, 5xx and broken bodies stay 'unknown'.
 */
function ledgerClassifyMarzbanGetuser($response): array
{
    if (!is_array($response) || !empty($response['error'])) {
        return ['result' => 'unknown', 'user' => null];
    }
    $status = $response['status'] ?? null;
    if ($status === 404) {
        return ['result' => 'absent', 'user' => null];
    }
    if ($status === 200) {
        $body = json_decode($response['body'] ?? '', true);
        if (is_array($body) && isset($body['username'])) {
            return ['result' => 'present', 'user' => $body];
        }
        return ['result' => 'unknown', 'user' => null];
    }
    return ['result' => 'unknown', 'user' => null];
}

/**
 * Verify whether a username exists on the panel.
 * result: present | absent | unknown | unsupported (non-Marzban panel).
 */
function ledgerVerifyUserExists(string $panelName, string $username): array
{
    $panel = select("marzban_panel", "*", "name_panel", $panelName, "select");
    if (!is_array($panel)) {
        return ['result' => 'unknown', 'user' => null];
    }
    if (($panel['type'] ?? '') !== 'marzban' || !function_exists('getuser')) {
        return ['result' => 'unsupported', 'user' => null];
    }
    return ledgerClassifyMarzbanGetuser(getuser($username, $panelName));
}

/**
 * Verify an extend separately from user existence: the stored expected
 * data_limit/expire must match the live panel values. A mismatch is NOT a
 * definitive failure (another change may have raced us) — it stays
 * 'mismatch', which callers must treat like 'unknown' (no auto-refund).
 */
function ledgerVerifyExtendDelivery(array $op): array
{
    $v = ledgerVerifyUserExists((string) $op['panel_name'], (string) $op['panel_username']);
    if ($v['result'] !== 'present') {
        return $v;
    }
    $user = $v['user'];
    $expLimit = $op['expected_data_limit'];
    $expExpire = $op['expected_expire'];
    if ($expLimit === null && $expExpire === null) {
        return ['result' => 'unknown', 'user' => $user];
    }
    $limitOk = $expLimit === null ? true : (int) ($user['data_limit'] ?? -1) === (int) $expLimit;
    $expireOk = true;
    if ($expExpire !== null) {
        $actual = (int) ($user['expire'] ?? -1);
        $expireOk = abs($actual - (int) $expExpire) <= 300;
    }
    if ($limitOk && $expireOk) {
        return ['result' => 'delivered', 'user' => $user];
    }
    return ['result' => 'mismatch', 'user' => $user];
}
