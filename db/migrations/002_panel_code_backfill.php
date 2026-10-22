<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('marzban_panel')) {
        return;
    }
    $missing = $pdo
        ->query("SELECT id FROM marzban_panel WHERE code_panel IS NULL OR code_panel = ''")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!$missing) {
        return;
    }
    $highest = $pdo
        ->query("SELECT MAX(CAST(SUBSTRING(code_panel, 3) AS UNSIGNED)) FROM marzban_panel WHERE code_panel LIKE '7e%'")
        ->fetchColumn();
    $next = $highest ? (int) $highest + 1 : 15;
    $update = $pdo->prepare('UPDATE marzban_panel SET code_panel = ? WHERE id = ?');
    foreach ($missing as $id) {
        $update->execute(['7e' . $next, $id]);
        $next++;
    }
};
