<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        Tracking VARCHAR(100) NOT NULL,
        idsupport VARCHAR(100) NOT NULL,
        iduser VARCHAR(100) NOT NULL,
        name_departman VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        result TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        time VARCHAR(200) NOT NULL,
        status ENUM('Answered','Pending','Unseen','Customerresponse','close') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        SQL,
    'columns' => [
        ['result', '0', 'TEXT'],
    ],
];
