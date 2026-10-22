<?php

$textbotlang = $schema->context('textbotlang');

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        idsupport VARCHAR(200) NOT NULL,
        name_departman VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        SQL,
    'seedOnCreate' => [
        [
            'idsupport' => $schema->context('adminnumber'),
            'name_departman' => $textbotlang['db_defaults']['departmanGeneral'],
        ],
    ],
];
