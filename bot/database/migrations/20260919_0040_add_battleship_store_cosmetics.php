<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-battleship-map-sea' => ['layer'=>'theme', 'variant'=>'sea', 'name'=>'Бирюзовый фарватер', 'slot'=>'game_battleship_theme', 'price'=>3000, 'sort'=>820],
        'game-battleship-map-dark-military' => ['layer'=>'theme', 'variant'=>'dark-military', 'name'=>'Военный радар', 'slot'=>'game_battleship_theme', 'price'=>5000, 'sort'=>821],
        'game-battleship-map-storm' => ['layer'=>'theme', 'variant'=>'storm', 'name'=>'Штормовая карта', 'slot'=>'game_battleship_theme', 'price'=>8000, 'sort'=>822],
        'game-battleship-map-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновый сектор', 'slot'=>'game_battleship_theme', 'price'=>12000, 'sort'=>823],

        'game-battleship-fleet-classic' => ['layer'=>'elements', 'variant'=>'classic', 'name'=>'Адмиральский флот', 'slot'=>'game_battleship_elements', 'price'=>3000, 'sort'=>840],
        'game-battleship-fleet-modern' => ['layer'=>'elements', 'variant'=>'modern', 'name'=>'Современный флот', 'slot'=>'game_battleship_elements', 'price'=>6000, 'sort'=>841],
        'game-battleship-fleet-armored' => ['layer'=>'elements', 'variant'=>'armored', 'name'=>'Бронированный флот', 'slot'=>'game_battleship_elements', 'price'=>9000, 'sort'=>842],
        'game-battleship-fleet-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновый флот', 'slot'=>'game_battleship_elements', 'price'=>12500, 'sort'=>843],

        'game-battleship-effect-shot' => ['layer'=>'effect', 'variant'=>'shot', 'name'=>'Плазменный выстрел', 'slot'=>'game_battleship_effect', 'event'=>'shot', 'price'=>2500, 'sort'=>860],
        'game-battleship-effect-hit' => ['layer'=>'effect', 'variant'=>'hit', 'name'=>'Ударная вспышка', 'slot'=>'game_battleship_effect', 'event'=>'hit', 'price'=>5000, 'sort'=>861],
        'game-battleship-effect-destroy' => ['layer'=>'effect', 'variant'=>'destroy', 'name'=>'Критическое потопление', 'slot'=>'game_battleship_effect', 'event'=>'destroy', 'price'=>7500, 'sort'=>862],
    ];

    public function version(): string
    {
        return '20260919_0040_add_battleship_store_cosmetics';
    }

    public function description(): string
    {
        return 'Add Battleship Store Phase 1: four paid maps, four paid fleet sets, and three static concept effects. Paid sea/classic identities are explicitly distinct from the free base presentation; bundles and live cosmetics remain out of scope.';
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
            'game_type' => 'battleship',
            'layer' => (string)$definition['layer'],
            'variant' => (string)$definition['variant'],
            'display_name' => (string)$definition['name'],
            'event' => isset($definition['event']) ? (string)$definition['event'] : null,
            'preview_mode' => (string)$definition['layer'] === 'effect' ? 'static_concept' : 'static',
            'paid_default_distinct' => in_array((string)$definition['variant'], ['sea','classic'], true) ? 'v1' : null,
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
                'item_family'=>'game_battleship',
                'equip_slot'=>(string)$definition['slot'],
                'catalog_status'=>'active',
                'metadata_json'=>$metadata,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_battleship', equip_slot = :equip_slot,
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
                'subcategory'=>'battleship',
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
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'battleship',
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
