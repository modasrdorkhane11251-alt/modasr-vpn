<?php
/** Reseller system tests — state machine, journal idempotency, pricing policy.
 *  Uses real DB (server) but cleans up every row it creates. */

section('reseller');

require_once __DIR__ . '/../function.php';

global $pdo;
$testAgentId = 'test_agent_' . bin2hex(random_bytes(4));
$cleanup = function () use ($pdo, $testAgentId) {
    $pdo->prepare("DELETE FROM Requestagent WHERE id = ?")->execute([$testAgentId]);
    $pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ?")->execute([$testAgentId]);
    $pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$testAgentId]);
};
$cleanup(); // in case a previous run was interrupted

/* ---------- pure pricing policy ---------- */
$product = ['price_product' => '10000', 'wholesale_price' => null, 'min_retail_price' => null, 'max_retail_price' => null];
ok(resellerWholesalePrice($product, ['pricediscount' => '0']) === 10000, 'wholesale falls back to base price');
$product['wholesale_price'] = '7000';
ok(resellerWholesalePrice($product, ['pricediscount' => '0']) === 7000, 'wholesale_price overrides base');
ok(resellerWholesalePrice($product, ['pricediscount' => '10']) === 6300, 'agent discount applied to wholesale');
ok(resellerWholesalePrice($product, ['pricediscount' => '150']) === 7000, 'invalid discount ignored');

ok(resellerRetailPriceAllowed($product, 5) === true, 'no bounds = any price allowed');
$product['min_retail_price'] = '8000';
$product['max_retail_price'] = '20000';
ok(resellerRetailPriceAllowed($product, 7999) === false, 'below min retail rejected');
ok(resellerRetailPriceAllowed($product, 8000) === true, 'min retail boundary allowed');
ok(resellerRetailPriceAllowed($product, 20001) === false, 'above max retail rejected');
ok(resellerRetailPriceAllowed($product, 20000) === true, 'max retail boundary allowed');

ok(resellerBotIsActive(['status' => 'active']) === true, 'active bot serves');
ok(resellerBotIsActive(['status' => 'suspended']) === false, 'suspended bot refused');
ok(resellerBotIsActive([]) === true, 'legacy bot row without status = active (backward compatible)');

/* ---------- request state machine (real DB, cleaned up) ---------- */
$testUser = ['id' => $testAgentId, 'username' => 'testreseller'];
ok(resellerSubmitRequest($testUser, 'توضیح تست') === true, 'request submitted without payment');
ok(resellerSubmitRequest($testUser, 'دوباره') === false, 'duplicate open request refused');
ok(resellerGetRequest($testAgentId)['status'] === 'waiting', 'request starts in waiting state');

ok(resellerPayActivationFee(['id' => $testAgentId]) === 'bad_state', 'fee payment refused before approval');
ok(resellerApproveRequest($testAgentId) === true, 'admin approval moves waiting -> approved');
ok(resellerApproveRequest($testAgentId) === false, 'double approval refused');

// reject path first: approved -> rejected -> resubmission allowed
ok(resellerRejectRequest($testAgentId) === true, 'rejection works from approved');
$resubmit = resellerSubmitRequest($testUser, 'درخواست مجدد');
ok($resubmit === true && resellerGetRequest($testAgentId)['status'] === 'waiting', 'resubmission after rejection reopens request');
ok(resellerApproveRequest($testAgentId) === true, 're-approval after resubmission');

$fee = resellerActivationFee();
$payResult = resellerPayActivationFee(['id' => $testAgentId, 'Balance' => '0', 'agent' => 'f', 'maxbuyagent' => '0']);
if ($fee > 0) {
    ok($payResult === 'no_balance', 'fee payment with empty wallet refused');
    ok(resellerGetRequest($testAgentId)['status'] === 'approved', 'failed fee payment keeps approved state');
} else {
    ok($payResult === 'paid', 'zero fee activates immediately');
    ok(resellerGetRequest($testAgentId)['status'] === 'paid', 'state moves to paid after fee');
    ok(resellerPayActivationFee(['id' => $testAgentId, 'Balance' => '0']) === 'bad_state', 'double fee payment refused');
}

/* ---------- journal idempotency ---------- */
$ref = 'test_ref_' . bin2hex(random_bytes(4));
$first = resellerJournal($testAgentId, 'test', -100, $ref);
$second = resellerJournal($testAgentId, 'test', -100, $ref);
ok($first > 0, 'journal insert succeeds');
ok($second === 0, 'duplicate journal ref ignored (idempotency)');
ok(resellerJournalExists($ref) === true, 'journal ref lookup works');
ok(resellerJournalExists('nonexistent_ref_xyz') === false, 'unknown ref not found');

$cleanup();
clearSelectCache('Requestagent');
clearSelectCache('agent_transaction');
clearSelectCache('user');
ok(resellerGetRequest($testAgentId) === null, 'test rows cleaned up');
