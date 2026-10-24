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
/**
 * Normalize a panel expire value to a unix timestamp (or null when
 * unreadable). Panels return either an integer timestamp or an ISO-8601
 * datetime string (Marzban version_panel=1), and 0 means "unlimited".
 */
function ledgerNormalizeExpire($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    $t = strtotime((string) $value);
    return $t === false ? null : $t;
}

/**
 * Pure comparison of expected extend values against a live panel user row.
 * Returns true (match), false (real mismatch), or null (unreadable panel
 * values — callers must treat null like 'unknown', never as failure).
 */
function ledgerExtendValuesMatch($expLimit, $expExpire, array $user): ?bool
{
    if ($expLimit === null && $expExpire === null) {
        return null; // nothing stored to compare against
    }
    if ($expLimit !== null) {
        if (!isset($user['data_limit']) || !is_numeric($user['data_limit'])) {
            return null;
        }
        if ((int) $user['data_limit'] !== (int) $expLimit) {
            return false;
        }
    }
    if ($expExpire !== null) {
        $actual = ledgerNormalizeExpire($user['expire'] ?? null);
        if ($actual === null) {
            return null;
        }
        if (abs($actual - (int) $expExpire) > 300) {
            return false;
        }
    }
    return true;
}

function ledgerVerifyExtendDelivery(array $op): array
{
    $v = ledgerVerifyUserExists((string) $op['panel_name'], (string) $op['panel_username']);
    if ($v['result'] !== 'present') {
        return $v;
    }
    $match = ledgerExtendValuesMatch($op['expected_data_limit'], $op['expected_expire'], $v['user']);
    if ($match === true) {
        return ['result' => 'delivered', 'user' => $v['user']];
    }
    if ($match === null) {
        return ['result' => 'unknown', 'user' => $v['user']];
    }
    return ['result' => 'mismatch', 'user' => $v['user']];
}

/* ----------------------------------------------------------------------- */
/*  Reconciliation (STRICTLY READ-ONLY)                                     */
/* ----------------------------------------------------------------------- */

/**
 * Read-only reconciliation. Never writes anything. Categories are kept
 * separate on purpose: in-flight and unknown operations are NOT mismatches;
 * charges on charged/delivering/unknown ops are normal states, not errors.
 *
 * @return array{
 *   stale_started: array,   // ops stuck in 'started' (no money moved; safe)
 *   in_flight: array,       // charged/delivering: money moved, delivery open
 *   unknown: array,         // unverifiable delivery; no auto-refund happened
 *   refund_pending: array,  // refund decided but not completed
 *   refunded: array,        // summary only
 *   charge_journal_missing: array,  // definitive mismatch
 *   refund_journal_missing: array,  // definitive mismatch
 *   refund_without_charge: array,   // definitive mismatch (over-refund risk)
 *   legacy_journal: array,          // pre-ledger wholesale journal rows
 *   invoice_without_op: array       // active agent-bot invoices w/o op row
 * }
 */
