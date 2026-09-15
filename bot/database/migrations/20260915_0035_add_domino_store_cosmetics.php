<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-domino-table-felt' => ['layer'=>'theme', 'variant'=>'felt', 'name'=>'Классический стол', 'slot'=>'game_domino_theme', 'price'=>3000, 'sort'=>700],
        'game-domino-table-midnight' => ['layer'=>'theme', 'variant'=>'midnight', 'name'=>'Ночной стол', 'slot'=>'game_domino_theme', 'price'=>5000, 'sort'=>701],
        'game-domino-table-walnut' => ['layer'=>'theme', 'variant'=>'walnut', 'name'=>'Ореховый стол', 'slot'=>'game_domino_theme', 'price'=>8000, 'sort'=>702],
        'game-domino-table-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновый стол', 'slot'=>'game_domino_theme', 'price'=>12000, 'sort'=>703],
        'game-domino-tiles-ivory' => ['layer'=>'elements', 'variant'=>'ivory', 'name'=>'Костяные костяшки', 'slot'=>'game_domino_elements', 'price'=>3000, 'sort'=>720],
        'game-domino-tiles-ebony' => ['layer'=>'elements', 'variant'=>'ebony', 'name'=>'Чёрные костяшки', 'slot'=>'game_domino_elements', 'price'=>6000, 'sort'=>721],
        'game-domino-tiles-marble' => ['layer'=>'elements', 'variant'=>'marble', 'name'=>'Мраморные костяшки', 'slot'=>'game_domino_elements', 'price'=>9000, 'sort'=>722],
        'game-domino-tiles-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые костяшки', 'slot'=>'game_domino_elements', 'price'=>12500, 'sort'=>723],
        'game-domino-effect-precision-drop' => ['layer'=>'effect', 'variant'=>'precision-drop', 'name'=>'Точный удар', 'slot'=>'game_domino_effect', 'event'=>'play', 'price'=>2500, 'sort'=>740],
        'game-domino-effect-stock-pulse' => ['layer'=>'effect', 'variant'=>'stock-pulse', 'name'=>'Импульс запаса', 'slot'=>'game_domino_effect', 'event'=>'draw', 'price'=>5000, 'sort'=>741],
        'game-domino-effect-chain-finale' => ['layer'=>'effect', 'variant'=>'chain-finale', 'name'=>'Финиш цепи', 'slot'=>'game_domino_effect', 'event'=>'finish', 'price'=>7500, 'sort'=>742],
    ];

    public function version(): string
    {
        return '20260915_0035_add_domino_store_cosmetics';
    }

    public function description(): string
    {
        return 'Add the MVP-19.9 Domino Store catalogue: four tables, four tile sets, and three effects. Bundles remain out of scope.';
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
            'game_type' => 'domino',
            'layer' => (string)$definition['layer'],
            'variant' => (string)$definition['variant'],
            'display_name' => (string)$definition['name'],
            'event' => isset($definition['event']) ? (string)$definition['event'] : null,
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
                'item_family'=>'game_domino',
                'equip_slot'=>(string)$definition['slot'],
                'catalog_status'=>'active',
                'metadata_json'=>$metadata,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_domino', equip_slot = :equip_slot,
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
                'subcategory'=>'domino',
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
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'domino',
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
