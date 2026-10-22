<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->hasColumn('product', 'status_product')) {
        return;
    }
    $pdo->exec("UPDATE product SET status_product = 'active' WHERE status_product IS NULL OR status_product = ''");
    $column = $pdo->query("SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'status_product'")->fetch(PDO::FETCH_ASSOC);
    if ($column && ($column['IS_NULLABLE'] !== 'NO' || trim((string) $column['COLUMN_DEFAULT'], "'") !== 'active')) {
        $pdo->exec("ALTER TABLE product MODIFY status_product VARCHAR(20) NOT NULL DEFAULT 'active'");
    }
};
