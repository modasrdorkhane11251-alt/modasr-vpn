<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
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
        SQL,
];
