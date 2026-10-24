<?php
/** F1–F5 fix verification tests.
 *  F1: atomic wallet credit + journal, idempotent replay, top-up repair.
 *  F2: deterministic extend claims + purchase in-flight guard.
 *  F3: subscription link normalization in recovery.
 *  F4: expire normalization + extend value matching.
 *  F5: manual finalize of delivered ops (never re-charges, never re-creates).
 *  Uses the real DB but cleans up every row it creates; never touches real
 *  agents, panels or journals. */

section('F1 — atomic wallet charge + repair');

require_once __DIR__ . '/../function.php';

global $pdo;
$pdo->exec("SET SESSION sql_mode=''");

$FA = 'fixA_' . bin2hex(random_bytes(3)); // agent
$FN = 'fixN_' . bin2hex(random_bytes(3)); // non-agent
$fixCleanup = function () use ($pdo, $FA, $FN) {
    foreach ([$FA, $FN] as $id) {
        $pdo->prepare("DELETE FROM reseller_operation WHERE agent_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM Payment_report WHERE id_user = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM invoice WHERE id_user = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM service_other WHERE id_user = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$id]);
    }
    $pdo->exec("DELETE FROM reseller_operation WHERE op_id LIKE 'fx_%'");
    $pdo->exec("DELETE FROM reseller_operation WHERE op_id LIKE 'e\_fx%'");
    $pdo->exec("DELETE FROM invoice WHERE id_invoice LIKE 'fxinv_%'");
    $pdo->exec("DELETE FROM service_other WHERE value LIKE '%fx_%'");
    clearSelectCache('user');
    clearSelectCache('Payment_report');
    clearSelectCache('invoice');
    clearSelectCache('service_other');
};
$fixCleanup();

$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 1000, 'n1')")->execute([$FA]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 1000, 'f')")->execute([$FN]);
clearSelectCache('user');

$fBal = function ($id) use ($pdo) {
    return (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($id))->fetchColumn();
};
$fJrn = function ($ref) use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM agent_transaction WHERE ref = ?");
    $s->execute([$ref]);
    return (int) $s->fetchColumn();
};
$mkOrder = function ($idUser, $price, $status = 'paid') use ($pdo) {
    $oid = 'fxord_' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO Payment_report (id_user, id_order, time, price, Payment_Method, payment_Status) VALUES (?, ?, ?, ?, 'test', ?)")
        ->execute([$idUser, $oid, date('Y-m-d H:i:s'), (string) $price, $status]);
    clearSelectCache('Payment_report');
    return $oid;
};

// Atomic credit: one transaction, journaled.
$ref1 = 'wallet_charge_fx_' . bin2hex(random_bytes(3));
$res = resellerCreditWallet($FA, 250, 'wallet_charge', $ref1, ['order' => 'x']);
ok($res === 1250 && $fBal($FA) === 1250, 'atomic credit moves balance exactly once');
ok($fJrn($ref1) === 1, 'wallet_charge journal written inside the same transaction');
// Replay: same ref => no-op duplicate, distinguishable from failure.
$resReplay = resellerCreditWallet($FA, 250, 'wallet_charge', $ref1, ['order' => 'x']);
ok($resReplay === null, 'replay with same ref returns null');
ok(resellerJournalExists($ref1) === true, 'journal ref proves replay is a duplicate, not a failure');
ok($fBal($FA) === 1250 && $fJrn($ref1) === 1, 'replay moved no money and wrote no journal');

// Repair: paid top-up without credit.
$ord1 = $mkOrder($FA, 400);
$rep = ledgerRepairAgentTopup($ord1);
ok($rep['ok'] === true && $rep['reason'] === 'credited', 'repair credits a paid top-up without journal');
ok($fBal($FA) === 1650 && $fJrn('wallet_charge_' . $ord1) === 1, 'repair is journaled with the standard ref');
$rep2 = ledgerRepairAgentTopup($ord1);
ok($rep2['ok'] === true && $rep2['reason'] === 'already credited (journal exists)', 'repair replay is an idempotent no-op');
ok($fBal($FA) === 1650, 'repair replay moved no money');
// Repair refusals.
$ordN = $mkOrder($FN, 100);
ok(ledgerRepairAgentTopup($ordN)['ok'] === false, 'repair refuses non-agent owner');
ok(ledgerRepairAgentTopup('fxord_missing_' . bin2hex(random_bytes(3)))['ok'] === false, 'repair refuses unknown order');
$ordL = $mkOrder($FA, 100);
$pdo->prepare("INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, bottype, note, notifctions, id_payment) VALUES (?, ?, 'u', '2026-01-01', 'p', 'prod', '100', '1', '30', 'active', 't', '', '{}', ?)")
    ->execute([$FA, 'fxinv_link_' . bin2hex(random_bytes(3)), $ordL]);
