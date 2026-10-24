<?php
/** Phase E ledger tests — operation lifecycle, idempotency, concurrency,
 *  refund safety, reconciliation categories, Marzban response classification.
 *  Uses real DB (server) but cleans up every row it creates.
 *  Panel-dependent paths use a deliberately unreachable panel (unknown state),
 *  never a real panel and never the real journal of real agents. */

section('ledger');

require_once __DIR__ . '/../function.php';

global $pdo;
$pdo->exec("SET SESSION sql_mode=''");

$LA = 'ledA_' . bin2hex(random_bytes(3)); // agent A
$LB = 'ledB_' . bin2hex(random_bytes(3)); // agent B (cross-tenant)
$ledgerCleanup = function () use ($pdo, $LA, $LB) {
    foreach ([$LA, $LB] as $id) {
        $pdo->prepare("DELETE FROM reseller_operation WHERE agent_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$id]);
    }
    $pdo->exec("DELETE FROM reseller_operation WHERE op_id LIKE 'lt_%'");
    $pdo->exec("DELETE FROM marzban_panel WHERE name_panel = 'lt_panel_dead'");
    clearSelectCache('user');
    clearSelectCache('marzban_panel');
};
$ledgerCleanup();

$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 1000, 'n1')")->execute([$LA]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 500, 'n1')")->execute([$LB]);
clearSelectCache('user');

$balOf = function ($id) use ($pdo) {
    return (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($id))->fetchColumn();
};
$journalCount = function ($ref) use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM agent_transaction WHERE ref = ?");
    $s->execute([$ref]);
    return (int) $s->fetchColumn();
};

/* ---------- 1. create + atomic charge ---------- */
$op1 = 'lt_op1_' . bin2hex(random_bytes(3));
$created = ledgerCreateOperation($op1, LEDGER_OP_PURCHASE, $LA, 1, 'buyer1', 'inv1', 'panelX', 'userx', 400);
ok(is_array($created) && $created['status'] === 'started', 'op created in started state');
$bal = ledgerChargeOperation($op1);
ok($bal === 600, 'charge succeeds (1000 - 400 = 600)');
ok(ledgerGetOperation($op1)['status'] === 'charged', 'op status charged after charge');
ok($journalCount('wholesale_purchase_' . $op1) === 1, 'charge journal written with op-derived ref');

/* ---------- 2. idempotent replay / concurrent second charge ---------- */
$bal2 = ledgerChargeOperation($op1);
ok($bal2 === 600 && $balOf($LA) === 600, 'repeated charge on charged op is a no-op (no double debit)');
ok($journalCount('wholesale_purchase_' . $op1) === 1, 'still exactly one charge journal row');

/* ---------- 3. transition guards ---------- */
ok(ledgerTransition($op1, ['charged'], 'succeeded') === true, 'charged -> succeeded allowed');
ok(ledgerChargeOperation($op1) === null, 'charge on succeeded op refused');
ok(ledgerRefundOperation($op1, 'too late') === false, 'refund on succeeded op refused');
$op1b = 'lt_op1b_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op1b, LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 100);
ok(ledgerTransition($op1b, ['charged'], 'succeeded') === false, 'started -> succeeded refused (must pass through charged)');
ok(ledgerRefundOperation($op1b, 'never charged') === false, 'refund on never-charged op refused');
ok($balOf($LA) === 600, 'no balance change from refused transitions');

/* ---------- 4. definitive failure -> idempotent refund ---------- */
$op2 = 'lt_op2_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op2, LEDGER_OP_PURCHASE, $LA, 1, 'buyer2', 'inv2', 'panelX', 'usery', 300);
ledgerChargeOperation($op2);
ok($balOf($LA) === 300, 'second charge applied (600 - 300)');
ok(ledgerMarkDelivering($op2) === true, 'charged -> delivering');
ok(ledgerRefundOperation($op2, 'delivery failed: user absent on panel') === true, 'refund after definitive failure');
ok($balOf($LA) === 600, 'balance restored after refund');
ok(ledgerGetOperation($op2)['status'] === 'refunded', 'op refunded');
ok($journalCount('refund_purchase_' . $op2) === 1, 'refund journal written');
/* double refund must be a no-op */
ok(ledgerRefundOperation($op2, 'duplicate attempt') === true, 'second refund call returns true (idempotent)');
ok($balOf($LA) === 600 && $journalCount('refund_purchase_' . $op2) === 1, 'double refund did not move money again');

