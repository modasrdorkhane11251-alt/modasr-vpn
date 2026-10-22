<?php

return [
    'create' => <<<SQL
        id_admin varchar(500) PRIMARY KEY NOT NULL,
        username varchar(1000) NOT NULL,
        password varchar(1000) NOT NULL,
        rule varchar(500) NOT NULL
        SQL,
    'seedOnCreate' => [
        [
            'id_admin' => $schema->context('adminnumber'),
            'rule' => 'administrator',
            'username' => 'admin',
            'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
        ],
    ],
    'columns' => [
        ['username', null, 'VARCHAR(200)'],
        ['password', null, 'VARCHAR(200)'],
        ['rule', 'administrator', 'VARCHAR(200)'],
    ],
];
