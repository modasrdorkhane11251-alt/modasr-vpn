<?php

return [
    'create' => <<<SQL
        description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        status_commission varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        Discount varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        price_Discount varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
        porsant_one_buy varchar(100),
        id_media varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
        SQL,
    'seedOnCreate' => [
        [
            'description' => 'none',
            'id_media' => 'none',
            'status_commission' => 'oncommission',
            'Discount' => 'onDiscountaffiliates',
            'porsant_one_buy' => 'off_buy_porsant',
        ],
    ],
    'columns' => [
        ['status_commission', 'oncommission', 'VARCHAR(100)'],
        ['Discount', 'onDiscountaffiliates', 'VARCHAR(100)'],
        ['price_Discount', null, 'VARCHAR(100)'],
        ['porsant_one_buy', 'off_buy_porsant', 'VARCHAR(100)'],
    ],
];
