<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-four-field-blue' => ['layer'=>'theme', 'variant'=>'blue', 'name'=>'Синее поле', 'slot'=>'game_four_in_a_row_theme', 'price'=>3000, 'sort'=>760],
        'game-four-field-dark' => ['layer'=>'theme', 'variant'=>'dark', 'name'=>'Ночное поле', 'slot'=>'game_four_in_a_row_theme', 'price'=>5000, 'sort'=>761],
        'game-four-field-metal' => ['layer'=>'theme', 'variant'=>'metal', 'name'=>'Стальное поле', 'slot'=>'game_four_in_a_row_theme', 'price'=>8000, 'sort'=>762],
        'game-four-field-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновое поле', 'slot'=>'game_four_in_a_row_theme', 'price'=>12000, 'sort'=>763],

        'game-four-discs-classic' => ['layer'=>'elements', 'variant'=>'classic', 'name'=>'Классические фишки', 'slot'=>'game_four_in_a_row_elements', 'price'=>3000, 'sort'=>780],
        'game-four-discs-3d' => ['layer'=>'elements', 'variant'=>'3d', 'name'=>'Объёмные фишки', 'slot'=>'game_four_in_a_row_elements', 'price'=>6000, 'sort'=>781],
        'game-four-discs-metal' => ['layer'=>'elements', 'variant'=>'metal', 'name'=>'Металлические фишки', 'slot'=>'game_four_in_a_row_elements', 'price'=>9000, 'sort'=>782],
        'game-four-discs-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые фишки', 'slot'=>'game_four_in_a_row_elements', 'price'=>12500, 'sort'=>783],

        'game-four-effect-drop' => ['layer'=>'effect', 'variant'=>'drop', 'name'=>'Импульс падения', 'slot'=>'game_four_in_a_row_effect', 'event'=>'drop', 'price'=>2500, 'sort'=>800],
        'game-four-effect-four' => ['layer'=>'effect', 'variant'=>'four', 'name'=>'Линия четырёх', 'slot'=>'game_four_in_a_row_effect', 'event'=>'four', 'price'=>5000, 'sort'=>801],
        'game-four-effect-victory-wave' => ['layer'=>'effect', 'variant'=>'victory-wave', 'name'=>'Победная волна', 'slot'=>'game_four_in_a_row_effect', 'event'=>'victory_wave', 'price'=>7500, 'sort'=>802],
    ];

    public function version(): string
    {
        return '20260918_0036_add_four_in_a_row_store_cosmetics';
    }

    public function description(): string
    {
        return 'Add the Four in a Row Store catalogue: four fields, four disc sets, and three static-preview effect products. Bundle and live cosmetics remain out of scope.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::ITEMS as $itemId => $definition) {
            $this->ensureCatalogItem($database, $itemId, $definition, $now);
            $this->ensureItemOffer($database, $itemId, $definition, $now);
        }
    }

    private function ensureCatalogItem(DatabaseConnectionInterface $database, string $itemId, array $definition, string $now): void
    {
        $metadata = json_encode([
            'game_type' => 'four_in_a_row',
            'layer' => (string)$definition['layer'],
            'variant' => (string)$definition['variant'],
            'display_name' => (string)$definition['name'],
            'event' => isset($definition['event']) ? (string)$definition['event'] : null,
            'preview_mode' => (string)$definition['layer'] === 'effect' ? 'static_concept' : 'static',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $database->execute(
            $prefix . ' INTO mgw_product_catalog (
                item_id, item_type, item_family, equip_slot, is_store_product, starter_grant,
                catalog_status, metadata_json, created_at_utc, updated_at_utc
             ) VALUES (
                :item_id, :item_type, :item_family, :equip_slot, 1, 0,
                :catalog_status, :metadata_json, :created_at, :updated_at
             )',
            [
                'item_id'=>$itemId,
                'item_type'=>'game',
                'item_family'=>'game_four_in_a_row',
                'equip_slot'=>(string)$definition['slot'],
                'catalog_status'=>'active',
                'metadata_json'=>$metadata,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_four_in_a_row', equip_slot = :equip_slot,
                 is_store_product = 1, starter_grant = 0, catalog_status = 'active',
                 metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id",
            [
                'equip_slot'=>(string)$definition['slot'],
                'metadata_json'=>$metadata,
                'updated_at'=>$now,
                'item_id'=>$itemId,
            ]
        );
    }

    private function ensureItemOffer(DatabaseConnectionInterface $database, string $itemId, array $definition, string $now): void
    {
        $offerId = substr($itemId, 5);
        $members = json_encode([$itemId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $database->execute(
            $prefix . ' INTO mgw_product_offers (
                offer_id, offer_type, item_id, category, subcategory, price_coins,
                partial_unit_price_coins, members_json, offer_status, sort_order,
                created_at_utc, updated_at_utc
             ) VALUES (
                :offer_id, :offer_type, :item_id, :category, :subcategory, :price_coins,
                NULL, :members_json, :offer_status, :sort_order, :created_at, :updated_at
             )',
            [
                'offer_id'=>$offerId,
                'offer_type'=>'item',
                'item_id'=>$itemId,
                'category'=>'games',
                'subcategory'=>'four_in_a_row',
                'price_coins'=>(int)$definition['price'],
                'members_json'=>$members,
                'offer_status'=>'active',
                'sort_order'=>(int)$definition['sort'],
                'created_at'=>$now,
                'updated_at'=>$now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'four_in_a_row',
                 price_coins = :price_coins, partial_unit_price_coins = NULL,
                 members_json = :members_json, offer_status = 'active', sort_order = :sort_order,
                 updated_at_utc = :updated_at
             WHERE offer_id = :offer_id",
            [
                'item_id'=>$itemId,
                'price_coins'=>(int)$definition['price'],
                'members_json'=>$members,
                'sort_order'=>(int)$definition['sort'],
                'updated_at'=>$now,
                'offer_id'=>$offerId,
            ]
        );
    }
};