<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(500) NOT NULL,
        username varchar(1000) NOT NULL,
        value varchar(1000) NOT NULL,
        time varchar(200) NOT NULL,
        price varchar(200) NOT NULL,
        type varchar(1000) NOT NULL,
        status varchar(200) NOT NULL,
        output TEXT NOT NULL,
        payable_amount varchar(20) NULL,
        id_payment varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        SQL,
    'columns' => [
        ['price', null, 'VARCHAR(200)'],
        ['status', null, 'VARCHAR(200)'],
        ['output', null, 'TEXT'],
        ['payable_amount', null, 'VARCHAR(20) NULL'],
        ['id_payment', null, 'VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'],
    ],
];
