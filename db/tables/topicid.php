<?php

$reports = [
    'buyreport',
    'otherservice',
    'paymentreport',
    'otherreport',
    'reporttest',
    'errorreport',
    'porsantreport',
    'reportnight',
    'reportcron',
    'backupfile',
];

$seed = [];
foreach ($reports as $report) {
    $seed[] = ['report' => $report, 'idreport' => '0'];
}

return [
    'create' => <<<SQL
        report varchar(500) PRIMARY KEY NOT NULL,
        idreport TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        SQL,
    'seed' => $seed,
];
