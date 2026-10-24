<?php

/**
 * Manual finalization of a ledger operation stuck in 'delivering' (F5).
 *
 * Use this ONLY after you have manually confirmed on the panel that the
 * service was actually created / extended. The tool re-verifies against the
 * panel itself and refuses to run when verification does not prove delivery.
 *
 * Safety guarantees:
 *   - Never re-creates a service, never re-charges a wallet.
 *   - Only an op in status 'delivering' can be finalized.
 *   - The wholesale charge was already journaled at charge time; nothing
 *     financial moves here. The retail (JSON) balance is deliberately NOT
 *     touched — the op meta is flagged `retail_pending: true` and settling
 *     the buyer's retail balance is a manual action for the agent.
 *
 * Usage (CLI only):
 *   php cronbot/finalize_operation.php <op_id>
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';

$opId = $argv[1] ?? '';
if ($opId === '') {
    echo "Usage: php cronbot/finalize_operation.php <op_id>\n";
    exit(1);
}

$op = ledgerGetOperation($opId);
if ($op === null) {
    echo "ERROR: operation '$opId' not found.\n";
    exit(1);
}

echo "Operation: {$op['op_id']} | type={$op['type']} | status={$op['status']} | agent={$op['agent_id']} | invoice={$op['invoice_id']} | user={$op['panel_username']}@{$op['panel_name']} | amount=" . number_format((int) $op['amount']) . "\n";

if ($op['status'] !== 'delivering') {
    echo "REFUSED: only an op in status 'delivering' can be finalized (current: {$op['status']}).\n";
    echo "If status is 'unknown', first check the panel manually; if the service exists and matches, move it to 'delivering' via SQL, then re-run this tool.\n";
    exit(1);
}

$result = $op['type'] === LEDGER_OP_PURCHASE
    ? ledgerFinalizeDeliveredPurchase($opId)
    : ledgerFinalizeDeliveredExtend($opId);

if (!$result['ok']) {
    echo "FAILED: {$result['reason']}\n";
    echo "Nothing was changed. Fix the cause (panel unreachable? service missing?) and re-run, or handle the op manually.\n";
    exit(1);
}

echo "OK: {$result['reason']}\n";
if (isset($result['invoice_activated'])) {
    echo "Invoice activated: " . ($result['invoice_activated'] ? 'yes' : 'no (already active)') . "\n";
}
echo "Retail balance: NOT touched — meta flag retail_pending=true was set; settle the buyer's retail (JSON) balance manually.\n";
exit(0);
