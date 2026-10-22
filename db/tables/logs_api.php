<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        header JSON NULL,
        data JSON NULL,
        ip varchar(200) NOT NULL,
        time varchar(200) NOT NULL,
        actions varchar(200) NOT NULL
        SQL,
];
