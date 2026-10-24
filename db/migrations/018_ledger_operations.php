<?php

/**
 * Migration 018 — MODASR ledger operations (Phase E).
 *
 * Adds the reseller_operation table: one row per purchase/extend operation
 * of an agent (wholesale) bot, tracking the full lifecycle
 * started -> charged -> delivering -> succeeded
 *                         |-> refund_pending -> refunded
 *                         |-> unknown  (unverifiable, no auto-refund)
 *                         +-> failed   (definitive failure BEFORE any charge)
 *
 * Rollback: db/migrations/rollback/018_ledger_operations.rollback.sql
 * Backward compatible: new table only, no changes to existing tables.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('reseller_operation')) {
        $schema->create('reseller_operation', <<<SQL
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            op_id VARCHAR(64) NOT NULL,
            type VARCHAR(20) NOT NULL,
            agent_id VARCHAR(200) NOT NULL,
            bot_id INT NULL,
            buyer_id VARCHAR(200) NULL,
            invoice_id VARCHAR(200) NULL,
            panel_name VARCHAR(200) NULL,
            panel_username VARCHAR(200) NULL,
            amount BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'started',
            expected_data_limit BIGINT NULL,
            expected_expire BIGINT NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            meta TEXT NULL,
            created_at VARCHAR(50) NOT NULL,
            updated_at VARCHAR(50) NOT NULL
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `reseller_operation` WHERE Key_name = 'uq_reseller_operation_op_id'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `reseller_operation` ADD UNIQUE INDEX `uq_reseller_operation_op_id` (`op_id`)");
    }
    foreach ([
        'idx_reseller_operation_status' => '(`status`)',
        'idx_reseller_operation_agent' => '(`agent_id`)',
        'idx_reseller_operation_invoice' => '(`invoice_id`)',
    ] as $name => $cols) {
        $exists = $pdo->query("SHOW INDEX FROM `reseller_operation` WHERE Key_name = '$name'")->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `reseller_operation` ADD INDEX `$name` $cols");
        }
    }
};
