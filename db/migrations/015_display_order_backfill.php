<?php

return static function (PDO $pdo, Schema $schema): void {
    foreach (['product', 'marzban_panel', 'category'] as $table) {
        if (!$schema->hasColumn($table, 'display_order')) {
            continue;
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM `$table` WHERE display_order != 0")->fetchColumn() === 0) {
            $pdo->exec("UPDATE `$table` SET display_order = id");
        }
    }
};
