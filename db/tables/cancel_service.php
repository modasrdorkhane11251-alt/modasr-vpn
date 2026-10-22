<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(500) NOT NULL,
        username varchar(1000) NOT NULL,
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status varchar(1000) NOT NULL
        SQL,
];
