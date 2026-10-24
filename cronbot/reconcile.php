<?php

/**
 * Read-only reconciliation report for the Phase E ledger.
 *
 * STRICTLY READ-ONLY: this script runs SELECT queries only and prints a
 * report. It never modifies any data. Run manually (CLI or browser):
 *   php cronbot/reconcile.php
 */

chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';

header('Content-Type: text/plain; charset=utf-8');

$r = ledgerReconcile();

$line = str_repeat('-', 60);
echo "MODASR ledger reconciliation — " . date('Y-m-d H:i:s') . "\n$line\n";
echo "READ-ONLY report. No data was modified.\n$line\n";

$fmtOp = function (array $op): string {
    return sprintf(
        "op=%s | type=%s | agent=%s | invoice=%s | user=%s@%s | amount=%s | since=%s | err=%s",
        $op['op_id'],
        $op['type'],
        $op['agent_id'],
        $op['invoice_id'] ?? '-',
        $op['panel_username'] ?? '-',
        $op['panel_name'] ?? '-',
        number_format((int) $op['amount']),
        $op['created_at'],
        mb_substr((string) ($op['last_error'] ?? ''), 0, 80)
    );
};

echo "\n[1] IN-FLIGHT (charged/delivering — money moved, delivery open; NOT a mismatch)\n";
echo count($r['in_flight']) ? '' : "  none\n";
foreach ($r['in_flight'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n[2] UNKNOWN (unverifiable delivery — no auto-refund was issued; investigate manually)\n";
echo count($r['unknown']) ? '' : "  none\n";
foreach ($r['unknown'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n[3] REFUND PENDING (refund decided but not completed)\n";
echo count($r['refund_pending']) ? '' : "  none\n";
foreach ($r['refund_pending'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n[4] REFUNDED (summary)\n";
echo "  count={$r['refunded']['count']} amount=" . number_format($r['refunded']['amount']) . "\n";

echo "\n[5] STALE STARTED (no money moved; abandoned before charge — safe)\n";
echo count($r['stale_started']) ? '' : "  none\n";
foreach ($r['stale_started'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n$line\nDEFINITIVE MISMATCHES (require manual investigation)\n$line\n";

echo "\n[6] Charged/delivered operations WITHOUT a charge journal row\n";
echo count($r['charge_journal_missing']) ? '' : "  none\n";
foreach ($r['charge_journal_missing'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n[7] Refunded operations WITHOUT a refund journal row\n";
echo count($r['refund_journal_missing']) ? '' : "  none\n";
foreach ($r['refund_journal_missing'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n[8] Refund journal rows WITHOUT a matching charge (over-refund risk)\n";
echo count($r['refund_without_charge']) ? '' : "  none\n";
foreach ($r['refund_without_charge'] as $op) echo '  ' . $fmtOp($op) . "\n";

echo "\n$line\nINFORMATIONAL\n$line\n";

echo "\n[9] Legacy wholesale journal rows (pre-ledger; not mismatches)\n";
echo "  count={$r['legacy_journal']['count']} net-amount=" . number_format($r['legacy_journal']['amount']) . "\n";
foreach ($r['legacy_journal']['rows'] as $row) {
    echo sprintf("  ref=%s | agent=%s | type=%s | amount=%s | at=%s\n", $row['ref'], $row['agent_id'], $row['type'], number_format((int) $row['amount']), $row['created_at']);
}

echo "\n[10] Active agent-bot invoices without an operation row (legacy or untracked — review, NOT definitive)\n";
echo count($r['invoice_without_op']) ? '' : "  none\n";
foreach ($r['invoice_without_op'] as $row) {
    echo sprintf("  invoice=%s | user=%s | service=%s@%s | price=%s | time=%s\n", $row['id_invoice'], $row['id_user'], $row['username'], $row['Service_location'], number_format((int) $row['price_product']), $row['time_sell']);
}

echo "\n[11] Paid agent top-ups WITHOUT a wallet_charge journal (paid but never credited — repair via ledgerRepairAgentTopup)\n";
echo count($r['paid_topups_without_journal']) ? '' : "  none\n";
foreach ($r['paid_topups_without_journal'] as $row) {
    echo sprintf("  order=%s | agent=%s | price=%s | method=%s | time=%s\n", $row['id_order'], $row['id_user'], number_format((int) $row['price']), $row['Payment_Method'], $row['time']);
}

echo "\n$line\nEnd of report.\n";
