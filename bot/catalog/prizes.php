<?php
declare(strict_types=1);

if (!defined('MINIGAMES_INTERNAL')) {
    exit;
}

require_once dirname(__DIR__) . '/localization/ServerLocalization.php';

return [
    'version' => 1,
    'currency' => 'GOLD',
    'updated_at' => '2026-07-11',
    'countries' => [
        ['code' => 'RU', 'name' => ServerLocalization::copy('server.prizes.countries.RU', 'Russia'), 'sort_order' => 10, 'enabled' => true],
        ['code' => 'BY', 'name' => ServerLocalization::copy('server.prizes.countries.BY', 'Belarus'), 'sort_order' => 20, 'enabled' => true],
        ['code' => 'WORLD', 'name' => ServerLocalization::copy('server.prizes.countries.WORLD', 'Worldwide'), 'sort_order' => 30, 'enabled' => true],
    ],
    'items' => [
        [
            'id' => 'ozon_ru',
            'country_code' => 'RU',
            'provider_code' => 'ozon',
            'provider' => 'Ozon',
            'title' => ServerLocalization::copy('server.prizes.ozon_ru.title', 'Ozon gift certificate'),
            'description' => ServerLocalization::copy('server.prizes.ozon_ru.description', 'Electronic certificate. Issued manually after request review.'),
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => ServerLocalization::copy('server.prizes.ozon_ru.image_alt', 'Ozon gift certificate'),
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
            'title' => ServerLocalization::copy('server.prizes.wildberries_ru.title', 'Wildberries gift certificate'),
            'description' => ServerLocalization::copy('server.prizes.wildberries_ru.description', 'Electronic certificate. Issued manually after request review.'),
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => ServerLocalization::copy('server.prizes.wildberries_ru.image_alt', 'Wildberries gift certificate'),
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
            'title' => ServerLocalization::copy('server.prizes.wildberries_by.title', 'Wildberries gift certificate'),
            'description' => ServerLocalization::copy('server.prizes.wildberries_by.description', 'Electronic certificate for Belarus. Issued manually after request review.'),
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => ServerLocalization::copy('server.prizes.wildberries_by.image_alt', 'Wildberries gift certificate for Belarus'),
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
            'title' => ServerLocalization::copy('server.prizes.aliexpress_world.title', 'AliExpress gift certificate'),
            'description' => ServerLocalization::copy('server.prizes.aliexpress_world.description', 'Electronic prize for supported regions. Issued manually after request review.'),
            'delivery_type' => 'manual_code',
            'image' => '',
            'image_alt' => ServerLocalization::copy('server.prizes.aliexpress_world.image_alt', 'AliExpress gift certificate'),
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
