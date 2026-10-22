<?php
/**
 * Modasr multi-platform layer.
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
    $p = isset($platform) ? strtolower(trim((string) $platform)) : 'telegram';
    return in_array($p, ['telegram', 'bale', 'rubika'], true) ? $p : 'telegram';
}

/**
 * Route an outgoing Bot API call through the active platform.
 *
 * @return array{0:bool,1:array} [handled, telegram-style result]
 */
function modasr_platform_request(string $method, array $datas, string $token): array
{
    $p = modasr_platform();
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
