<?php

return [
    'create' => <<<SQL
        remark varchar(200) NOT NULL,
        linkjoin varchar(200) NOT NULL,
        link varchar(200) NOT NULL,
        platform varchar(20) NOT NULL DEFAULT 'telegram'
        SQL,
    'columns' => [
        ['remark', null, 'VARCHAR(200)'],
        ['linkjoin', null, 'VARCHAR(200)'],
        ['platform', 'telegram', 'VARCHAR(20)'],
    ],
];
