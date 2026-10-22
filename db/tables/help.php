<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name_os varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        Media_os varchar(5000) NOT NULL,
        type_Media_os varchar(500) NOT NULL,
        category TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        Description_os TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        SQL,
    'columns' => [
        ['category', null, 'TEXT'],
    ],
];
