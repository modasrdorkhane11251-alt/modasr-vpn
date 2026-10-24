<?php

/**
 * MODASR mini-app authentication (Phase 2 — cross-platform).
 *
 * Three ways a user reaches the mini-app:
 *
 *   1. Native web-app button (telegram / bale / splus): the messenger
 *      webview injects Telegram-style initData signed with THAT platform's
 *      bot token. miniapp_validate_init_data() verifies the signature and
 *      tells us which platform the user came from.
 *   2. Magic link (any browser / rubika / fallback): the bot issues a
 *      one-time short-lived `miniapp_auth_token`; app/index.php exchanges
 *      it server-side for a session and injects it into the page.
 *   3. Legacy: an already-issued `user.token` Bearer token (kept working).
 *
 * Sessions live in `miniapp_session` so a customer can be logged in on
 * several devices/platforms at once without the sessions kicking each
 * other out. Account linking is honoured: sessions are always issued on
 * the PRIMARY account so the mini-app shows the shared wallet/services.
 */

require_once __DIR__ . '/account_link.php';

const MODASR_MINIAPP_SESSION_TTL = 604800;      // 7 days
const MODASR_MINIAPP_AUTH_TTL = 600;            // magic link: 10 minutes
const MODASR_MINIAPP_MAX_ACTIVE_AUTH = 5;       // per user

/**
 * Verify Telegram-style initData against a specific bot token.
 * Returns the decoded user payload ['id'=>..., 'first_name'=>..., ...].
 * Throws InvalidArgumentException on malformed input and RuntimeException
 * on signature/expiry problems — callers distinguish "not for this token"
 * (RuntimeException 'User verification failed') from real breakage.
 */
function miniapp_validate_init_data($rawData, string $botToken): array
{
    if (is_string($rawData)) {
        $rawData = trim($rawData);
        if ($rawData === '') {
            throw new InvalidArgumentException('init data is missing');
        }
        $decodedQuery = html_entity_decode($rawData, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        parse_str($decodedQuery, $initData);
    } elseif (is_array($rawData)) {
        $initData = $rawData;
    } else {
        throw new InvalidArgumentException('init data is missing');
    }

    if (!is_array($initData) || $initData === [] || !isset($initData['hash'])) {
        throw new InvalidArgumentException('init data payload is empty or unsigned');
    }

    $receivedHash = (string) $initData['hash'];
    unset($initData['hash']);

    $dataCheckArray = [];
    foreach ($initData as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $stringValue = (string) $value;
        if ($stringValue === '') {
            continue;
        }
        $dataCheckArray[] = $key . '=' . $stringValue;
    }
    if ($dataCheckArray === []) {
        throw new InvalidArgumentException('init data payload is empty');
    }
    sort($dataCheckArray, SORT_STRING);
    $dataCheckString = implode("\n", $dataCheckArray);

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    if (!hash_equals($calculatedHash, $receivedHash)) {
        throw new RuntimeException('User verification failed');
    }

    $authDate = isset($initData['auth_date']) ? (int) $initData['auth_date'] : 0;
    if ($authDate <= 0) {
        throw new RuntimeException('init data is missing auth_date');
    }
    if (time() - $authDate > 86400) {
        throw new RuntimeException('init data has expired');
    }

    $userRaw = $initData['user'] ?? null;
    if (is_string($userRaw)) {
        $userData = json_decode($userRaw, true);
    } elseif (is_array($userRaw)) {
        $userData = $userRaw;
    } else {
        $userData = null;
    }
    if (!is_array($userData) || !isset($userData['id']) || (string) $userData['id'] === '') {
        throw new RuntimeException('User data is missing or malformed in init data');
    }

    return $userData;
}

/**
 * Try every configured platform token and return
 * ['platform' => ..., 'user' => [...]] for the one whose signature matches.
 */
function miniapp_identify_platform($rawData): ?array
{
    global $PLATFORM_TOKENS, $APIKEY;

    $candidates = [];
    if (isset($PLATFORM_TOKENS) && is_array($PLATFORM_TOKENS)) {
        foreach ($PLATFORM_TOKENS as $platform => $token) {
            if (is_string($token) && $token !== '') {
                $candidates[$platform] = $token;
            }
        }
    }
    // Legacy: $APIKEY historically carried the active platform token.
    if (isset($APIKEY) && is_string($APIKEY) && $APIKEY !== '' && !in_array($APIKEY, $candidates, true)) {
        $candidates['legacy'] = $APIKEY;
    }

    foreach ($candidates as $platform => $token) {
        try {
            $userData = miniapp_validate_init_data($rawData, $token);
            if ($platform === 'legacy') {
                $platform = modasr_platform();
            }
            return ['platform' => $platform, 'user' => $userData];
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

/**
 * Create a mini-app session for the PRIMARY account of $userId.
 * Also refreshes user.token for backward compatibility with older
 * mini-app builds that only know the legacy Bearer token.
 */
function miniapp_issue_session(string $userId, string $platform, int $ttl = MODASR_MINIAPP_SESSION_TTL): string
{
    global $pdo;
    $primaryId = modasr_primary_user_id($userId);
    $token = bin2hex(random_bytes(20));
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO miniapp_session (token, user_id, platform, created_at, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$token, $primaryId, $platform, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + $ttl)]);

    // Best-effort legacy compatibility; never fail the login over it.
    try {
        $legacy = $pdo->prepare("UPDATE user SET token = ? WHERE id = ?");
        $legacy->execute([$token, $primaryId]);
    } catch (Throwable $e) {
        error_log('miniapp_issue_session legacy token sync failed: ' . $e->getMessage());
    }
    return $token;
}

/**
 * Resolve a Bearer token to its account row.
 * Prefers miniapp_session (multi-session aware); falls back to the legacy
 * user.token column so sessions issued before this change keep working.
 * Returns the `user` table row of the (primary) account, or null.
 */
function miniapp_resolve_session(string $token): ?array
{
    global $pdo;
    if ($token === '' || strlen($token) > 128) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT user_id, platform FROM miniapp_session WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        if ($session) {
            $user = select('user', '*', 'id', (string) $session['user_id'], 'select');
            if ($user) {
                $user['__session_platform'] = (string) $session['platform'];
                return $user;
            }
        }
    } catch (Throwable $e) {
        // Table may not exist yet on a partially migrated install — fall through.
    }
    $legacy = select('user', '*', 'token', $token, 'select');
    if ($legacy && is_string($legacy['token']) && hash_equals($legacy['token'], $token)) {
        $legacy['__session_platform'] = (string) ($legacy['platform'] ?? 'telegram');
        return $legacy;
    }
    return null;
}

/**
 * Issue a one-time magic-link token for /app/?auth_token=...
 * Returns null when the user has too many active tokens (rate limit).
 */
function miniapp_issue_auth_token(string $userId, string $platform): ?string
{
    global $pdo;
    miniapp_purge_expired();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM miniapp_auth_token WHERE user_id = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() >= MODASR_MINIAPP_MAX_ACTIVE_AUTH) {
        return null;
    }
    $token = bin2hex(random_bytes(20));
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO miniapp_auth_token (token, user_id, platform, created_at, expires_at, used) VALUES (?, ?, ?, ?, ?, 0)");
    $stmt->execute([$token, $userId, $platform, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + MODASR_MINIAPP_AUTH_TTL)]);
    return $token;
}

