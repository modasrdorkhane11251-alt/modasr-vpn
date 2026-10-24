<?php

/**
 * Migration 020 — MODASR mini-app cross-platform authentication.
 *
 *   miniapp_session     — long-lived (7 day) mini-app sessions. One row per
 *                         login, so a customer can stay logged in on
 *                         telegram + splus + a browser at the same time
 *                         (the legacy single user.token column caused
 *                         sessions to kick each other out).
 *   miniapp_auth_token  — one-time magic-link tokens (10 min TTL) that the
 *                         bot hands out so users on platforms WITHOUT
 *                         signed webview initData (rubika, plain browsers)
 *                         can still open the mini-app authenticated.
 *
 * Rollback: db/migrations/rollback/020_miniapp_session.rollback.sql
 * Backward compatible: new tables only, no changes to existing tables.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('miniapp_session')) {
        $schema->create('miniapp_session', <<<SQL
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id VARCHAR(500) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `miniapp_session` WHERE Key_name = 'ix_miniapp_session_user'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `miniapp_session` ADD KEY `ix_miniapp_session_user` (`user_id`(191))");
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `miniapp_session` WHERE Key_name = 'ix_miniapp_session_expires'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `miniapp_session` ADD KEY `ix_miniapp_session_expires` (`expires_at`)");
    }

    if (!$schema->tableExists('miniapp_auth_token')) {
        $schema->create('miniapp_auth_token', <<<SQL
            token VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id VARCHAR(500) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `miniapp_auth_token` WHERE Key_name = 'ix_miniapp_auth_user'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `miniapp_auth_token` ADD KEY `ix_miniapp_auth_user` (`user_id`(191))");
    }
};
