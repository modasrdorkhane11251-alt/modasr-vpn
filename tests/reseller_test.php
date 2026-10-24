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

section('reseller — adversarial');

/* ---------- simulated concurrency: two charges racing for the same funds ---------- */
global $pdo;
$raceAgent = 'race_' . bin2hex(random_bytes(4));
$pdo->exec("SET SESSION sql_mode=''");
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 500, 'n')")->execute([$raceAgent]);
clearSelectCache('user');
$raceUser = select("user", "*", "id", $raceAgent, "select");
// two concurrent purchases of 400 each: only ONE may succeed
$r1 = resellerChargeWallet($raceUser, 400, 'wholesale_purchase', 'race_a');
clearSelectCache('user');
$raceUser2 = select("user", "*", "id", $raceAgent, "select");
$r2 = resellerChargeWallet($raceUser2, 400, 'wholesale_purchase', 'race_b');
ok($r1 === 100 && $r2 === null, 'racing charges: first succeeds, second refused (no overdraw)');
clearSelectCache('user');
ok(intval(select("user", "Balance", "id", $raceAgent, "select")['Balance']) === 100, 'balance consistent after race (100 left)');

/* ---------- n2 credit-limit race ---------- */
$pdo->prepare("UPDATE user SET agent = 'n2', maxbuyagent = 300 WHERE id = ?")->execute([$raceAgent]);
clearSelectCache('user');
$u = select("user", "*", "id", $raceAgent, "select");
$c1 = resellerChargeWallet($u, 700, 'wholesale_purchase', 'race_c'); // 100-700 = -600 < -300 -> refuse
ok($c1 === null, 'n2 charge beyond credit limit refused');
$c2 = resellerChargeWallet($u, 350, 'wholesale_purchase', 'race_d'); // 100-350 = -250 >= -300 -> ok
ok($c2 === -250, 'n2 charge within credit limit succeeds (negative balance allowed)');
$pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$raceAgent]);
$pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ?")->execute([$raceAgent]);

/* ---------- duplicate activation-fee callback (simulated double-click) ---------- */
$dupAgent = 'dup_' . bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 0, 'f')")->execute([$dupAgent]);
resellerSubmitRequest(['id' => $dupAgent, 'username' => 'd'], 't');
resellerApproveRequest($dupAgent);
// fee is 0 on this server; pay twice — second must be refused
$pay1 = resellerPayActivationFee(['id' => $dupAgent, 'Balance' => '0', 'agent' => 'f', 'maxbuyagent' => '0']);
$pay2 = resellerPayActivationFee(['id' => $dupAgent, 'Balance' => '0', 'agent' => 'f', 'maxbuyagent' => '0']);
ok($pay1 === 'paid' && $pay2 === 'bad_state', 'double fee callback: second refused');
// activation is also a one-way conditional transition
ok(resellerActivate($dupAgent) === true, 'activation from paid works');
ok(resellerActivate($dupAgent) === false, 'double activation refused');
ok(resellerRejectRequest($dupAgent) === false, 'rejection of active reseller refused');
clearSelectCache('Requestagent');
ok(resellerGetRequest($dupAgent)['status'] === 'active', 'final state is active');
$pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$dupAgent]);
$pdo->prepare("DELETE FROM Requestagent WHERE id = ?")->execute([$dupAgent]);
$pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ?")->execute([$dupAgent]);

/* ---------- cross-tenant isolation: owner resolution only via token ---------- */
$tokA = '111111:AAAA' . bin2hex(random_bytes(8));
$tokB = '222222:BBBB' . bin2hex(random_bytes(8));
$ownA = 'ownA_' . bin2hex(random_bytes(3));
$ownB = 'ownB_' . bin2hex(random_bytes(3));
foreach ([[$ownA, $tokA, 'botA'], [$ownB, $tokB, 'botB']] as [$oid, $tok, $uname]) {
    $pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 0, 'n')")->execute([$oid]);
    $pdo->prepare("INSERT INTO botsaz (id_user, bot_token, admin_ids, username, time, setting, hide_panel, webhook_secret) VALUES (?, ?, '[]', ?, 't', '{}', '{}', 'x')")->execute([$oid, $tok, $uname]);
}
clearSelectCache('botsaz');
$ownerFromA = resellerOwnerFromToken($tokA);
ok(is_array($ownerFromA) && $ownerFromA['id'] === $ownA, 'token A resolves owner A');
ok(resellerOwnerFromToken($tokB)['id'] === $ownB, 'token B resolves owner B');
ok(resellerOwnerFromToken($tokA)['id'] !== $ownB, 'token A never resolves owner B');
ok(resellerOwnerFromToken('999999:FORGED_token') === null, 'forged token resolves to nothing');
// tenant B cannot suspend/read as tenant A: toggle is scoped by owner id
resellerToggleBotStatus($ownA);
clearSelectCache('botsaz');
ok(resellerBotIsActive(resellerBotFromToken($tokA)) === false, 'bot A suspended via owner A');
ok(resellerBotIsActive(resellerBotFromToken($tokB)) === true, 'bot B unaffected by action on A');
$pdo->prepare("DELETE FROM botsaz WHERE bot_token IN (?, ?)")->execute([$tokA, $tokB]);
$pdo->prepare("DELETE FROM user WHERE id IN (?, ?)")->execute([$ownA, $ownB]);
clearSelectCache('botsaz');