clearSelectCache('invoice');
ok(ledgerRepairAgentTopup($ordL)['ok'] === false, 'repair refuses an order linked to a purchase (not a top-up)');
$ordU = $mkOrder($FA, 100, 'Unpaid');
ok(ledgerRepairAgentTopup($ordU)['ok'] === false, 'repair refuses an unpaid order');

section('F2 — duplicate-intent claims');

// Extend claim: first tap owns the deterministic op_id.
$invF2 = 'fxinv_' . bin2hex(random_bytes(3));
$c1 = ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300, ['volume' => '10', 'time' => '30']);
ok($c1['claimed'] === true && $c1['op_id'] === 'e_' . $invF2, 'first extend claim owns deterministic op_id e_{invoice}');
$c2 = ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300);
ok($c2['claimed'] === false && $c2['status'] === 'started' && $c2['op_id'] === 'e_' . $invF2, 'duplicate tap on fresh started op refused (no second op)');
ok(ledgerGetOperation('e_' . $invF2) !== null && ledgerGetOperation('e_' . $invF2 . '_1') === null, 'no shadow op row was created by the refused tap');
ledgerChargeOperation('e_' . $invF2);
$c3 = ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300);
ok($c3['claimed'] === false && $c3['status'] === 'charged', 'duplicate tap on charged (in-flight) op refused');
ledgerMarkDelivering('e_' . $invF2);
ok(ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300)['claimed'] === false, 'duplicate tap on delivering op refused');
ledgerRefundOperation('e_' . $invF2, 'test');
$c4 = ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300);
ok($c4['claimed'] === true && $c4['op_id'] === 'e_' . $invF2 . '_1', 'terminal op => intentional re-extend gets a new suffixed op (never blocked)');
ledgerMarkFailed('e_' . $invF2 . '_1', 'test');
$c5 = ledgerClaimExtendOperation($invF2, $FA, 1, 'buyer1', 'panelX', 'userx', 300);
ok($c5['claimed'] === true && $c5['op_id'] === 'e_' . $invF2 . '_2', 'repeated intentional extends keep getting new op ids');

// Stale 'started' op (crashed before charging, money never moved) must not block forever.
$invStale = 'fxinv_stale_' . bin2hex(random_bytes(3));
ledgerCreateOperation('e_' . $invStale, LEDGER_OP_EXTEND, $FA, 1, 'b', $invStale, 'p', 'u', 10);
$pdo->prepare("UPDATE reseller_operation SET created_at = ? WHERE op_id = ?")->execute([date('Y-m-d H:i:s', time() - 3600), 'e_' . $invStale]);
$cs = ledgerClaimExtendOperation($invStale, $FA, 1, 'b', 'p', 'u', 10);
ok($cs['claimed'] === true && $cs['op_id'] === 'e_' . $invStale . '_1', 'stale started op (never charged) is superseded, extend proceeds');

// Purchase in-flight guard.
$invP = 'fxinv_p_' . bin2hex(random_bytes(3));
ledgerCreateOperation('fx_p_' . $invP, LEDGER_OP_PURCHASE, $FA, 1, 'buyerP', $invP, 'panelX', 'userp', 100, ['product' => 'prodA', 'volume' => '10', 'time' => '30']);
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodA', '10', '30') === true, 'identical in-flight purchase detected');
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodA', '20', '30') === false, 'different volume is NOT a duplicate');
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodB', '10', '30') === false, 'different product is NOT a duplicate');
ok(ledgerHasInflightPurchase($FA, 'buyerQ', 'panelX', 'prodA', '10', '30') === false, 'different buyer is NOT a duplicate');
ledgerChargeOperation('fx_p_' . $invP);
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodA', '10', '30') === true, 'charged op still counts as in-flight');
ledgerRefundOperation('fx_p_' . $invP, 'test');
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodA', '10', '30') === false, 'refunded purchase never blocks a new one');
$invP2 = 'fxinv_p2_' . bin2hex(random_bytes(3));
ledgerCreateOperation('fx_p_' . $invP2, LEDGER_OP_PURCHASE, $FA, 1, 'buyerP', $invP2, 'panelX', 'userp2', 100, ['product' => 'prodA', 'volume' => '10', 'time' => '30']);
ledgerMarkFailed('fx_p_' . $invP2, 'test');
ok(ledgerHasInflightPurchase($FA, 'buyerP', 'panelX', 'prodA', '10', '30') === false, 'failed purchase never blocks a new one');

