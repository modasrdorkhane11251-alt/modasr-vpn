<?php

return [
    'options' => 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'create' => <<<SQL
        cardnumber varchar(500) PRIMARY KEY,
        namecard varchar(1000) NOT NULL
        SQL,
];