/**
 * Atomically consume a magic-link token. Single-use and race-safe:
 * the UPDATE ... WHERE used = 0 acts as the claim; only one request wins.
 * Returns ['user_id'=>..., 'platform'=>...] or null.
 */
function miniapp_consume_auth_token(string $token): ?array
{
    global $pdo;
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare("UPDATE miniapp_auth_token SET used = 1 WHERE token = ? AND used = 0 AND expires_at > NOW()");
    $stmt->execute([$token]);
    if ($stmt->rowCount() !== 1) {
        return null;
    }
    $row = $pdo->prepare("SELECT user_id, platform FROM miniapp_auth_token WHERE token = ? LIMIT 1");
    $row->execute([$token]);
    $found = $row->fetch();
    return $found ?: null;
}

/** Cheap hygiene: drop expired rows (~1% of calls plus every issue). */
function miniapp_purge_expired(): void
{
    global $pdo;
    try {
        $pdo->exec("DELETE FROM miniapp_auth_token WHERE expires_at < NOW() - INTERVAL 1 DAY");
        if (random_int(1, 100) === 1) {
            $pdo->exec("DELETE FROM miniapp_session WHERE expires_at < NOW()");
        }
    } catch (Throwable $e) {
        // Cleanup must never break the request.
    }
}

/**
 * CryptoJS-compatible AES encryption: CryptoJS.AES.encrypt(msg, passphrase)
 * uses OpenSSL EVP_BytesToKey (MD5) with a random 8-byte salt and outputs
 * base64("Salted__" + salt + ciphertext). The mini-app bundle decrypts the
 * session with a hardcoded passphrase, so the server reproduces that exact
 * format to inject pre-authenticated sessions.
 */
function miniapp_cryptojs_encrypt(string $plaintext, string $passphrase): string
{
    $salt = random_bytes(8);
    $keyIv = '';
    $block = '';
    while (strlen($keyIv) < 48) { // 32-byte key + 16-byte IV
        $block = md5($block . $passphrase . $salt, true);
        $keyIv .= $block;
    }
    $key = substr($keyIv, 0, 32);
    $iv = substr($keyIv, 32, 16);
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode('Salted__' . $salt . $ciphertext);
}
