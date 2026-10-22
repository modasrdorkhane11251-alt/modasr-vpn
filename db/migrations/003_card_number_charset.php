<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('card_number')) {
        return;
    }
    $column = $pdo->query("SHOW FULL COLUMNS FROM card_number LIKE 'namecard'")->fetch(PDO::FETCH_ASSOC);
    $collation = $column['Collation'] ?? '';
    if ($collation !== '' && stripos($collation, 'utf8mb4') !== false) {
        return;
    }
    $pdo->exec('ALTER TABLE card_number CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('ALTER TABLE card_number MODIFY cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    $pdo->exec('ALTER TABLE card_number MODIFY namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
};
