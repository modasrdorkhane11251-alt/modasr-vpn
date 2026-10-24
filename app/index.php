<?php

/**
 * MODASR mini-app entry.
 *
 * Native webview platforms (telegram/bale/splus) authenticate inside the
 * bundle via signed initData -> /api/verify. For platforms without signed
 * initData (rubika, plain browsers) the bot hands out a one-time magic
 * link /app/?auth_token=... — exchanged HERE, server-side, for a session
 * that is injected into localStorage before the bundle boots. The bundle
 * then finds a valid session and skips the initData flow entirely.
 */

$authToken = isset($_GET['auth_token']) && is_string($_GET['auth_token']) ? trim($_GET['auth_token']) : '';
$persistentKey = isset($_GET['key']) && is_string($_GET['key']) ? trim($_GET['key']) : '';
$injectedSession = null;

if ($authToken !== '' || $persistentKey !== '') {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../function.php';

    // Two entry modes: one-time auth_token (bot magic link / Google bounce)
    // or the persistent per-account key (survives across visits).
    $platformUser = null;
    $entryPlatform = 'web';
    if ($authToken !== '') {
        $auth = miniapp_consume_auth_token($authToken);
        if ($auth !== null) {
            $platformUser = select('user', '*', 'id', (string) $auth['user_id'], 'select');
            $entryPlatform = (string) $auth['platform'];
        }
    } else {
        $keyUser = miniapp_resolve_key($persistentKey);
        if ($keyUser) {
            $platformUser = $keyUser;
            $entryPlatform = (string) ($keyUser['platform'] ?? 'web');
        }
    }
    if ($platformUser && $platformUser['User_Status'] !== 'block') {
        $primaryId = modasr_primary_user_id((string) $platformUser['id']);
        $primary = select('user', '*', 'id', $primaryId, 'select') ?: $platformUser;
        try {
            $sessionToken = miniapp_issue_session((string) $platformUser['id'], $entryPlatform);
            $displayName = miniapp_display_name($primary);
            $sessionPayload = [
                    'user' => [
                        'id' => is_numeric($primary['id']) ? (int) $primary['id'] : $primary['id'],
                        'first_name' => $displayName !== '' ? $displayName : 'کاربر',
                        'username' => (string) ($primary['username'] ?? ''),
                    ],
                    'token' => $sessionToken,
                    'expiresAt' => (time() + MODASR_MINIAPP_SESSION_TTL) * 1000,
                ];
                // Same passphrase the shipped bundle uses for localStorage.
                $injectedSession = miniapp_cryptojs_encrypt(
                    json_encode($sessionPayload, JSON_UNESCAPED_UNICODE),
                    'n8lE/qNXK7YC64+QH7BQb0IRN0WGm3kbaRgey1xsBp0='
                );
            } catch (Throwable $e) {
                error_log('miniapp auth exchange failed: ' . $e->getMessage());
            }
    }
    // Invalid/expired/consumed credential: fall through and render the app;
    // the bundle will show its regular login state.
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MODASR BOT — Mini App</title>
    <script src="./js/telegram-web-app.js"></script>
    <script src="./js/linking.js?v=0.3.0"></script>
<?php if ($injectedSession !== null): ?>
    <script>
    (function () {
      try {
        localStorage.setItem("userData", <?php echo json_encode($injectedSession); ?>);
      } catch (e) {}
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete("auth_token");
        window.history.replaceState(null, "", url.pathname + url.search + url.hash);
      } catch (e) {}
    })();
    </script>
<?php endif; ?>
    <script type="module" crossorigin src="./assets/index-C-2a0Dur.js"></script>
    <link rel="modulepreload" crossorigin href="./assets/vendor-CIGJ9g2q.js">
    <link rel="stylesheet" crossorigin href="./assets/index-BoHBsj0Z.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
