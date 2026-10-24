<?php
/**
 * Soroush Plus (سروش پلاس) bot API client.
 *
 * The Soroush Plus Bot API is Telegram-wire-compatible: same method names,
 * same parameter names, same ok/result envelope. The transport-level
 * differences from Telegram are the base URL (https://api.splus.ir/bot<token>/)
 * and a smaller feature surface (no ForceReply, no sendInvoice/payments,
 * no secret_token webhook parameter — an unguessable webhook path is the
 * recommended substitute, which our existing ?secret= mechanism provides).
 *
 * Docs: https://soroushplus.com/p/documents
 *
 * The adapter mirrors modasr_bale_request() exactly: form-encoded POST,
 * custom-emoji stripping, ok/error_code/description result shape.
 */

/**
 * Fire one Soroush Plus Bot API call and return the decoded response.
 * On transport or decode failure an ['ok' => false, ...] array is returned,
 * matching how the codebase treats failed Telegram/Bale calls.
 */
function modasr_splus_request(string $method, array $datas, string $token): array
{
    if ($token === '') {
        return ['ok' => false, 'error_code' => 0, 'description' => 'Soroush Plus token not configured.'];
    }
    // Soroush Plus (like Bale) does not support Telegram custom emoji payloads.
    if (function_exists('payloadHasCustomEmoji') && payloadHasCustomEmoji($datas)) {
        $datas = stripCustomEmojiPayload($datas);
    }

    $url = 'https://api.splus.ir/bot' . $token . '/' . $method;

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'description' => 'Unable to initialise cURL for Soroush Plus request.'];
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'description' => $err !== '' ? $err : 'Soroush Plus request failed.'];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error_code' => $code, 'description' => 'Invalid response received from Soroush Plus.'];
    }
    return $decoded;
}
