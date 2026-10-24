<?php
/** Mini-app auth tests: multi-platform initData, sessions, magic links. */

section('miniapp auth');

require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../db/Schema.php';

// Ensure the migration is applied (idempotent — safe to re-run).
$migration020 = require __DIR__ . '/../db/migrations/020_miniapp_session.php';
$migration020($pdo, new Schema($pdo, []));

global $pdo;
$pdo->exec("SET SESSION sql_mode=''");

$MP = 'test_map_p_' . substr(md5(uniqid()), 0, 6);   // primary
$MS = 'test_map_s_' . substr(md5(uniqid()), 0, 6);   // secondary (linked)

$pdo->prepare("INSERT INTO user (id, platform, step, Balance, agent) VALUES (?, 'telegram', 'none', 1000, 'f')")->execute([$MP]);
$pdo->prepare("INSERT INTO user (id, platform, step, Balance, agent) VALUES (?, 'splus', 'none', 250, 'f')")->execute([$MS]);
clearSelectCache('user');

// --- 1. initData validation ---------------------------------------------------
$signInitData = function (array $data, string $token): string {
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);
    return http_build_query($data);
};

$tgToken = 'TESTONLY_telegram_token_1';
$userJson = json_encode(['id' => 424242, 'first_name' => 'T']);
$good = $signInitData(['auth_date' => time(), 'query_id' => 'AAE', 'user' => $userJson], $tgToken);
$parsed = miniapp_validate_init_data($good, $tgToken);
ok((int) $parsed['id'] === 424242, 'initData: valid signature accepted');

$threw = false;
try {
    miniapp_validate_init_data($good, 'TESTONLY_wrong_token');
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'User verification failed';
}
ok($threw, 'initData: wrong token rejected');

$threw = false;
try {
    miniapp_validate_init_data('auth_date=1&user=%7B%7D', $tgToken);
} catch (Throwable $e) {
    $threw = true;
}
ok($threw, 'initData: unsigned payload rejected');

$old = $signInitData(['auth_date' => time() - 90000, 'user' => $userJson], $tgToken);
$threw = false;
try {
    miniapp_validate_init_data($old, $tgToken);
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'init data has expired';
}
ok($threw, 'initData: expired auth_date rejected');

// --- 2. multi-platform identification -----------------------------------------
$PLATFORM_TOKENS_BACKUP = $GLOBALS['PLATFORM_TOKENS'] ?? null;
$GLOBALS['PLATFORM_TOKENS'] = [
    'telegram' => 'TESTONLY_telegram_token_1',
    'bale' => 'TESTONLY_bale_token_2',
    'splus' => 'TESTONLY_splus_token_3',
    'rubika' => '',
];
$identified = miniapp_identify_platform($signInitData(['auth_date' => time(), 'user' => json_encode(['id' => 1])], 'TESTONLY_bale_token_2'));
ok($identified !== null && $identified['platform'] === 'bale', 'identify: bale signature maps to bale');
$identified = miniapp_identify_platform($signInitData(['auth_date' => time(), 'user' => json_encode(['id' => 1])], 'TESTONLY_splus_token_3'));
ok($identified !== null && $identified['platform'] === 'splus', 'identify: splus signature maps to splus');
$identified = miniapp_identify_platform($signInitData(['auth_date' => time(), 'user' => json_encode(['id' => 1])], 'TESTONLY_unknown_token'));
ok($identified === null, 'identify: unknown token never matches');
if ($PLATFORM_TOKENS_BACKUP !== null) {
    $GLOBALS['PLATFORM_TOKENS'] = $PLATFORM_TOKENS_BACKUP;
}

// --- 3. sessions ----------------------------------------------------------------
$link = $pdo->prepare("INSERT INTO user_link (primary_user_id, linked_user_id, platform, linked_via, created_at) VALUES (?, ?, 'splus', 'code', ?)");
$link->execute([$MP, $MS, date('Y-m-d H:i:s')]);
modasr_account_link_cache_flush();

$sessionA = miniapp_issue_session($MS, 'splus');       // login from the LINKED account
$sessionB = miniapp_issue_session($MP, 'telegram');    // login from the primary itself
ok($sessionA !== $sessionB, 'sessions: distinct tokens per login');

