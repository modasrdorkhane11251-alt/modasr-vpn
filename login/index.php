<?php

/**
 * MODASR — web login / landing page.
 *
 * One page to enter the MODASR mini-app from anywhere:
 *   - native deep links into each messenger bot (telegram / bale / splus / rubika)
 *   - Google sign-in (active once GOOGLE_CLIENT_ID is configured)
 *   - explanation of the persistent login link (issued by the bot via /app)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../platform/platform.php';

$domain = (string) ($domainhosts ?? 'bot.modasr.site');

/* Resolve bot usernames per platform (cached 1h — getMe is slow + rate-limited). */
function login_bot_getme(string $platform, string $token)
{
    $base = [
        'telegram' => 'https://api.telegram.org/bot',
        'bale'     => 'https://tapi.bale.ai/bot',
        'splus'    => 'https://api.splus.ir/bot',
        'rubika'   => 'https://botapi.rubika.ir/v3/',
    ][$platform] ?? null;
    if ($base === null) {
        return null;
    }
    $url = $base . $token . '/getMe';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $res = json_decode($raw, true);
    if (!is_array($res)) {
        return null;
    }
    // telegram/bale/splus: {ok:true,result:{username}} — rubika: {status:"OK",data:{username|bot_title}}
    $username = $res['result']['username'] ?? ($res['data']['username'] ?? ($res['data']['bot_title'] ?? null));
    return (is_string($username) && $username !== '') ? ltrim($username, '@') : null;
}

function login_bot_usernames(): array
{
    $cacheFile = __DIR__ . '/../storage/cache/login_bot_usernames.json';
    $names = [];
    if (is_file($cacheFile) && time() - filemtime($cacheFile) < 3600) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $names = $cached;
        }
    }
    global $PLATFORM_TOKENS;
    $changed = false;
    foreach ((array) $PLATFORM_TOKENS as $platform => $token) {
        if (!is_string($token) || $token === '' || isset($names[$platform])) {
            continue;
        }
        $username = login_bot_getme((string) $platform, $token);
        if ($username !== null) {
            $names[$platform] = $username;
            $changed = true;
        }
    }
    if ($changed) {
        @file_put_contents($cacheFile, json_encode($names));
    }
    return $names;
}

$usernames = login_bot_usernames();
// Rubika has no getMe — take the username from config if set.
if (!isset($usernames['rubika']) && !empty($RUBIKA_BOT_USERNAME)) {
    $usernames['rubika'] = ltrim((string) $RUBIKA_BOT_USERNAME, '@');
}

$platforms = [
    'telegram' => ['label' => 'تلگرام', 'icon' => '✈️', 'color' => '#2aabee',
        'url' => isset($usernames['telegram']) ? 'https://t.me/' . $usernames['telegram'] . '?start=app' : null],
    'bale' => ['label' => 'بله', 'icon' => '🟡', 'color' => '#f5b301',
        'url' => isset($usernames['bale']) ? 'https://ble.ir/' . $usernames['bale'] . '?start=app' : null],
    'splus' => ['label' => 'سروش پلاس', 'icon' => '🔵', 'color' => '#4f7cff',
        'url' => isset($usernames['splus']) ? 'https://splus.ir/' . $usernames['splus'] : 'https://splus.ir/MODASRbot'],
    'rubika' => ['label' => 'روبیکا', 'icon' => '🟢', 'color' => '#22c55e',
        'url' => isset($usernames['rubika']) ? 'https://rubika.ir/' . $usernames['rubika'] : null],
];

