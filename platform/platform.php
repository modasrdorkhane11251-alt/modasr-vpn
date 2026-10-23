<?php
/**
 * MODASR multi-platform layer.
 *
 * Allows the bot core to run on three messengers:
 *   - telegram : native Bot API (default behaviour, unchanged)
 *   - bale     : Bale messenger Bot API (Telegram-compatible, different base URL)
 *   - rubika   : Rubika messenger Bot API (different schema, adapted by RubikaClient)
 *
 * The active platform is selected with $platform in config.php:
 *   $platform = 'telegram'; // or 'bale' | 'rubika'
 */

require_once __DIR__ . '/RubikaClient.php';

function modasr_platform(): string
{
    global $platform;
    // Per-request override: webhooks for the secondary platforms carry
    // ?platform=bale|rubika so one installation can serve all messengers
    // at the same time on a single database.
    if (isset($_GET['platform'])) {
        $q = strtolower(trim((string) $_GET['platform']));
        if (in_array($q, ['telegram', 'bale', 'rubika'], true)) {
            return $q;
        }
    }
    $p = isset($platform) ? strtolower(trim((string) $platform)) : 'telegram';
    return in_array($p, ['telegram', 'bale', 'rubika'], true) ? $p : 'telegram';
}

/**
 * Bot token for a given platform. Reads $PLATFORM_TOKENS from config.php:
 *   $PLATFORM_TOKENS = ['telegram' => '…', 'bale' => '…', 'rubika' => '…'];
 * Falls back to the main $APIKEY for the primary platform.
 */
function modasr_token_for(string $p): string
{
    global $PLATFORM_TOKENS, $APIKEY;
    $tokens = isset($PLATFORM_TOKENS) && is_array($PLATFORM_TOKENS) ? $PLATFORM_TOKENS : [];
    $t = trim((string) ($tokens[$p] ?? ''));
    if ($t !== '') {
        return $t;
    }
    return ($p === modasr_platform() && isset($APIKEY)) ? (string) $APIKEY : '';
}

/**
 * Guess which platform a chat_id belongs to:
 *  - Rubika ids are GUID-like (u0…, b0…, g0…, c0…)
 *  - otherwise look up the user's stored platform in the database
 *  - fallback: the platform of the current request
 */
function modasr_platform_for_chat($chatId): string
{
    $chatId = (string) $chatId;
    if ($chatId !== '' && preg_match('/^[ugbc]0[A-Za-z0-9]{10,}$/', $chatId)) {
        return 'rubika';
    }
    static $cache = [];
    if (array_key_exists($chatId, $cache)) {
        return $cache[$chatId];
    }
    $result = '';
    if ($chatId !== '' && function_exists('select')) {
        try {
            $row = select("user", "platform", "id", $chatId, "select");
            $result = (string) ($row['platform'] ?? '');
        } catch (\Throwable $e) {
            $result = '';
        }
    }
    if (!in_array($result, ['telegram', 'bale', 'rubika'], true)) {
        $result = modasr_platform();
    }
    $cache[$chatId] = $result;
    return $result;
}

/**
 * Make sure the user table can store each user's home platform.
 */
function modasr_ensure_user_platform_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    global $pdo;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM user LIKE 'platform'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE user ADD COLUMN platform VARCHAR(20) NOT NULL DEFAULT '' AFTER id");
        }
    } catch (\Throwable $e) {
        // non-fatal
    }
}

/**
 * Route an outgoing Bot API call through the active platform.
 *
 * @return array{0:bool,1:array} [handled, telegram-style result]
 */
function modasr_platform_request(string $method, array $datas, string $token, string $platformOverride = ''): array
{
    $p = $platformOverride !== '' ? $platformOverride : modasr_platform();
    if ($p === 'telegram') {
        return [false, []]; // fall through to the original Telegram transport
    }

    if ($p === 'bale') {
        return [true, modasr_bale_request($method, $datas, $token)];
    }

    // rubika
    $client = new RubikaClient($token);
    return [true, $client->call($method, $datas)];
}

/**
 * Bale Bot API is wire-compatible with the Telegram Bot API;
 * only the base URL (and token format) differs.
 */
