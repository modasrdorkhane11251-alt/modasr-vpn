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
// flag guard. The flag file is removed afterwards so production state is
// never affected by tests.
$_GET['platform'] = 'bale';
$flag = __DIR__ . '/../storage/cache/webhook_secret_migrated_bale';
@unlink($flag);
$first = migrateWebhookSecretForPlatform('bale', $secret);
ok($first === true && is_file($flag), 'first migration re-registers webhook and writes flag');
$second = migrateWebhookSecretForPlatform('bale', $secret);
ok($second === false, 'second migration refused (grace usable only once)');
@unlink($flag);
ok(!is_file($flag), 'test cleaned up the migration flag');
unset($_GET['platform']);