$googleReady = !empty($GLOBALS['GOOGLE_CLIENT_ID']);
$googleUrl = 'https://' . $domain . '/api/google_oauth.php?mode=login';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MODASR — ورود به حساب</title>
<style>
@font-face{font-family:Vazir;src:url(/app/fonts/Vazir-Medium.woff2) format("woff2");font-weight:500}
@font-face{font-family:Vazir;src:url(/app/fonts/Vazir-Bold.woff2) format("woff2");font-weight:700}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;font-family:Vazir,Tahoma,sans-serif;background:#0b1117;color:#e5e7eb;
display:flex;align-items:center;justify-content:center;padding:24px;overflow-x:hidden}
.orb{position:fixed;border-radius:50%;filter:blur(90px);opacity:.35;pointer-events:none}
.orb1{width:420px;height:420px;background:#35bfae;top:-120px;right:-120px}
.orb2{width:380px;height:380px;background:#7c5cff;bottom:-140px;left:-100px}
.card{position:relative;z-index:1;width:100%;max-width:430px;background:rgba(22,33,44,.72);
backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.07);border-radius:26px;padding:38px 30px;
box-shadow:0 24px 70px rgba(0,0,0,.5);text-align:center}
.logo{width:74px;height:74px;margin:0 auto 16px;border-radius:22px;display:flex;align-items:center;justify-content:center;
background:linear-gradient(135deg,#35bfae,#7c5cff);font-size:34px;box-shadow:0 10px 30px rgba(53,191,174,.35)}
h1{font-size:23px;font-weight:700;color:#fff;margin-bottom:6px}
.sub{font-size:13.5px;color:#9ca3af;line-height:1.9;margin-bottom:26px}
.btn{display:flex;align-items:center;gap:12px;width:100%;border:none;cursor:pointer;text-decoration:none;
border-radius:15px;padding:14px 18px;margin-bottom:11px;font-family:inherit;font-size:15px;font-weight:700;
color:#fff;transition:transform .15s ease, box-shadow .15s ease}
.btn:hover{transform:translateY(-2px)}
.btn .ic{width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:17px}
.btn small{display:block;font-size:11px;font-weight:500;opacity:.85;text-align:right}
.btn .txt{flex:1;text-align:right}
.btn.disabled{background:#26262e !important;color:#6b7280;cursor:default}
.btn.disabled:hover{transform:none}
.tg{background:linear-gradient(135deg,#2aabee,#1d8fd0)}
.bale{background:linear-gradient(135deg,#f5b301,#d99a00)}
.splus{background:linear-gradient(135deg,#4f7cff,#3557d6)}
.rubika{background:linear-gradient(135deg,#22c55e,#149647)}
.google{background:#fff;color:#1f2937}
.divider{display:flex;align-items:center;gap:12px;color:#6b7280;font-size:12px;margin:20px 0}
.divider:before,.divider:after{content:"";flex:1;height:1px;background:rgba(255,255,255,.09)}
.note{font-size:12px;color:#8b94a3;line-height:2;background:rgba(255,255,255,.04);border-radius:13px;padding:12px 14px;margin-top:8px}
.brand{margin-top:22px;font-size:11.5px;color:#5b6472}
</style>
</head>
<body>
<div class="orb orb1"></div><div class="orb orb2"></div>
<div class="card">
  <div class="logo">🤖</div>
  <h1>MODASR BOT</h1>
  <p class="sub">ورود به مینی‌اپ و مدیریت حساب — از هر پلتفرمی که دوست دارید.<br>موجودی و سرویس‌های شما همه‌جا یکی است.</p>

  <a class="btn google" href="<?php echo htmlspecialchars($googleUrl); ?>">
    <span class="ic">🇬</span>
    <span class="txt">ورود با گوگل<small><?php echo $googleReady ? 'سریع و امن' : 'به‌زودی — در حال راه‌اندازی'; ?></small></span>
  </a>

  <div class="divider">یا از طریق پیام‌رسان</div>

  <?php foreach ($platforms as $key => $p): if (!$p['url']) continue; ?>
  <a class="btn <?php echo $key; ?>" href="<?php echo htmlspecialchars($p['url']); ?>">
    <span class="ic"><?php echo $p['icon']; ?></span>
    <span class="txt">ورود با <?php echo $p['label']; ?><small>بات MODASR در <?php echo $p['label']; ?></small></span>
  </a>
  <?php endforeach; ?>

  <div class="note">
    💡 در بات خود دستور <b>/app</b> را بفرستید تا <b>لینک دائمی ورود</b> را بگیرید — با آن بدون پیام‌رسان، مستقیم از مرورگر وارد می‌شوید.
  </div>

  <div class="brand">MODASR BOT — مدثر بات 🤍</div>
</div>
</body>
</html>
