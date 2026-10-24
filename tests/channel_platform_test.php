<?php
/** Per-platform force-join filtering tests (API calls are stubbed). */

section('channel() platform filtering');

require_once __DIR__ . '/../platform/platform.php';
require_once __DIR__ . '/../function.php';

if (!function_exists('isTelegramChatIdEmpty')) {
    function isTelegramChatIdEmpty($chat_id): bool
    {
        return $chat_id === null || trim((string) $chat_id) === '' || (is_numeric($chat_id) && (int) $chat_id === 0);
    }
}

// Stub the messenger API: record calls, report "not a member" so channels
// stay on the must-join list.
$GLOBALS['__api_calls'] = [];
function telegram($method, $datas = [], $token = null, $allowEmojiFallback = true)
{
    $GLOBALS['__api_calls'][] = [$method, $datas];
    return ['ok' => true, 'result' => ['status' => 'left']];
}

$from_id = '12345';
$channels = [
    ['link' => '@tg_channel',  'platform' => 'telegram'],
    ['link' => '@bale_channel','platform' => 'bale'],
    ['link' => 'rubika_channel','platform' => 'rubika'],
];

// A Telegram user must only be checked against Telegram channels.
$_GET['platform'] = 'telegram';
$GLOBALS['__api_calls'] = [];
$result = channel($channels);
ok($result === ['@tg_channel'], 'telegram user sees only telegram channel');
ok(count($GLOBALS['__api_calls']) === 1, 'exactly one API call for telegram user');
ok(($GLOBALS['__api_calls'][0][1]['chat_id'] ?? '') === '@tg_channel', 'API called with the telegram channel');

// A Bale user must only be checked against Bale channels.
$_GET['platform'] = 'bale';
$GLOBALS['__api_calls'] = [];
$result = channel($channels);
ok($result === ['@bale_channel'], 'bale user sees only bale channel');
ok(count($GLOBALS['__api_calls']) === 1, 'exactly one API call for bale user');

// Rubika has no membership API: soft gate, no API call must happen.
$_GET['platform'] = 'rubika';
$GLOBALS['__api_calls'] = [];
$result = channel($channels);
ok($result === ['rubika_channel'], 'rubika user sees only rubika channel (soft gate)');
ok(count($GLOBALS['__api_calls']) === 0, 'no API call for rubika (no membership endpoint exists)');

// Legacy rows without a platform default to telegram.
$_GET['platform'] = 'telegram';
$GLOBALS['__api_calls'] = [];
$result = channel([['link' => '@legacy', 'platform' => ''], '@plain']);
ok($result === ['@legacy', '@plain'], 'rows without platform default to telegram');

unset($_GET['platform']);
