<?php
/** Identity hub tests: display_name, persistent keys, google binding columns. */

section('identity hub');

require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../db/Schema.php';

$migration021 = require __DIR__ . '/../db/migrations/021_user_identity.php';
$migration021($pdo, new Schema($pdo, []));

global $pdo;
$pdo->exec("SET SESSION sql_mode=''");

$IH = 'test_hub_' . substr(md5(uniqid()), 0, 8);
$pdo->prepare("INSERT INTO user (id, platform, step, Balance, agent, username, namecustom) VALUES (?, 'rubika', 'none', 0, 'f', 'NOT_USERNAME', 'none')")->execute([$IH]);
clearSelectCache('user');

// --- display name --------------------------------------------------------------
$row = select('user', '*', 'id', $IH, 'select');
ok(miniapp_display_name($row) === 'کاربر', 'display name: falls back to generic when nothing stored');

$pdo->prepare("UPDATE user SET display_name = 'مدثر' WHERE id = ?")->execute([$IH]);
clearSelectCache('user');
$row = select('user', '*', 'id', $IH, 'select');
ok(miniapp_display_name($row) === 'مدثر', 'display name: prefers display_name column');

$pdo->prepare("UPDATE user SET display_name = NULL, username = 'modasr_user' WHERE id = ?")->execute([$IH]);
clearSelectCache('user');
$row = select('user', '*', 'id', $IH, 'select');
ok(miniapp_display_name($row) === 'modasr_user', 'display name: falls back to username');

// --- persistent keys -------------------------------------------------------------
$key1 = miniapp_get_or_create_key($IH);
ok(preg_match('/^[a-f0-9]{48}$/', $key1) === 1, 'persistent key: 48 hex chars');
$key2 = miniapp_get_or_create_key($IH);
ok($key1 === $key2, 'persistent key: stable across calls');

$resolved = miniapp_resolve_key($key1);
ok($resolved && $resolved['id'] === $IH, 'persistent key: resolves to owner');
ok(miniapp_resolve_key('short') === null, 'persistent key: malformed rejected');
ok(miniapp_resolve_key(str_repeat('f', 48)) === null, 'persistent key: unknown rejected');

$key3 = miniapp_regenerate_key($IH);
ok($key3 !== $key1, 'regenerate: new key differs');
ok(miniapp_resolve_key($key1) === null, 'regenerate: old key revoked');
ok(miniapp_resolve_key($key3) !== null, 'regenerate: new key works');

// session issued from a key still resolves to the primary account
$session = miniapp_issue_session($IH, 'web');
$resolvedSession = miniapp_resolve_session($session);
ok($resolvedSession && $resolvedSession['id'] === $IH, 'session from web entry resolves account');
$pdo->prepare("DELETE FROM miniapp_session WHERE token = ?")->execute([$session]);

// --- google columns -----------------------------------------------------------------
$pdo->prepare("UPDATE user SET google_sub = 'g123', google_email = 'a@b.c' WHERE id = ?")->execute([$IH]);
clearSelectCache('user');
$row = select('user', '*', 'id', $IH, 'select');
ok($row['google_sub'] === 'g123' && $row['google_email'] === 'a@b.c', 'google columns writable');

// cleanup
$pdo->prepare("DELETE FROM user WHERE id = ?")->execute([$IH]);
clearSelectCache('user');
