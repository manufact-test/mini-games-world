<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    exit;
}

return [
    'version' => 1,
    'currency' => 'GOLD',
    'updated_at' => '2026-07-11',
    'countries' => [
        ['code' => 'RU', 'name' => 'Россия', 'sort_order' => 10, 'enabled' => true],
        ['code' => 'BY', 'name' => 'Беларусь', 'sort_order' => 20, 'enabled' => true],
        ['code' => 'WORLD', 'name' => 'Мир', 'sort_order' => 30, 'enabled' => true],
    ],
    'items' => [
        [
            'id' => 'ozon_ru',
            'country_code' => 'RU',
            'provider_code' => 'ozon',
            'provider' => 'Ozon',
            'title' => 'Сертификат Ozon',
            'description' => 'Электронный сертификат. Выдаётся вручную после проверки заявки.',
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => 'Сертификат Ozon',
            'sort_order' => 10,
            'enabled' => true,
            'denominations' => [
                ['id' => 'ozon_ru_1000', 'label' => '1 000 Gold', 'gold_cost' => 1000, 'sort_order' => 10, 'enabled' => true],
                ['id' => 'ozon_ru_2000', 'label' => '2 000 Gold', 'gold_cost' => 2000, 'sort_order' => 20, 'enabled' => true],
                ['id' => 'ozon_ru_3000', 'label' => '3 000 Gold', 'gold_cost' => 3000, 'sort_order' => 30, 'enabled' => true],
                ['id' => 'ozon_ru_5000', 'label' => '5 000 Gold', 'gold_cost' => 5000, 'sort_order' => 40, 'enabled' => true],
            ],
        ],
        [
            'id' => 'wildberries_ru',
            'country_code' => 'RU',
            'provider_code' => 'wildberries',
            'provider' => 'Wildberries',
            'title' => 'Сертификат Wildberries',
            'description' => 'Электронный сертификат. Выдаётся вручную после проверки заявки.',
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => 'Сертификат Wildberries',
            'sort_order' => 20,
            'enabled' => true,
            'denominations' => [
                ['id' => 'wildberries_ru_1000', 'label' => '1 000 Gold', 'gold_cost' => 1000, 'sort_order' => 10, 'enabled' => true],
                ['id' => 'wildberries_ru_2000', 'label' => '2 000 Gold', 'gold_cost' => 2000, 'sort_order' => 20, 'enabled' => true],
                ['id' => 'wildberries_ru_3000', 'label' => '3 000 Gold', 'gold_cost' => 3000, 'sort_order' => 30, 'enabled' => true],
                ['id' => 'wildberries_ru_5000', 'label' => '5 000 Gold', 'gold_cost' => 5000, 'sort_order' => 40, 'enabled' => true],
            ],
        ],
        [
            'id' => 'wildberries_by',
            'country_code' => 'BY',
            'provider_code' => 'wildberries',
            'provider' => 'Wildberries',
            'title' => 'Сертификат Wildberries',
            'description' => 'Электронный сертификат для Беларуси. Выдаётся вручную после проверки заявки.',
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => 'Сертификат Wildberries для Беларуси',
            'sort_order' => 30,
            'enabled' => true,
            'denominations' => [
                ['id' => 'wildberries_by_1000', 'label' => '1 000 Gold', 'gold_cost' => 1000, 'sort_order' => 10, 'enabled' => true],
                ['id' => 'wildberries_by_2000', 'label' => '2 000 Gold', 'gold_cost' => 2000, 'sort_order' => 20, 'enabled' => true],
                ['id' => 'wildberries_by_3000', 'label' => '3 000 Gold', 'gold_cost' => 3000, 'sort_order' => 30, 'enabled' => true],
            ],
        ],
        [
            'id' => 'aliexpress_world',
            'country_code' => 'WORLD',
            'provider_code' => 'aliexpress',
            'provider' => 'AliExpress',
            'title' => 'Сертификат AliExpress',
            'description' => 'Электронный приз для поддерживаемых регионов. Выдаётся вручную после проверки заявки.',
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => 'Сертификат AliExpress',
            'sort_order' => 40,
            'enabled' => true,
            'denominations' => [
                ['id' => 'aliexpress_world_1000', 'label' => '1 000 Gold', 'gold_cost' => 1000, 'sort_order' => 10, 'enabled' => true],
                ['id' => 'aliexpress_world_2000', 'label' => '2 000 Gold', 'gold_cost' => 2000, 'sort_order' => 20, 'enabled' => true],
                ['id' => 'aliexpress_world_3000', 'label' => '3 000 Gold', 'gold_cost' => 3000, 'sort_order' => 30, 'enabled' => true],
                ['id' => 'aliexpress_world_5000', 'label' => '5 000 Gold', 'gold_cost' => 5000, 'sort_order' => 40, 'enabled' => true],
            ],
        ],
    ],
];
