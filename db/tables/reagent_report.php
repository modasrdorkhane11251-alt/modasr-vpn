<?php

return [
    'options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin',
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNIQUE NOT NULL,
        get_gift BOOL NOT NULL,
        time varchar(50) NOT NULL,
        reagent varchar(30) NOT NULL
        SQL,
];
