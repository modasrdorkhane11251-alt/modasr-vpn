<?php

return static function (PDO $pdo, Schema $schema): void {
    $statements = [
        'invoice' => [
            'ALTER TABLE `invoice` CHANGE `Volume` `Volume` VARCHAR(200)',
            'ALTER TABLE `invoice` CHANGE `price_product` `price_product` VARCHAR(200)',
            'ALTER TABLE `invoice` CHANGE `name_product` `name_product` VARCHAR(200)',
            'ALTER TABLE `invoice` CHANGE `username` `username` VARCHAR(200)',
            'ALTER TABLE `invoice` CHANGE `Service_location` `Service_location` VARCHAR(200)',
            'ALTER TABLE `invoice` CHANGE `time_sell` `time_sell` VARCHAR(200)',
        ],
        'marzban_panel' => [
            'ALTER TABLE `marzban_panel` MODIFY password_panel TEXT COLLATE utf8mb4_bin',
            'ALTER TABLE `marzban_panel` MODIFY name_panel VARCHAR(255) COLLATE utf8mb4_bin',
        ],
        'product' => [
            'ALTER TABLE `product` MODIFY name_product VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
        ],
        'help' => [
            'ALTER TABLE `help` MODIFY name_os VARCHAR(500) COLLATE utf8mb4_bin',
        ],
    ];

    foreach ($statements as $table => $queries) {
        if (!$schema->tableExists($table)) {
            continue;
        }
        foreach ($queries as $query) {
            try {
                $pdo->exec($query);
            } catch (Throwable $e) {
                error_log("[db:migration:column_types:$table] " . $e->getMessage());
            }
        }
    }
};
