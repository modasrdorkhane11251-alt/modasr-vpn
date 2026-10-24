<?php
/** Soroush Plus platform adapter tests (no live API calls). */

section('soroush plus platform adapter');

require_once __DIR__ . '/../platform/platform.php';
require_once __DIR__ . '/../function.php';

// Capture any real configured token BEFORE we start mutating globals, for
// the non-leak scan at the end. Never printed.
$realSplusToken = modasr_token_for('splus');

// --- 1. platform whitelist -------------------------------------------------
$_GET['platform'] = 'splus';
ok(modasr_platform() === 'splus', '?platform=splus is accepted');
$_GET['platform'] = 'SPLUS';
ok(modasr_platform() === 'splus', 'platform override is case-insensitive');
$_GET['platform'] = 'soroush';
ok(modasr_platform() !== 'soroush', 'unknown platform name is rejected');
unset($_GET['platform']);

// --- 2. token resolution ----------------------------------------------------
$savedTokens = $GLOBALS['PLATFORM_TOKENS'] ?? [];
$GLOBALS['PLATFORM_TOKENS'] = ['splus' => 'TEST_SPLUS_TOKEN_PLACEHOLDER'];
ok(modasr_token_for('splus') === 'TEST_SPLUS_TOKEN_PLACEHOLDER', 'token resolves from $PLATFORM_TOKENS[\'splus\']');
ok(modasr_token_for('telegram') === '', 'empty telegram slot stays empty (no cross-platform fallback)');
$GLOBALS['PLATFORM_TOKENS'] = $savedTokens;

// --- 3. outgoing request routing (no network: empty token short-circuits) ---
[$handled, $res] = modasr_platform_request('sendMessage', ['chat_id' => 1, 'text' => 'x'], '', 'splus');
ok($handled === true, 'splus override is handled by the platform layer');
ok(($res['ok'] ?? null) === false, 'empty token returns ok=false');
ok(strpos((string) ($res['description'] ?? ''), 'Soroush Plus') !== false, 'response comes from the splus branch');

[$handledTg] = modasr_platform_request('sendMessage', ['chat_id' => 1, 'text' => 'x'], '', 'telegram');
ok($handledTg === false, 'telegram still falls through to the native transport');

// --- 4. incoming update normalisation: passthrough --------------------------
$_GET['platform'] = 'splus';
$update = [
    'update_id' => 42,
    'message' => [
        'message_id' => 7,
        'from' => ['id' => 30770260, 'first_name' => 'Owner'],
        'chat' => ['id' => 30770260, 'type' => 'private'],
        'text' => '/start',
    ],
];
ok(modasr_normalize_incoming_update($update) === $update, 'splus updates pass through unchanged (telegram-shaped)');
unset($_GET['platform']);

// --- 5. chat → platform resolution ------------------------------------------
// Rubika GUIDs keep resolving to rubika even during a splus request.
$_GET['platform'] = 'splus';
ok(modasr_platform_for_chat('u0abcdef1234567890') === 'rubika', 'rubika GUID still detected during splus request');
// Unknown numeric chat ids fall back to the current request platform.
ok(modasr_platform_for_chat('999999999999900001') === 'splus', 'unknown numeric chat falls back to request platform');
unset($_GET['platform']);

// --- 6. report-group tagging -------------------------------------------------
$_GET['platform'] = 'splus';
$tagged = modasr_tag_report_with_platform(['text' => 'گزارش تست']);
ok(strpos($tagged['text'], 'سروش پلاس') !== false, 'report messages tagged as سروش پلاس');
unset($_GET['platform']);

// --- 7. webhook secret enforcement (source guard) ----------------------------
$indexSrc = file_get_contents(__DIR__ . '/../index.php');
ok(strpos($indexSrc, "['telegram', 'bale', 'splus']") !== false, 'index.php enforces the webhook secret for splus');

// --- 8. channel soft-gate (no membership API on splus) -----------------------
if (!function_exists('isTelegramChatIdEmpty')) {
    function isTelegramChatIdEmpty($chat_id): bool
    {
        return $chat_id === null || trim((string) $chat_id) === '' || (is_numeric($chat_id) && (int) $chat_id === 0);
    }
}
$from_id = '30770260';
$splusChannels = [
    ['link' => 'splus_channel', 'platform' => 'splus'],
    ['link' => '@tg_channel',   'platform' => 'telegram'],
];
$_GET['platform'] = 'splus';
$softGate = channel($splusChannels);
ok($softGate === ['splus_channel'], 'splus force-join is a soft gate on its own channels only');
unset($_GET['platform']);

// --- 9. platform-qualified admin ids -----------------------------------------
ok(modasr_admin_id_parts('splus:30770260') === ['splus', '30770260'], 'qualified entry splits into platform + id');
ok(modasr_admin_id_parts('1355650097') === [null, '1355650097'], 'legacy numeric entry has no platform');
ok(modasr_admin_id_parts('u0J1q4A052b3e360c26b14d89fc1cdf0') === [null, 'u0J1q4A052b3e360c26b14d89fc1cdf0'], 'legacy GUID entry has no platform');
ok(modasr_admin_chat_id('splus:30770260') === '30770260', 'chat id helper strips the prefix');

$GLOBALS['admin_ids'] = ['splus:30770260', '1355650097', 'u0J1q4A052b3e360c26b14d89fc1cdf0'];

$_GET['platform'] = 'splus';
ok(modasr_is_admin('30770260') === true, 'splus owner is admin on splus');
ok(modasr_is_admin('1355650097') === true, 'legacy numeric admin keeps working on splus request (legacy behaviour preserved)');
$_GET['platform'] = 'telegram';
ok(modasr_is_admin('30770260') === false, 'CRITICAL: a telegram user with the same numeric id is NOT admin');
ok(modasr_is_admin('1355650097') === true, 'legacy telegram admin still admin on telegram');
$_GET['platform'] = 'rubika';
ok(modasr_is_admin('30770260') === false, 'rubika user with same id is NOT admin');
ok(modasr_is_admin('u0J1q4A052b3e360c26b14d89fc1cdf0') === true, 'legacy GUID admin still admin on rubika');
$_GET['platform'] = 'bale';
ok(modasr_is_admin('30770260') === false, 'bale user with same id is NOT admin');
unset($_GET['platform'], $GLOBALS['admin_ids']);

// telegram() must strip the platform prefix and force routing (source guard:
// the live function cannot be loaded in the test harness because botapi.php
// parses php://input at include time).
$botapiSrc = file_get_contents(__DIR__ . '/../botapi.php');
ok(strpos($botapiSrc, '$forcedPlatform') !== false, 'telegram() honours platform-qualified chat ids');

// --- 10. token non-leak scan ----------------------------------------------------
// The real token (when configured on the server) must not appear anywhere in
// the codebase outside config.php.
$isRealToken = is_string($realSplusToken) && strlen($realSplusToken) >= 10
    && strpos($realSplusToken, '{') === false;
if (!$isRealToken) {
    ok(true, 'no real splus token configured here — leak scan skipped (placeholder)');
} else {
    $leaks = [];
    $root = dirname(__DIR__);
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $path = $file->getPathname();
        if (preg_match('#/(vendor|\.git|storage)/#', $path)) {
            continue;
        }
        if (basename($path) === 'config.php') {
            continue; // the only allowed location
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $content = @file_get_contents($path);
        if (is_string($content) && strpos($content, $realSplusToken) !== false) {
            $leaks[] = $path;
        }
    }
    ok($leaks === [], 'real splus token appears nowhere outside config.php');
}
