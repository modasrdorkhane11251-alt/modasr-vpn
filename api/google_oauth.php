<?php

/**
 * MODASR Google OAuth — web login + account binding.
 *
 *   ?mode=login           Start Google login. On callback, if the Google
 *                         account is bound to a MODASR account, the user is
 *                         bounced into /app/ with a fresh session.
 *   ?mode=link&state=T    Bind Google to the account that owns one-time
 *                         state token T (issued by the mini-app account hub).
 *   ?code=...&state=...   OAuth callback (both modes).
 *
 * Inactive until GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are configured.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';

date_default_timezone_set('Asia/Tehran');

function goauth_page(string $title, string $message, string $color = '#e5e7eb'): void
{
    http_response_code(200);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#0f0f14;font-family:Tahoma,sans-serif;color:' . $color . ';}'
        . '.card{background:#1c1c22;border-radius:18px;padding:34px 30px;max-width:420px;text-align:center;'
        . 'box-shadow:0 12px 40px rgba(0,0,0,.45);}h2{margin:0 0 12px;font-size:19px;color:#fff;}'
        . 'p{font-size:14px;line-height:2;color:#9ca3af;margin:0;}</style></head>'
        . '<body><div class="card"><h2>' . htmlspecialchars($title) . '</h2><p>' . $message . '</p></div></body></html>';
    exit;
}

$clientId = $GLOBALS['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $GLOBALS['GOOGLE_CLIENT_SECRET'] ?? '';
if ($clientId === '' || $clientSecret === '') {
    goauth_page('ورود با گوگل', 'ورود با گوگل هنوز فعال نشده است. لطفاً از طریق پیام‌رسان خود (تلگرام / بله / سروش / روبیکا) وارد مینی‌اپ شوید.');
}

$domain = (string) ($domainhosts ?? 'bot.modasr.site');
$redirectUri = 'https://' . $domain . '/api/google_oauth.php';
$hmacSecret = (string) ($PLATFORM_TOKENS['telegram'] ?? $clientSecret);

$mode = (string) ($_GET['mode'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

/* ------------------------- start: redirect to Google ------------------------ */
if ($mode !== '' && $code === '') {
    if ($mode === 'login') {
        $nonce = bin2hex(random_bytes(8));
        $stateOut = 'login.' . $nonce . '.' . hash_hmac('sha256', 'login.' . $nonce, $hmacSecret);
    } elseif ($mode === 'link') {
        // state is the one-time account token issued by the mini-app hub.
        if (!preg_match('/^[a-f0-9]{40}$/', $state)) {
            goauth_page('خطا', 'لینک اتصال نامعتبر است. از مینی‌اپ دوباره تلاش کنید.');
        }
        $stateOut = 'link.' . $state;
    } else {
        goauth_page('خطا', 'درخواست نامعتبر است.');
    }
    $params = [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $stateOut,
        'prompt' => 'select_account',
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

/* ------------------------------- callback ---------------------------------- */
if ($code === '' || $state === '') {
    goauth_page('خطا', 'پاسخ گوگل ناقص بود. دوباره تلاش کنید.');
}

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$tokenResp = json_decode((string) curl_exec($ch), true);
$idToken = (string) ($tokenResp['id_token'] ?? '');
if ($idToken === '') {
    goauth_page('خطا', 'دریافت توکن از گوگل ناموفق بود. دوباره تلاش کنید.');
}

$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$claims = json_decode((string) curl_exec($ch), true);
$googleSub = (string) ($claims['sub'] ?? '');
$googleEmail = (string) ($claims['email'] ?? '');
if ($googleSub === '' || ($claims['aud'] ?? '') !== $clientId) {
    goauth_page('خطا', 'اعتبارسنجی توکن گوگل ناموفق بود.');
}

if (str_starts_with($state, 'link.')) {
    /* ---- bind Google to the account that owns the state token ---- */
    $auth = miniapp_consume_auth_token(substr($state, 5));
    if ($auth === null) {
        goauth_page('خطا', 'لینک اتصال منقضی یا استفاده شده است. از مینی‌اپ دوباره تلاش کنید.');
    }
    $primaryId = modasr_primary_user_id((string) $auth['user_id']);
    // one Google account can be bound to only one MODASR account
    $stmt = $pdo->prepare("SELECT id FROM user WHERE google_sub = ? AND id != ? LIMIT 1");
    $stmt->execute([$googleSub, $primaryId]);
    if ($stmt->fetch()) {
        goauth_page('خطا', 'این حساب گوگل قبلاً به یک حساب دیگر متصل شده است.');
    }
    $stmt = $pdo->prepare("UPDATE user SET google_sub = ?, google_email = ? WHERE id = ?");
    $stmt->execute([$googleSub, $googleEmail, $primaryId]);
    clearSelectCache('user');
    goauth_page('✅ گوگل متصل شد', 'حساب گوگل شما با موفقیت متصل شد. حالا می‌توانید از صفحه‌ی ورود سایت با گوگل وارد شوید. این صفحه را ببندید.', '#34d399');
}

if (str_starts_with($state, 'login.')) {
    /* ---- stateless CSRF check ---- */
    $parts = explode('.', $state);
    if (count($parts) !== 3 || !hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $hmacSecret), $parts[2])) {
        goauth_page('خطا', 'اعتبارسنجی نشست ناموفق بود. دوباره تلاش کنید.');
    }
    $stmt = $pdo->prepare("SELECT id FROM user WHERE google_sub = ? LIMIT 1");
    $stmt->execute([$googleSub]);
    $userId = $stmt->fetchColumn();
    if (!$userId) {
        goauth_page('حسابی پیدا نشد', 'این حساب گوگل به هیچ حساب MODASR متصل نیست.<br><br>ابتدا از طریق پیام‌رسان خود وارد مینی‌اپ شوید، سپس از بخش «حساب» گوگل را متصل کنید.');
    }
    $bounce = miniapp_issue_auth_token((string) $userId, 'google');
    if ($bounce === null) {
        goauth_page('خطا', 'تعداد نشست‌های فعال زیاد است. چند دقیقه دیگر تلاش کنید.');
    }
    header('Location: https://' . $domain . '/app/?auth_token=' . $bounce);
    exit;
}

goauth_page('خطا', 'درخواست نامعتبر است.');