/* ---------- 5. charge refusal (insufficient funds) ---------- */
$op3 = 'lt_op3_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op3, LEDGER_OP_PURCHASE, $LB, 2, 'buyer3', 'inv3', 'panelX', 'userz', 900);
ok(ledgerChargeOperation($op3) === null, 'charge beyond balance refused');
ok(ledgerGetOperation($op3)['status'] === 'started', 'refused charge leaves op in started');
ok(ledgerMarkFailed($op3, 'wholesale wallet charge refused') === true, 'started -> failed');
ok(ledgerChargeOperation($op3) === null, 'charge on failed op refused');
ok($balOf($LB) === 500, 'no money moved for failed op');
ok($journalCount('wholesale_purchase_' . $op3) === 0, 'no journal for failed op');

/* ---------- 6. unknown state: NO auto-refund ---------- */
$op4 = 'lt_op4_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op4, LEDGER_OP_EXTEND, $LA, 1, 'buyer4', 'inv4', 'panelX', 'userw', 200);
ledgerChargeOperation($op4);
ok(ledgerMarkUnknown($op4, 'timeout') === true, 'charged -> unknown');
ok(ledgerRefundOperation($op4, 'try auto refund') === false, 'refund on unknown op REFUSED (no auto-refund without verification)');
ok($balOf($LA) === 400, 'unknown op keeps the charge in place');
ok(ledgerMarkSucceeded($op4) === false, 'unknown -> succeeded refused via safe wrapper (terminal for automation)');

/* ---------- 7. zero-amount op ---------- */
$op5 = 'lt_op5_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op5, LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 0);
ok(ledgerChargeOperation($op5) === 0, 'zero-amount charge succeeds without journal');
ok(ledgerGetOperation($op5)['status'] === 'charged', 'zero-amount op charged');
ok(ledgerRefundOperation($op5, 'free op failed') === true, 'zero-amount refund succeeds without journal');
ok(ledgerGetOperation($op5)['status'] === 'refunded', 'zero-amount op refunded');

/* ---------- 8. cross-tenant: charge always debits the op owner ---------- */
$op6 = 'lt_op6_' . bin2hex(random_bytes(3));
ledgerCreateOperation($op6, LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 50);
$before = [$balOf($LA), $balOf($LB)];
ledgerChargeOperation($op6);
ok($balOf($LA) === $before[0] - 50 && $balOf($LB) === $before[1], 'charge debits op owner only (tenant isolation)');

/* ---------- 9. admin adjustment rules ---------- */
$adjRef1 = 'wallet_charge_lt_' . bin2hex(random_bytes(3));
ok(resellerWalletAdjust($LA, 100, 'جبران خطای سرویس', 'admin_1', $adjRef1) === $balOf($LA), 'positive adjustment succeeds');
ok($journalCount($adjRef1) === 1, 'wallet_charge journaled');
$jr = $pdo->query("SELECT * FROM agent_transaction WHERE ref = '$adjRef1'")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode($jr['meta'], true);
ok($jr['type'] === 'wallet_charge' && $meta['reason'] !== '' && $meta['admin'] === 'admin_1', 'journal carries type + mandatory reason + admin identity');
ok(resellerWalletAdjust($LA, 100, '', 'admin_1', 'x') === null, 'adjustment without reason refused');
ok(resellerWalletAdjust($LA, 100, 'r', '', 'x') === null, 'adjustment without admin identity refused');
$adjRef2 = 'adjustment_lt_' . bin2hex(random_bytes(3));
$beforeAdj = $balOf($LA);
ok(resellerWalletAdjust($LA, -70, 'اصلاح دستی', 'admin_2', $adjRef2) === $beforeAdj - 70, 'negative adjustment succeeds');
$jr2 = $pdo->query("SELECT * FROM agent_transaction WHERE ref = '$adjRef2'")->fetch(PDO::FETCH_ASSOC);
ok($jr2['type'] === 'manual_adjustment', 'negative adjustment journaled as manual_adjustment');

section('ledger — reconciliation');

