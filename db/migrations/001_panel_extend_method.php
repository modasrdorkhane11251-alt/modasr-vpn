<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('marzban_panel')) {
        return;
    }
    $rows = $pdo->query('SELECT id, Methodextend FROM marzban_panel')->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE marzban_panel SET Methodextend = ? WHERE id = ?');
    foreach ($rows as $row) {
        $key = extendMethodKey($row['Methodextend'], null);
        if ($key === null || $key === $row['Methodextend']) {
            continue;
        }
        $update->execute([$key, $row['id']]);
    }
};
