<?php

return static function (PDO $pdo, Schema $schema): void {
    $statusColumns = [
        'wheelـluck' => ['button' => 'text_wheel_luck', 'off' => '0'],
        'affiliatesstatus' => ['button' => 'text_affiliates', 'off' => 'offaffiliates'],
    ];
    $statusColumns = array_filter($statusColumns, fn($column) => $schema->hasColumn('setting', $column), ARRAY_FILTER_USE_KEY);
    if (!$statusColumns) {
        return;
    }
    $row = $pdo->query("SELECT * FROM `setting` LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $layout = json_decode($row['keyboardmain'] ?? '', true);
    if ($row && is_array($layout['keyboard'] ?? null)) {
        $disabledButtons = [];
        foreach ($statusColumns as $column => $status) {
            if ((string) $row[$column] === $status['off']) {
                $disabledButtons[] = $status['button'];
            }
        }
        if ($disabledButtons) {
            foreach ($layout['keyboard'] as $rowIndex => $kbRow) {
                $layout['keyboard'][$rowIndex] = array_values(array_filter((array) $kbRow, fn($button) => !in_array($button['text'] ?? null, $disabledButtons, true)));
            }
            $layout['keyboard'] = array_values(array_filter($layout['keyboard']));
            $statement = $pdo->prepare("UPDATE `setting` SET keyboardmain = ?");
            $statement->execute([json_encode($layout, JSON_UNESCAPED_UNICODE)]);
        }
    }
    foreach (array_keys($statusColumns) as $column) {
        $schema->dropColumn('setting', $column);
    }
};
