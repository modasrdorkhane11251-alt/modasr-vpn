<?php

return [
    'create' => <<<SQL
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_user varchar(200) NOT NULL,
        time varchar(200) NOT NULL,
        first_name varchar(200) NOT NULL,
        wheel_code varchar(200) NOT NULL,
        price varchar(200) NOT NULL
        SQL,
];