section('F3 — subscription link normalization');

ok(absoluteSubscriptionUrl('https://sub.example.com/abc', 'https://panel.example.com') === 'https://sub.example.com/abc', 'absolute https link passes through unchanged');
ok(absoluteSubscriptionUrl('http://sub.example.com:2096/abc', 'https://panel.example.com') === 'http://sub.example.com:2096/abc', 'absolute http link passes through unchanged');
ok(absoluteSubscriptionUrl('/sub/abc', 'https://panel.example.com:8080/') === 'https://panel.example.com:8080/sub/abc', 'relative link is anchored to the panel URL');
ok(absoluteSubscriptionUrl('sub/abc', 'https://panel.example.com') === 'https://panel.example.com/sub/abc', 'bare path is anchored to the panel URL');
ok(absoluteSubscriptionUrl('cdn.example.com:8443/sub/x', 'https://panel.example.com') === 'cdn.example.com:8443/sub/x', 'host-like link passes through unchanged');
ok(absoluteSubscriptionUrl('', 'https://panel.example.com') === '', 'empty link stays empty');

section('F4 — expire normalization + extend matching');

$ts = 1762000000; // 2025-11-01T12:26:40Z
$dt = date('Y-m-d H:i:s', $ts);
ok(ledgerNormalizeExpire($ts) === $ts, 'int timestamp passes through');
ok(ledgerNormalizeExpire((string) $ts) === $ts, 'numeric string passes through');
ok(ledgerNormalizeExpire($dt) === $ts, 'datetime string normalized to timestamp');
ok(ledgerNormalizeExpire('2025-11-01T12:26:40+00:00') === $ts, 'ISO 8601 string normalized to timestamp');
ok(ledgerNormalizeExpire('not-a-date') === null, 'unreadable expire => null (unknown, not mismatch)');
ok(ledgerNormalizeExpire(null) === null && ledgerNormalizeExpire('') === null, 'null/empty expire => null');

ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => 1000, 'expire' => $ts]) === true, 'timestamp match (positive)');
ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => '1000', 'expire' => $dt]) === true, 'datetime-string match (positive — the Marzban v1 case)');
ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => 2000, 'expire' => $ts]) === false, 'wrong data_limit => mismatch (negative)');
ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => 1000, 'expire' => $ts + 600]) === false, 'expire drift beyond tolerance => mismatch (negative)');
ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => 1000, 'expire' => $ts + 100]) === true, 'expire within ±300s tolerance => match');
ok(ledgerExtendValuesMatch(1000, $ts, ['data_limit' => 1000, 'expire' => 'garbage']) === null, 'unreadable expire => unknown, never a false mismatch');
ok(ledgerExtendValuesMatch(null, null, ['data_limit' => 1000, 'expire' => $ts]) === null, 'no stored expectations => unknown (cannot verify)');
ok(ledgerExtendValuesMatch(1000, null, ['expire' => $ts]) === null, 'expected limit but panel lacks data_limit => unknown');

section('F5 — finalize delivered operations');

// Purchase finalize guards.
$finInv = 'fxinv_fin_' . bin2hex(random_bytes(3));
ledgerCreateOperation($finInv, LEDGER_OP_PURCHASE, $FA, 1, 'buyerF', $finInv, 'panelX', 'userf', 100);
ok(ledgerFinalizeDeliveredPurchase($finInv, ['result' => 'present', 'user' => ['username' => 'userf']])['ok'] === false, 'finalize refused on non-delivering op');
ledgerChargeOperation($finInv);
ledgerMarkDelivering($finInv);
$balBeforeFin = $fBal($FA);
ok(ledgerFinalizeDeliveredPurchase($finInv, ['result' => 'unknown'])['ok'] === false, 'finalize refused when panel cannot prove delivery');
ok(ledgerGetOperation($finInv)['status'] === 'delivering', 'refused finalize leaves op in delivering');
ok($fBal($FA) === $balBeforeFin, 'refused finalize moved no money');