function modasr_bale_request(string $method, array $datas, string $token): array
{
    // Bale does not support custom emoji payloads.
    if (function_exists('payloadHasCustomEmoji') && payloadHasCustomEmoji($datas)) {
        $datas = stripCustomEmojiPayload($datas);
    }

    $url = 'https://tapi.bale.ai/bot' . $token . '/' . $method;

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'description' => 'Unable to initialise cURL for Bale request.'];
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'description' => $err !== '' ? $err : 'Bale request failed.'];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error_code' => $code, 'description' => 'Invalid response received from Bale.'];
    }
    return $decoded;
}

/**
 * Normalise an incoming webhook update into the Telegram-style update
 * structure that the rest of the codebase expects.
 *
 * Bale already sends Telegram-shaped updates, so it passes through.
 */
function modasr_normalize_incoming_update($update)
{
    if (!is_array($update)) {
        return $update;
    }
    if (modasr_platform() !== 'rubika') {
        return $update;
    }
    return RubikaClient::normalizeUpdate($update);
}

/* ------------------------------------------------------------------ */
/*  Telegram reports group (forum topics) alongside Bale/Rubika        */
/* ------------------------------------------------------------------ */

/**
 * Optional Telegram bot token used only for the reports group.
 * Set $REPORTS_BOT_TOKEN in config.php to enable.
 */
function modasr_reports_token(): string
{
    global $REPORTS_BOT_TOKEN;
    $t = isset($REPORTS_BOT_TOKEN) ? trim((string) $REPORTS_BOT_TOKEN) : '';
    // When no dedicated reports bot is set, the main Telegram bot (if any)
    // handles the reports group.
    return $t !== '' ? $t : modasr_token_for('telegram');
}

/**
 * Cached copy of the reports-group chat id (setting.Channel_Report).
 */
function modasr_reports_chat_id(): string
{
    static $chatId = null;
    if ($chatId === null) {
        $chatId = '';
        try {
            if (function_exists('select')) {
                $row = select("setting", "Channel_Report", null, null, "select");
                $chatId = (string) ($row['Channel_Report'] ?? '');
            }
        } catch (\Throwable $e) {
            $chatId = '';
        }
    }
    return $chatId;
}

/**
 * Decide whether a telegram() call targets the Telegram reports group
 * (and therefore must bypass Bale/Rubika and go to api.telegram.org).
 */
function modasr_reports_route(string $method, array $datas): bool
{
    if (modasr_reports_token() === '') {
        return false;
    }
    // Forum-topic management only makes sense on Telegram.
    if (stripos($method, 'ForumTopic') !== false || $method === 'createForumTopic') {
        return true;
    }
    // Anything addressed to a topic thread is a report-group message.
    if (isset($datas['message_thread_id'])) {
        return true;
    }
    // Direct messages to the configured reports group.
    $reportChat = modasr_reports_chat_id();
    if ($reportChat !== '' && isset($datas['chat_id']) && (string) $datas['chat_id'] === $reportChat) {
        return true;
    }
    return false;
}

/**
 * Minimal direct call to the Telegram Bot API (used for the reports group).
 */
function modasr_telegram_api(string $token, string $method, array $datas): array
{
    if ($token === '') {
        return ['ok' => false, 'error_code' => 0, 'description' => 'reports token not configured'];
    }
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $datas,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'error_code' => 0, 'description' => 'no response'];
}

/**
 * Prepend a platform tag (تلگرام / بله / روبیکا) to report-group messages
 * so the admin can see which messenger the user came from.
 */
function modasr_tag_report_with_platform(array $datas): array
{
    $labels = [
        'telegram' => 'تلگرام',
        'bale'     => 'بله',
        'rubika'   => 'روبیکا',
    ];
    $p = modasr_platform();
    $label = $labels[$p] ?? $p;
    $tag = "📱 پلتفرم: " . $label . "\n";
    foreach (['text', 'caption'] as $field) {
        if (isset($datas[$field]) && is_string($datas[$field]) && $datas[$field] !== ''
            && strpos($datas[$field], 'پلتفرم:') === false) {
            $datas[$field] = $tag . $datas[$field];
        }
    }
    return $datas;
}
