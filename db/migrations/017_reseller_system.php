<?php

/**
 * Migration 017 — MODASR reseller system.
 *
 * Adds wholesale/retail pricing policy columns, agent-bot lifecycle columns
 * and the agent_transaction journal (append-only financial log).
 *
 * Rollback: db/migrations/rollback/017_reseller_system.rollback.sql
 * Backward compatible: every new column is nullable or has a safe default,
 * so existing products, agents and bots keep working unchanged.
 */

return static function (PDO $pdo, Schema $schema): void {
    // --- product: wholesale price + retail bounds (NULL = no policy) ---
    foreach ([
        ['wholesale_price', "VARCHAR(50) NULL"],
        ['min_retail_price', "VARCHAR(50) NULL"],
        ['max_retail_price', "VARCHAR(50) NULL"],
    ] as [$column, $type]) {
        if (!$schema->hasColumn('product', $column)) {
            $schema->addColumn('product', $column, null, $type);
        }
    }

    // --- botsaz: agent-bot lifecycle ---
    foreach ([
        ['status', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
        ['platform', "VARCHAR(20) NOT NULL DEFAULT 'telegram'"],
        ['expires_at', "VARCHAR(50) NULL"],
    ] as [$column, $type]) {
        if (!$schema->hasColumn('botsaz', $column)) {
            $schema->addColumn('botsaz', $column, null, $type);
        }
    }

    // --- agent_transaction: append-only financial journal ---
    if (!$schema->tableExists('agent_transaction')) {
        $schema->create('agent_transaction', <<<SQL
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref VARCHAR(64) NOT NULL,
            agent_id VARCHAR(200) NOT NULL,
            type VARCHAR(40) NOT NULL,
            amount BIGINT NOT NULL,
            balance_after BIGINT NULL,
            meta TEXT NULL,
            created_at VARCHAR(50) NOT NULL
            SQL);
    }
    $indexExists = $pdo->query("SHOW INDEX FROM `agent_transaction` WHERE Key_name = 'uq_agent_transaction_ref'")->fetch();
    if (!$indexExists) {
        $pdo->exec("ALTER TABLE `agent_transaction` ADD UNIQUE INDEX `uq_agent_transaction_ref` (`ref`)");
    }
};
