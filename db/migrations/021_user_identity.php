<?php

/**
 * Migration 021 — MODASR identity/profile columns.
 *
 *   display_name — the user's real display name captured from messenger
 *                  updates (first_name). Powers the mini-app profile,
 *                  which previously showed "none" for non-Telegram users.
 *   miniapp_key  — persistent mini-app login key (/app/?key=...). One per
 *                  account, regeneratable (regeneration revokes the old).
 *   google_sub / google_email — Google account binding for web login
 *                  (activated once Google OAuth credentials are configured).
 *
 * Rollback: db/migrations/rollback/021_user_identity.rollback.sql
 * Backward compatible: new nullable columns only.
 */

return static function (PDO $pdo, Schema $schema): void {
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `user`")->fetchAll() as $col) {
        $columns[] = $col['Field'];
    }
    if (!in_array('display_name', $columns, true)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `display_name` VARCHAR(200) NULL DEFAULT NULL AFTER `namecustom`");
    }
    if (!in_array('miniapp_key', $columns, true)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `miniapp_key` VARCHAR(64) NULL DEFAULT NULL AFTER `display_name`");
    }
    if (!in_array('google_sub', $columns, true)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `google_sub` VARCHAR(64) NULL DEFAULT NULL AFTER `miniapp_key`");
    }
    if (!in_array('google_email', $columns, true)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `google_email` VARCHAR(200) NULL DEFAULT NULL AFTER `google_sub`");
    }

    $indexExists = $pdo->query("SHOW INDEX FROM `user` WHERE Key_name = 'uq_user_miniapp_key'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `user` ADD UNIQUE KEY `uq_user_miniapp_key` (`miniapp_key`)");
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `user` WHERE Key_name = 'ix_user_google_sub'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `user` ADD KEY `ix_user_google_sub` (`google_sub`)");
    }
};
