<?php
/** Webhook-secret verification tests (pure logic, no network). */

section('webhook secret');

require_once __DIR__ . '/../function.php';

// webhookSecretMatches: constant-time compare of the ?secret= query param.
$secret = 'abcdef1234567890abcdef1234567890abcdef1234567890';

$_GET['secret'] = $secret;
ok(webhookSecretMatches($secret) === true, 'correct secret accepted');

$_GET['secret'] = 'wrong-secret';
ok(webhookSecretMatches($secret) === false, 'wrong secret rejected');

unset($_GET['secret']);
ok(webhookSecretMatches($secret) === false, 'missing secret rejected');

$_GET['secret'] = '';
ok(webhookSecretMatches($secret) === false, 'empty secret rejected');

// migrateWebhookSecretForPlatform: the telegram() stub (defined in
// channel_platform_test.php) reports success, so the first migration call
// must succeed and write the flag; the second call must be refused by the
// flag guard. A pre-existing production flag is backed up and restored so
// tests never weaken live webhook enforcement.
$_GET['platform'] = 'bale';
$flag = __DIR__ . '/../storage/cache/webhook_secret_migrated_bale';
$flagBackup = is_file($flag) ? file_get_contents($flag) : null;
@unlink($flag);
$first = migrateWebhookSecretForPlatform('bale', $secret);
ok($first === true && is_file($flag), 'first migration re-registers webhook and writes flag');
$second = migrateWebhookSecretForPlatform('bale', $secret);
ok($second === false, 'second migration refused (grace usable only once)');
@unlink($flag);
if ($flagBackup !== null) {
    file_put_contents($flag, $flagBackup);
}
ok($flagBackup === null ? !is_file($flag) : is_file($flag), 'production migration flag restored after test');
unset($_GET['platform']);