$resolvedA = miniapp_resolve_session($sessionA);
$resolvedB = miniapp_resolve_session($sessionB);
ok($resolvedA && $resolvedA['id'] === $MP, 'sessions: login via linked account resolves to PRIMARY');
ok($resolvedB && $resolvedB['id'] === $MP, 'sessions: primary login resolves to primary');
ok(miniapp_resolve_session($sessionA) && miniapp_resolve_session($sessionB), 'sessions: concurrent sessions do NOT kick each other out');
ok(miniapp_resolve_session('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef') === null, 'sessions: unknown token rejected');
ok(miniapp_resolve_session('') === null, 'sessions: empty token rejected');

// legacy fallback: token only in user.token (pre-migration sessions)
$legacyToken = bin2hex(random_bytes(20));
$pdo->prepare("UPDATE user SET token = ? WHERE id = ?")->execute([$legacyToken, $MP]);
$pdo->prepare("DELETE FROM miniapp_session WHERE token = ?")->execute([$legacyToken]);
clearSelectCache('user');
$resolvedLegacy = miniapp_resolve_session($legacyToken);
ok($resolvedLegacy && $resolvedLegacy['id'] === $MP, 'sessions: legacy user.token fallback works');

// expired session rejected
$expired = bin2hex(random_bytes(20));
$pdo->prepare("INSERT INTO miniapp_session (token, user_id, platform, created_at, expires_at) VALUES (?, ?, 'telegram', NOW(), DATE_SUB(NOW(), INTERVAL 1 HOUR))")->execute([$expired, $MP]);
ok(miniapp_resolve_session($expired) === null, 'sessions: expired session rejected');

// --- 4. magic-link auth tokens ---------------------------------------------------
$auth1 = miniapp_issue_auth_token($MP, 'telegram');
ok(is_string($auth1) && preg_match('/^[a-f0-9]{40}$/', $auth1) === 1, 'magic link: token issued (40 hex)');

$consumed = miniapp_consume_auth_token($auth1);
ok($consumed !== null && $consumed['user_id'] === $MP && $consumed['platform'] === 'telegram', 'magic link: consume returns owner + platform');
ok(miniapp_consume_auth_token($auth1) === null, 'magic link: single-use (second consume fails)');
ok(miniapp_consume_auth_token('not-a-token') === null, 'magic link: malformed token rejected');
ok(miniapp_consume_auth_token(str_repeat('a', 40)) === null, 'magic link: unknown token rejected');

// rate limit
$issued = 0;
for ($i = 0; $i < MODASR_MINIAPP_MAX_ACTIVE_AUTH + 2; $i++) {
    if (miniapp_issue_auth_token($MS, 'splus') !== null) {
        $issued++;
    }
}
ok($issued === MODASR_MINIAPP_MAX_ACTIVE_AUTH, 'magic link: per-user active-token cap enforced');

// --- 5. CryptoJS-compatible encryption -------------------------------------------
$ciphertext = miniapp_cryptojs_encrypt('{"user":{"id":1},"token":"abc"}', 'test-passphrase');
$raw = base64_decode($ciphertext, true);
ok($raw !== false && substr($raw, 0, 8) === 'Salted__', 'cryptojs: salted OpenSSL format');
$salt = substr($raw, 8, 8);
$keyIv = '';
$block = '';
while (strlen($keyIv) < 48) {
    $block = md5($block . 'test-passphrase' . $salt, true);
    $keyIv .= $block;
}
$plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', substr($keyIv, 0, 32), OPENSSL_RAW_DATA, substr($keyIv, 32, 16));
ok($plain === '{"user":{"id":1},"token":"abc"}', 'cryptojs: roundtrip decrypt matches plaintext');

// --- cleanup ----------------------------------------------------------------------
$pdo->prepare("DELETE FROM user_link WHERE primary_user_id = ? OR linked_user_id IN (?, ?)")->execute([$MP, $MP, $MS]);
$pdo->prepare("DELETE FROM miniapp_session WHERE user_id IN (?, ?)")->execute([$MP, $MS]);
$pdo->prepare("DELETE FROM miniapp_auth_token WHERE user_id IN (?, ?)")->execute([$MP, $MS]);
$pdo->prepare("DELETE FROM user WHERE id IN (?, ?)")->execute([$MP, $MS]);
clearSelectCache('user');
modasr_account_link_cache_flush();
