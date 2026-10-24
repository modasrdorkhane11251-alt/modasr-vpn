<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(200) NOT NULL,
        bot_token varchar(200) NOT NULL,
        admin_ids TEXT NOT NULL,
        username varchar(200) NOT NULL,
        setting TEXT NULL,
        hide_panel JSON NOT NULL,
        webhook_secret varchar(200) NOT NULL DEFAULT '',
        time varchar(200) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        platform varchar(20) NOT NULL DEFAULT 'telegram',
        expires_at varchar(50) NULL
        SQL,
    'columns' => [
        ['hide_panel', '{}', 'JSON'],
        ['webhook_secret', null, "VARCHAR(200) NOT NULL DEFAULT ''"],
        ['status', 'active', "VARCHAR(20) NOT NULL DEFAULT 'active'"],
        ['platform', 'telegram', "VARCHAR(20) NOT NULL DEFAULT 'telegram'"],
        ['expires_at', null, 'VARCHAR(50) NULL'],
    ],
];
