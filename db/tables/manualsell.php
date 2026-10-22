<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        codepanel varchar(100) NOT NULL,
        codeproduct varchar(100) NOT NULL,
        namerecord varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        username varchar(500) NULL,
        contentrecord TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        status varchar(200) NOT NULL
        SQL,
];
