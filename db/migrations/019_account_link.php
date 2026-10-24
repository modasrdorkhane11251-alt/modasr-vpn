<?php

/**
 * Migration 019 — MODASR account linking (cross-platform identity).
 *
 * A customer may use the bot on several messengers (telegram / bale /
 * rubika / splus). Each messenger creates its own `user` row — and its own
 * wallet. This migration adds the tables that tie those rows to ONE
 * primary account:
 *
 *   user_link          — linked_user_id (a platform-specific user id) is
 *                        attached to primary_user_id; a user id can be
 *                        linked to at most one primary (UNIQUE).
 *   account_link_code  — one-time 6-digit codes (5 min TTL) that authorise
 *                        a link; issued on the primary account, redeemed on
 *                        the secondary one.
 *   account_link_attempt — audit + rate-limit log of redeem attempts.
 *
 * Rollback: db/migrations/rollback/019_account_link.rollback.sql
 * Backward compatible: new tables only, no changes to existing tables.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('user_link')) {
        $schema->create('user_link', <<<SQL
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            primary_user_id VARCHAR(200) NOT NULL,
            linked_user_id VARCHAR(200) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            linked_via VARCHAR(20) NOT NULL DEFAULT 'code',
            created_at VARCHAR(50) NOT NULL
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `user_link` WHERE Key_name = 'uq_user_link_linked'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `user_link` ADD UNIQUE KEY `uq_user_link_linked` (`linked_user_id`)");
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `user_link` WHERE Key_name = 'ix_user_link_primary'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `user_link` ADD KEY `ix_user_link_primary` (`primary_user_id`)");
    }

    if (!$schema->tableExists('account_link_code')) {
        $schema->create('account_link_code', <<<SQL
            code VARCHAR(12) NOT NULL PRIMARY KEY,
            primary_user_id VARCHAR(200) NOT NULL,
            expires_at BIGINT NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            used_by VARCHAR(200) NULL,
            created_at VARCHAR(50) NOT NULL
            SQL);
    }

    if (!$schema->tableExists('account_link_attempt')) {
        $schema->create('account_link_attempt', <<<SQL
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(200) NOT NULL,
            code_tried VARCHAR(12) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at VARCHAR(50) NOT NULL
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `account_link_attempt` WHERE Key_name = 'ix_link_attempt_user'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `account_link_attempt` ADD KEY `ix_link_attempt_user` (`user_id`)");
    }
};