// Invoice activation + retail_pending flag on success.
$pdo->prepare("INSERT INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, bottype, note, notifctions) VALUES ('buyerF', ?, 'userf', '2026-01-01', 'panelX', 'prod', '100', '1', '30', 'unpaid', 't', '', '{}')")->execute([$finInv]);
clearSelectCache('invoice');
$fin = ledgerFinalizeDeliveredPurchase($finInv, ['result' => 'present', 'user' => ['username' => 'userf']]);
ok($fin['ok'] === true && $fin['invoice_activated'] === true, 'finalize succeeds with verified delivery and activates the invoice');
ok(ledgerGetOperation($finInv)['status'] === 'succeeded', 'op moved to succeeded');
$finMeta = json_decode(ledgerGetOperation($finInv)['meta'], true);
ok(($finMeta['retail_pending'] ?? false) === true, 'retail_pending flag set — retail JSON balance is a documented manual step');
$invRow = $pdo->prepare("SELECT Status FROM invoice WHERE id_invoice = ?");
$invRow->execute([$finInv]);
ok($invRow->fetchColumn() === 'active', 'invoice status is active');
ok($fBal($FA) === $balBeforeFin, 'finalize moved NO money (charge happened at charge time)');
ok(ledgerFinalizeDeliveredPurchase($finInv, ['result' => 'present', 'user' => ['username' => 'userf']])['ok'] === false, 'finalize replay on succeeded op refused');
ok(ledgerFinalizeDeliveredPurchase($finInv . '_nope')['ok'] === false, 'finalize on unknown op refused');

// Extend finalize guards + one-shot history record.
$finExt = 'e_fx_fin_' . bin2hex(random_bytes(3));
$extInvId = substr($finExt, 2);
ledgerCreateOperation($finExt, LEDGER_OP_EXTEND, $FA, 1, 'buyerE', $extInvId, 'panelX', 'usere', 100, ['volume' => '10', 'time' => '30', 'retail_price' => 500]);
ledgerChargeOperation($finExt);
ledgerMarkDelivering($finExt);
$balBeforeFinE = $fBal($FA);
$bad = ledgerFinalizeDeliveredExtend($finExt, ['result' => 'mismatch', 'user' => []]);
ok($bad['ok'] === false, 'extend finalize refused on value mismatch');
$good = ledgerFinalizeDeliveredExtend($finExt, ['result' => 'delivered', 'user' => ['username' => 'usere']]);
ok($good['ok'] === true, 'extend finalize succeeds with verified delivery');
ok($fBal($FA) === $balBeforeFinE, 'extend finalize moved no money');
$soCount = $pdo->prepare("SELECT COUNT(*) FROM service_other WHERE type = 'extend_user' AND value LIKE ?");
$soCount->execute(['%"op_id":"' . $finExt . '"%']);
ok((int) $soCount->fetchColumn() === 1, 'recovery history record written exactly once');
ok(ledgerFinalizeDeliveredExtend($finExt, ['result' => 'delivered', 'user' => []])['ok'] === false, 'extend finalize replay refused (status succeeded)');
ok(ledgerFinalizeDeliveredExtend($finInv, ['result' => 'delivered', 'user' => []])['ok'] === false, 'extend finalize refused on a purchase op');
ok(ledgerFinalizeDeliveredPurchase($finExt, ['result' => 'present', 'user' => []])['ok'] === false, 'purchase finalize refused on an extend op');

section('reconcile — paid top-ups without journal (F1 detection)');

$ordR = $mkOrder($FA, 700);
$rec2 = ledgerReconcile();
$found = array_filter($rec2['paid_topups_without_journal'], fn($r) => $r['id_order'] === $ordR);
ok(count($found) === 1, 'paid agent top-up without journal is flagged');
$foundLinked = array_filter($rec2['paid_topups_without_journal'], fn($r) => $r['id_order'] === $ordL);
ok(count($foundLinked) === 0, 'order linked to a purchase is NOT flagged as top-up');
$foundNonAgent = array_filter($rec2['paid_topups_without_journal'], fn($r) => $r['id_order'] === $ordN);
ok(count($foundNonAgent) === 0, 'non-agent top-up is NOT flagged');
ledgerRepairAgentTopup($ordR);
$rec3 = ledgerReconcile();
$foundAfter = array_filter($rec3['paid_topups_without_journal'], fn($r) => $r['id_order'] === $ordR);
ok(count($foundAfter) === 0, 'flagged top-up disappears after repair');

$fixCleanup();
ok(true, 'F-fix test rows cleaned up');
