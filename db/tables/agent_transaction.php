<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'create' => <<<SQL
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ref VARCHAR(64) NOT NULL,
        agent_id VARCHAR(200) NOT NULL,
        type VARCHAR(40) NOT NULL,
        amount BIGINT NOT NULL,
        balance_after BIGINT NULL,
        meta TEXT NULL,
        created_at VARCHAR(50) NOT NULL
        SQL,
];