function ledgerReconcile(int $staleMinutes = 10, int $limit = 200): array
{
    global $pdo;
    $out = [
        'stale_started' => [],
        'in_flight' => [],
        'unknown' => [],
        'refund_pending' => [],
        'refunded' => ['count' => 0, 'amount' => 0],
        'charge_journal_missing' => [],
        'refund_journal_missing' => [],
        'refund_without_charge' => [],
        'legacy_journal' => ['count' => 0, 'amount' => 0, 'rows' => []],
        'invoice_without_op' => [],
    ];
    if (!$pdo) {
        return $out;
    }
    $table = $pdo->query("SHOW TABLES LIKE 'reseller_operation'")->fetchColumn();
    if (!$table) {
        return $out;
    }
    $staleBefore = date('Y-m-d H:i:s', time() - $staleMinutes * 60);

    $stmt = $pdo->prepare("SELECT * FROM reseller_operation WHERE status = 'started' AND created_at < ? LIMIT $limit");
    $stmt->execute([$staleBefore]);
    $out['stale_started'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out['in_flight'] = $pdo->query("SELECT * FROM reseller_operation WHERE status IN ('charged','delivering') LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    $out['unknown'] = $pdo->query("SELECT * FROM reseller_operation WHERE status = 'unknown' LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    $out['refund_pending'] = $pdo->query("SELECT * FROM reseller_operation WHERE status = 'refund_pending' LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);

    $refunded = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM reseller_operation WHERE status = 'refunded'")->fetch(PDO::FETCH_ASSOC);
    $out['refunded'] = ['count' => (int) $refunded['c'], 'amount' => (int) $refunded['s']];

    // Definitive mismatches: op state vs journal.
    $out['charge_journal_missing'] = $pdo->query(
        "SELECT o.* FROM reseller_operation o
         LEFT JOIN agent_transaction j ON j.ref = CONCAT('wholesale_', o.type, '_', o.op_id)
         WHERE o.status IN ('charged','delivering','succeeded','refund_pending','refunded') AND j.id IS NULL
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out['refund_journal_missing'] = $pdo->query(
        "SELECT o.* FROM reseller_operation o
         LEFT JOIN agent_transaction j ON j.ref = CONCAT('refund_', o.type, '_', o.op_id)
         WHERE o.status = 'refunded' AND j.id IS NULL AND o.amount > 0
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out['refund_without_charge'] = $pdo->query(
        "SELECT o.* FROM reseller_operation o
         JOIN agent_transaction jr ON jr.ref = CONCAT('refund_', o.type, '_', o.op_id)
         LEFT JOIN agent_transaction jc ON jc.ref = CONCAT('wholesale_', o.type, '_', o.op_id)
         WHERE o.status = 'refunded' AND jc.id IS NULL
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Legacy wholesale journal rows (pre-ledger): ref suffix matches no op.
    $legacy = $pdo->query(
        "SELECT j.* FROM agent_transaction j
         LEFT JOIN reseller_operation o
           ON j.ref = CONCAT('wholesale_', o.type, '_', o.op_id)
          OR j.ref = CONCAT('refund_', o.type, '_', o.op_id)
         WHERE (j.type LIKE 'wholesale\\_%' OR j.type LIKE 'refund\\_%') AND o.id IS NULL
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);
    $sum = 0;
    foreach ($legacy as $row) {
        $sum += (int) $row['amount'];
    }
    $out['legacy_journal'] = ['count' => count($legacy), 'amount' => $sum, 'rows' => $legacy];

    // Active invoices created through agent bots without any operation row.
    // Informational only: invoices predating the ledger legitimately have none.
    $out['invoice_without_op'] = $pdo->query(
        "SELECT i.id_invoice, i.id_user, i.username, i.Service_location, i.price_product, i.time_sell, i.bottype
         FROM invoice i
         JOIN botsaz b ON b.bot_token = i.bottype
         LEFT JOIN reseller_operation o ON o.op_id = CONCAT('p_', i.id_invoice)
         WHERE i.Status = 'active' AND o.id IS NULL
         ORDER BY i.time_sell DESC
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Paid top-ups of agent wallets with no wallet_charge journal row.
    // Only orders NOT linked to any invoice/service_other qualify: a linked
    // order may be a purchase (money went to the service, not the wallet),
    // which legitimately has no wallet_charge — those are not listed.
    $out['paid_topups_without_journal'] = $pdo->query(
        "SELECT pr.id_order, pr.id_user, pr.price, pr.Payment_Method, pr.time
         FROM Payment_report pr
         JOIN user u ON u.id = pr.id_user AND u.agent IN ('n1','n2')
         LEFT JOIN invoice i ON i.id_payment = pr.id_order
         LEFT JOIN service_other s ON s.id_payment = pr.id_order
         WHERE pr.payment_Status = 'paid' AND i.id_invoice IS NULL AND s.id IS NULL
           AND NOT EXISTS (SELECT 1 FROM agent_transaction j WHERE j.ref = CONCAT('wallet_charge_', pr.id_order))
         ORDER BY pr.time DESC
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC);

    return $out;
}

/* ----------------------------------------------------------------------- */
/*  Duplicate-intent claims (F2)                                            */
/* ----------------------------------------------------------------------- */

/**
 * Claim an extend operation for an invoice. The first tap owns the
 * deterministic op_id `e_{invoice}`; a duplicate tap while that op is still
 * in flight is refused. Once the previous cycle reached a terminal state
 * (succeeded/refunded/failed) — or died in 'started' without ever moving
 * money (stale, older than $staleSeconds) — a NEW intentional extend gets a
 * suffixed op_id, so legitimate repeat extends are never rejected.
 *
 * @return array{claimed: bool, op_id: ?string, status: ?string, op: ?array}
 */
function ledgerClaimExtendOperation(string $invoiceId, string $agentId, ?int $botId, ?string $buyerId, ?string $panelName, ?string $panelUsername, int $amount, array $meta = [], int $staleSeconds = 600): array
{
    global $pdo;
    $base = 'e_' . $invoiceId;
    for ($i = 0; $i < 20; $i++) {
        $candidate = $i === 0 ? $base : $base . '_' . $i;
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO reseller_operation
                (op_id, type, agent_id, bot_id, buyer_id, invoice_id, panel_name, panel_username, amount, status, meta, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'started', ?, ?, ?)"
        );
        $stmt->execute([
            $candidate, LEDGER_OP_EXTEND, $agentId, $botId, $buyerId,
            $invoiceId, $panelName, $panelUsername, $amount,
            json_encode($meta, JSON_UNESCAPED_UNICODE), $now, $now,
        ]);
        if ($stmt->rowCount() === 1) {
            return ['claimed' => true, 'op_id' => $candidate, 'status' => 'started', 'op' => ledgerGetOperation($candidate)];
        }
        $existing = ledgerGetOperation($candidate);
        if ($existing === null) {
            continue; // row vanished between insert and read; try next suffix
        }
        $st = $existing['status'];
        $isStaleStarted = $st === 'started' && strtotime((string) $existing['created_at']) < time() - $staleSeconds;
        if (in_array($st, ['charged', 'delivering', 'refund_pending', 'unknown'], true) || ($st === 'started' && !$isStaleStarted)) {
            // A previous extend of THIS invoice is still in flight.
            return ['claimed' => false, 'op_id' => $candidate, 'status' => $st, 'op' => $existing];
        }
        // Terminal or abandoned op: the user intentionally extends again.
    }
    return ['claimed' => false, 'op_id' => null, 'status' => 'too_many_ops', 'op' => null];
}

/**
 * Conservative duplicate guard for purchases: true when the SAME buyer has
 * an unresolved (or just-created) operation for the SAME product, volume and
 * time on the SAME panel within the last $windowSeconds. Finished operations
 * (succeeded/refunded/failed) never block a new purchase.
 */
function ledgerHasInflightPurchase(string $agentId, string $buyerId, string $panelName, string $product, string $volume, string $time, int $windowSeconds = 120): bool
{
    global $pdo;
    $since = date('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt = $pdo->prepare(
        "SELECT 1 FROM reseller_operation
         WHERE type = 'purchase' AND agent_id = ? AND buyer_id = ? AND panel_name = ?
           AND status IN ('started','charged','delivering','unknown')
           AND created_at >= ?
           AND meta->>'$.product' = ?
           AND COALESCE(meta->>'$.volume','') = ?
           AND COALESCE(meta->>'$.time','') = ?
         LIMIT 1"
    );
    $stmt->execute([$agentId, $buyerId, $panelName, $since, $product, $volume, $time]);
    return (bool) $stmt->fetchColumn();
}

/* ----------------------------------------------------------------------- */
/*  Manual recovery (F5) — never re-creates, never re-charges               */
/* ----------------------------------------------------------------------- */

/**
 * Finalize a purchase op stuck in 'delivering' after the service was
 * actually created on the panel. Touches NOTHING financial: the wholesale
 * charge was already journaled at charge time and the retail (JSON) balance
 * is deliberately left untouched — it is flagged `retail_pending` in the op
 * meta for manual settlement by the agent.
 *
 * $verification: pass a precomputed ledgerVerifyUserExists() result (used by
 * tests); null performs a live panel check.
 *
 * @return array{ok: bool, reason: string}
 */
function ledgerFinalizeDeliveredPurchase(string $opId, ?array $verification = null): array
{
    global $pdo;
    $op = ledgerGetOperation($opId);
    if ($op === null) {
        return ['ok' => false, 'reason' => 'op not found'];
    }
    if ($op['type'] !== LEDGER_OP_PURCHASE) {
        return ['ok' => false, 'reason' => 'not a purchase op'];
    }
    if ($op['status'] !== 'delivering') {
        return ['ok' => false, 'reason' => 'op is not delivering (status=' . $op['status'] . ')'];
    }
    $v = $verification ?? ledgerVerifyUserExists((string) $op['panel_name'], (string) $op['panel_username']);
    if ($v['result'] !== 'present') {
        return ['ok' => false, 'reason' => 'panel verification: ' . $v['result']];
    }
    $meta = json_decode((string) ($op['meta'] ?? ''), true) ?: [];
    $meta['retail_pending'] = true;
    $meta['finalized_at'] = date('Y-m-d H:i:s');
    $meta['finalized_by'] = 'manual_finalize';
    if (!ledgerTransition($opId, ['delivering'], 'succeeded', ['meta' => $meta])) {
        return ['ok' => false, 'reason' => 'state changed concurrently'];
    }
    $claimedInvoice = 0;
    if (!empty($op['invoice_id'])) {
        $stmt = $pdo->prepare("UPDATE invoice SET Status = 'active' WHERE id_invoice = ? AND Status <> 'active'");
        $stmt->execute([$op['invoice_id']]);
        $claimedInvoice = $stmt->rowCount();
        clearSelectCache('invoice');
    }
    return ['ok' => true, 'reason' => 'finalized', 'invoice_activated' => $claimedInvoice === 1, 'retail_pending' => true];
}

/**
 * Finalize an extend op stuck in 'delivering' after verification proves the
 * expected values are live on the panel. Same money rules as purchases.
 *
 * @return array{ok: bool, reason: string}
 */
function ledgerFinalizeDeliveredExtend(string $opId, ?array $verification = null): array
{
    global $pdo;
    $op = ledgerGetOperation($opId);
    if ($op === null) {
        return ['ok' => false, 'reason' => 'op not found'];
    }
    if ($op['type'] !== LEDGER_OP_EXTEND) {
        return ['ok' => false, 'reason' => 'not an extend op'];
    }
    if ($op['status'] !== 'delivering') {
        return ['ok' => false, 'reason' => 'op is not delivering (status=' . $op['status'] . ')'];
    }
    $v = $verification ?? ledgerVerifyExtendDelivery($op);
    if ($v['result'] !== 'delivered') {
        return ['ok' => false, 'reason' => 'panel verification: ' . $v['result']];
    }
    $meta = json_decode((string) ($op['meta'] ?? ''), true) ?: [];
    $meta['retail_pending'] = true;
    $meta['finalized_at'] = date('Y-m-d H:i:s');
    $meta['finalized_by'] = 'manual_finalize';
    if (!ledgerTransition($opId, ['delivering'], 'succeeded', ['meta' => $meta])) {
        return ['ok' => false, 'reason' => 'state changed concurrently'];
    }
    if (!empty($op['invoice_id'])) {
        $stmt = $pdo->prepare("UPDATE invoice SET Status = 'active' WHERE id_invoice = ? AND Status <> 'active'");
        $stmt->execute([$op['invoice_id']]);
        clearSelectCache('invoice');
    }
    // Best-effort history record (idempotent via the op_id marker).
    $check = $pdo->prepare("SELECT 1 FROM service_other WHERE type = 'extend_user' AND value LIKE ? LIMIT 1");
    $check->execute(['%"op_id":"' . $opId . '"%']);
    if (!$check->fetchColumn()) {
        $value = json_encode([
            'volumebuy' => $meta['volume'] ?? null,
            'Service_time' => $meta['time'] ?? null,
            'code_product' => $meta['code_product'] ?? null,
            'id_order' => $op['invoice_id'],
            'op_id' => $opId,
            'recovered' => true,
        ], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO service_other (id_user, username, value, type, time, price, output) VALUES (?, ?, ?, 'extend_user', ?, ?, ?)");
        $stmt->execute([$op['buyer_id'], $op['panel_username'], $value, date('Y/m/d H:i:s'), (int) ($meta['retail_price'] ?? 0), 'recovered by finalize']);
        clearSelectCache('service_other');
    }
    return ['ok' => true, 'reason' => 'finalized', 'retail_pending' => true];
}

/**
 * Repair a paid agent top-up that was never credited (crash between the
 * payment claim and the credit). Idempotent: the journal ref is the same
 * `wallet_charge_{order_id}` the normal path uses, and the credit+journal
 * are one transaction — replaying a repaired order is a no-op.
 *
 * @return array{ok: bool, reason: string}
 */
function ledgerRepairAgentTopup(string $orderId): array
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE id_order = ? AND payment_Status = 'paid' LIMIT 1");
    $stmt->execute([$orderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($payment === false) {
        return ['ok' => false, 'reason' => 'paid order not found'];
    }
    // Pure top-ups only: an order linked to an invoice/service is a purchase.
    $linked = $pdo->prepare("SELECT (SELECT COUNT(*) FROM invoice WHERE id_payment = ?) + (SELECT COUNT(*) FROM service_other WHERE id_payment = ?)");
    $linked->execute([$orderId, $orderId]);
    if ((int) $linked->fetchColumn() > 0) {
        return ['ok' => false, 'reason' => 'order is linked to a purchase, not a top-up'];
    }
    $userStmt = $pdo->prepare("SELECT * FROM user WHERE id = ? LIMIT 1");
    $userStmt->execute([$payment['id_user']]);
    $target = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!resellerUserIsAgent($target)) {
        return ['ok' => false, 'reason' => 'order owner is not an agent'];
    }
    $ref = 'wallet_charge_' . $orderId;
    if (resellerJournalExists($ref)) {
        return ['ok' => true, 'reason' => 'already credited (journal exists)'];
    }
    $res = resellerCreditWallet((string) $target['id'], (int) $payment['price'], 'wallet_charge', $ref, [
        'order' => $orderId,
        'method' => $payment['Payment_Method'] ?? '',
        'repair' => true,
    ]);
    return $res === null
        ? ['ok' => false, 'reason' => 'credit failed']
        : ['ok' => true, 'reason' => 'credited', 'balance_after' => $res];
}
