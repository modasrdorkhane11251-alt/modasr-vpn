<?php
/** Account-linking tests: link codes, wallet merge, isolation, races. */

section('account linking');

require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../db/Schema.php';

// Ensure the migration is applied (idempotent — safe to re-run).
$migration019 = require __DIR__ . '/../db/migrations/019_account_link.php';
$migration019($pdo, new Schema($pdo, []));

global $pdo;
$pdo->exec("SET SESSION sql_mode=''");

// Synthetic users (unique prefix so re-runs never collide with real data).
$LP = 'test_link_p_' . substr(md5(uniqid()), 0, 6);   // primary
$LS = 'test_link_s_' . substr(md5(uniqid()), 0, 6);   // secondary
$LA = 'test_link_a_' . substr(md5(uniqid()), 0, 6);   // agent (blocked)
$LX = 'test_link_x_' . substr(md5(uniqid()), 0, 6);   // stranger
$LY = 'test_link_y_' . substr(md5(uniqid()), 0, 6);   // second primary candidate

$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 50000, 'f')")->execute([$LP]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 5000, 'f')")->execute([$LS]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 9000, 'n2')")->execute([$LA]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 100, 'f')")->execute([$LX]);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 700, 'f')")->execute([$LY]);
clearSelectCache('user');

$balanceOf = function ($id) use ($pdo) {
    return (int) $pdo->query("SELECT Balance FROM user WHERE id = " . $pdo->quote($id))->fetchColumn();
};

// --- 1. code issuing ---------------------------------------------------------
[$ok, $code] = accountLinkIssueCode($LP);
ok($ok === true && preg_match('/^\d{6}$/', (string) $code) === 1, 'issue: primary gets a 6-digit code');

[$okA, $errA] = accountLinkIssueCode($LA);
ok($okA === false && $errA === 'agent_blocked', 'issue: agent account refused');

// --- 2. happy path redeem ----------------------------------------------------
[$ok, $err, $merged] = accountLinkRedeemCode($code, $LS, 'splus');
ok($ok === true && $merged === 5000, 'redeem: secondary linked, merge amount reported');
ok($balanceOf($LP) === 55000, 'redeem: merged balance moved to primary');
ok($balanceOf($LS) === 0, 'redeem: secondary wallet zeroed');
ok(resellerJournalExists('linkmerge_' . $code), 'redeem: audit journal entry written');

// --- 3. resolution helpers ---------------------------------------------------
ok(modasr_primary_user_id($LS) === $LP, 'secondary resolves to primary');
ok(modasr_primary_user_id($LP) === $LP, 'primary resolves to itself');
ok(modasr_primary_user_id($LX) === $LX, 'stranger resolves to itself');
$ids = modasr_linked_user_ids($LS);
ok(in_array($LP, $ids, true) && in_array($LS, $ids, true), 'linked ids include both accounts');

// wallet overlay: secondary row sees primary balance
$secRow = (array) select("user", "*", "id", $LS, "select");
$overlaid = modasr_apply_primary_wallet($secRow);
ok((int) $overlaid['Balance'] === 55000 && ($overlaid['linked_primary'] ?? '') === $LP, 'wallet overlay: secondary sees primary balance');

// --- 4. wallet choke points route to primary ---------------------------------
addBalance($LS, 1200);
ok($balanceOf($LP) === 56200 && $balanceOf($LS) === 0, 'addBalance on secondary credits the primary');

$secRow = (array) select("user", "*", "id", $LS, "select");
$secRow = modasr_apply_primary_wallet($secRow);
ok(deductBalance($secRow, 200) === true && $balanceOf($LP) === 56000, 'deductBalance on secondary charges the primary');
ok(deductBalance($secRow, 999999999) === false && $balanceOf($LP) === 56000, 'deductBalance over balance refused, wallet untouched');

// --- 5. ownership across linked ids -------------------------------------------
ok(invoiceBelongsToUser(['id_user' => $LS], $LP) === true, 'primary owns secondary invoices');
ok(invoiceBelongsToUser(['id_user' => $LP], $LS) === true, 'secondary owns primary invoices');
ok(invoiceBelongsToUser(['id_user' => $LX], $LP) === false, 'stranger invoice still refused');

// --- 6. misuse protection ------------------------------------------------------
[$ok2, $err2] = accountLinkRedeemCode($code, $LX, 'telegram');
ok($ok2 === false && $err2 === 'invalid_code', 'one-time code cannot be reused');

[$okC, $codeC] = accountLinkIssueCode($LY);
ok($okC === true, 'second primary gets a code');
[$okD, $errD] = accountLinkRedeemCode($codeC, $LP, 'telegram');
ok($okD === false && $errD === 'has_own_links', 'a primary with links cannot become a secondary');
[$okE, $errE] = accountLinkRedeemCode($codeC, $LS, 'telegram');
ok($okE === false && $errE === 'already_linked', 'a linked secondary cannot link again');

[$okF, $codeF] = accountLinkIssueCode($LX);
[$okG, $errG] = accountLinkRedeemCode($codeF, $LA, 'telegram');
ok($okG === false && $errG === 'agent_blocked', 'redeem: agent secondary refused');

[$okH, $errH] = accountLinkRedeemCode($codeF, $LX, 'telegram');
ok($okH === false && $errH === 'self_link', 'self-link refused');

// --- 7. rate limiting -----------------------------------------------------------
$LR = 'test_link_r_' . substr(md5(uniqid()), 0, 6);
$pdo->prepare("INSERT INTO user (id, step, Balance, agent) VALUES (?, 'none', 0, 'f')")->execute([$LR]);
clearSelectCache('user');
for ($i = 0; $i < 5; $i++) {
    accountLinkRedeemCode('000000', $LR, 'telegram');
}
[$okR, $errR] = accountLinkRedeemCode('123456', $LR, 'telegram');
ok($okR === false && $errR === 'rate_limited', 'redeem: rate limited after 5 failures');

// --- 8. unlink -------------------------------------------------------------------
ok(accountLinkUnlink($LX, $LS) === false, 'stranger cannot unlink someone else');
ok(accountLinkUnlink($LP, $LS) === true, 'primary can unlink its secondary');
ok(modasr_primary_user_id($LS) === $LS, 'after unlink the secondary is its own account again');
ok($balanceOf($LS) === 0, 'unlink: merged money stays on primary (no reverse transfer)');

// --- cleanup ----------------------------------------------------------------------
$idsToClean = [$LP, $LS, $LA, $LX, $LY, $LR];
foreach ($idsToClean as $cid) {
    $pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$cid]);
    $pdo->prepare("DELETE FROM user_link WHERE primary_user_id = ? OR linked_user_id = ?")->execute([$cid, $cid]);
    $pdo->prepare("DELETE FROM account_link_code WHERE primary_user_id = ? OR used_by = ?")->execute([$cid, $cid]);
    $pdo->prepare("DELETE FROM account_link_attempt WHERE user_id = ?")->execute([$cid]);
    $pdo->prepare("DELETE FROM agent_transaction WHERE agent_id = ? AND type = 'link_merge'")->execute([$cid]);
}
clearSelectCache('user');
ok(true, 'test rows cleaned up');
