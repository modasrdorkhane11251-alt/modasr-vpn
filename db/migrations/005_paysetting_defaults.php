<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('PaySetting')) {
        return;
    }
    $pdo->exec("DELETE FROM PaySetting WHERE NamePay = 'urlpaymenttron'");

    $current = select('PaySetting', 'ValuePay', 'NamePay', 'maxbalance', 'select');
    $balance = json_decode($current['ValuePay'] ?? '', true);
    if (isset($balance['f'])) {
        return;
    }
    update('PaySetting', 'ValuePay', json_encode(['f' => '1000000', 'n' => '1000000', 'n2' => '1000000']), 'NamePay', 'maxbalance');
    update('PaySetting', 'ValuePay', json_encode(['f' => '20000', 'n' => '20000', 'n2' => '20000']), 'NamePay', 'minbalance');
};