/* Seed a spread of states and check categorization. */
$rc = 'lt_rc_' . bin2hex(random_bytes(2));
// in-flight: charged with journal (normal, NOT a mismatch)
ledgerCreateOperation($rc . '_inflight', LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 10);
ledgerChargeOperation($rc . '_inflight');
// unknown
ledgerCreateOperation($rc . '_unknown', LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 10);
ledgerChargeOperation($rc . '_unknown');
ledgerMarkUnknown($rc . '_unknown', 't');
// charge journal missing (definitive mismatch)
ledgerCreateOperation($rc . '_nojou', LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 10);
ledgerChargeOperation($rc . '_nojou');
$pdo->exec("DELETE FROM agent_transaction WHERE ref = 'wholesale_purchase_{$rc}_nojou'");
// refunded without refund journal (definitive mismatch)
ledgerCreateOperation($rc . '_norefj', LEDGER_OP_PURCHASE, $LA, 1, 'b', 'i', 'p', 'u', 10);
ledgerChargeOperation($rc . '_norefj');
ledgerRefundOperation($rc . '_norefj', 'r');
$pdo->exec("DELETE FROM agent_transaction WHERE ref = 'refund_purchase_{$rc}_norefj'");
// legacy journal row (no op at all)
resellerJournal($LA, 'wholesale_purchase', -10, 'wholesale_purchase_legacy_' . $rc, []);

$rec = ledgerReconcile();
$ids = fn($rows) => array_column($rows, 'op_id');
ok(in_array($rc . '_inflight', $ids($rec['in_flight']), true), 'in-flight op listed as in-flight');
ok(!in_array($rc . '_inflight', $ids($rec['charge_journal_missing']), true), 'in-flight op NOT flagged as mismatch');
ok(in_array($rc . '_unknown', $ids($rec['unknown']), true), 'unknown op listed as unknown');
ok(!in_array($rc . '_unknown', $ids($rec['charge_journal_missing']), true), 'unknown op NOT flagged as mismatch (has journal)');
ok(in_array($rc . '_nojou', $ids($rec['charge_journal_missing']), true), 'charged op without journal = definitive mismatch');
ok(in_array($rc . '_norefj', $ids($rec['refund_journal_missing']), true), 'refunded op without refund journal = definitive mismatch');
$legacyRefs = array_column($rec['legacy_journal']['rows'], 'ref');
ok(in_array('wholesale_purchase_legacy_' . $rc, $legacyRefs, true), 'legacy journal row categorized as legacy, not mismatch');

section('ledger — Marzban classification (mocked responses)');

ok(ledgerClassifyMarzbanGetuser(['status' => 404, 'body' => '{"detail":"User not found"}'])['result'] === 'absent', 'HTTP 404 = definitive absence');
ok(ledgerClassifyMarzbanGetuser(['status' => 200, 'body' => '{"username":"u1","data_limit":100,"expire":2000}'])['result'] === 'present', 'HTTP 200 + valid body = present');
ok(ledgerClassifyMarzbanGetuser(['status' => null, 'body' => null, 'error' => 'Connection timed out'])['result'] === 'unknown', 'curl timeout = unknown (NOT absence)');
ok(ledgerClassifyMarzbanGetuser(['status' => 500, 'body' => '{}'])['result'] === 'unknown', 'HTTP 500 = unknown');
ok(ledgerClassifyMarzbanGetuser(['status' => 200, 'body' => 'not json'])['result'] === 'unknown', 'invalid body = unknown');
ok(ledgerClassifyMarzbanGetuser(['status' => 200, 'body' => '{"detail":"weird"}'])['result'] === 'unknown', '200 without username = unknown');
ok(ledgerClassifyMarzbanGetuser(null)['result'] === 'unknown', 'null response = unknown');
ok(ledgerClassifyMarzbanGetuser(['error' => 'token failed'])['result'] === 'unknown', 'token error = unknown');

/* ---------- panel unreachable -> verification stays unknown ---------- */
require_once __DIR__ . '/../panels.php'; // provides getuser() for marzban panels
$pdo->exec("INSERT INTO marzban_panel (name_panel, url_panel, username_panel, password_panel, type, code_panel) VALUES ('lt_panel_dead', 'https://127.0.0.1:9', 'x', 'x', 'marzban', 'ltdead')");
clearSelectCache('marzban_panel');
$v = ledgerVerifyUserExists('lt_panel_dead', 'somebody');
ok($v['result'] === 'unknown', 'unreachable panel => unknown (no absence claim, no refund basis)');
$v2 = ledgerVerifyUserExists('lt_panel_dead', 'somebody');
$GLOBALS['pdo']->exec("UPDATE marzban_panel SET type = 'x-ui' WHERE name_panel = 'lt_panel_dead'");
clearSelectCache('marzban_panel');
$v3 = ledgerVerifyUserExists('lt_panel_dead', 'somebody');
ok($v3['result'] === 'unsupported', 'non-marzban panel => unsupported (treated as unknown by callers)');

$ledgerCleanup();
ok(true, 'ledger test rows cleaned up');
