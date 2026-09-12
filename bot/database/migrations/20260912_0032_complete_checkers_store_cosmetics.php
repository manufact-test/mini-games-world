<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-checkers-pieces-wood' => ['layer'=>'elements', 'variant'=>'wood', 'name'=>'Деревянные шашки', 'slot'=>'game_checkers_elements', 'price'=>3000, 'sort'=>520],
        'game-checkers-pieces-marble' => ['layer'=>'elements', 'variant'=>'marble', 'name'=>'Мраморные шашки', 'slot'=>'game_checkers_elements', 'price'=>6000, 'sort'=>521],
        'game-checkers-pieces-metal' => ['layer'=>'elements', 'variant'=>'metal', 'name'=>'Металлические шашки', 'slot'=>'game_checkers_elements', 'price'=>9000, 'sort'=>522],
        'game-checkers-pieces-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые шашки', 'slot'=>'game_checkers_elements', 'price'=>12500, 'sort'=>523],
        'game-checkers-effect-move' => ['layer'=>'effect', 'variant'=>'move', 'name'=>'Эффект хода', 'slot'=>'game_checkers_effect', 'event'=>'move', 'price'=>2500, 'sort'=>540],
        'game-checkers-effect-capture' => ['layer'=>'effect', 'variant'=>'capture', 'name'=>'Эффект взятия', 'slot'=>'game_checkers_effect', 'event'=>'capture', 'price'=>5000, 'sort'=>541],
        'game-checkers-effect-promotion' => ['layer'=>'effect', 'variant'=>'promotion', 'name'=>'Эффект дамки', 'slot'=>'game_checkers_effect', 'event'=>'promotion', 'price'=>7500, 'sort'=>542],
    ];

    private const PREMIUM_MEMBERS = [
        'game-checkers-board-neon',
        'game-checkers-pieces-neon',
        'game-checkers-effect-move',
        'game-checkers-effect-capture',
        'game-checkers-effect-promotion',
    ];

    public function version(): string
    {
        return '20260912_0032_complete_checkers_store_cosmetics';
    }

    public function description(): string
    {
        return 'Complete the MVP-19.6 Checkers Store with four piece sets, three event effects, and the canonical premium bundle.';
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
        $this->ensurePremiumBundle($database, $now);
    }

    private function ensureCatalogItem(
        DatabaseConnectionInterface $database,
        string $itemId,
        array $definition,
        string $now
    ): void {
        $metadata = json_encode([
            'game_type' => 'checkers',
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
                'item_id' => $itemId,
                'item_type' => 'game',
                'item_family' => 'game_checkers',
                'equip_slot' => (string)$definition['slot'],
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_checkers', equip_slot = :equip_slot,
                 is_store_product = 1, starter_grant = 0, catalog_status = 'active',
                 metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id",
            [
                'equip_slot' => (string)$definition['slot'],
                'metadata_json' => $metadata,
                'updated_at' => $now,
                'item_id' => $itemId,
            ]
        );
    }

    private function ensureItemOffer(
        DatabaseConnectionInterface $database,
        string $itemId,
        array $definition,
        string $now
    ): void {
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
                'offer_id' => $offerId,
                'offer_type' => 'item',
                'item_id' => $itemId,
                'category' => 'games',
                'subcategory' => 'checkers',
                'price_coins' => (int)$definition['price'],
                'members_json' => $members,
                'offer_status' => 'active',
                'sort_order' => (int)$definition['sort'],
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'checkers',
                 price_coins = :price_coins, partial_unit_price_coins = NULL,
                 members_json = :members_json, offer_status = 'active', sort_order = :sort_order,
                 updated_at_utc = :updated_at
             WHERE offer_id = :offer_id",
            [
                'item_id' => $itemId,
                'price_coins' => (int)$definition['price'],
                'members_json' => $members,
                'sort_order' => (int)$definition['sort'],
                'updated_at' => $now,
                'offer_id' => $offerId,
            ]
        );
    }

    private function ensurePremiumBundle(DatabaseConnectionInterface $database, string $now): void
    {
        $members = json_encode(self::PREMIUM_MEMBERS, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $parameters = [
            'offer_id' => 'checkers-premium-bundle',
            'offer_type' => 'bundle',
            'category' => 'bundles',
            'subcategory' => 'checkers',
            'price_coins' => 34000,
            'members_json' => $members,
            'offer_status' => 'active',
            'sort_order' => 560,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $database->execute(
            $prefix . ' INTO mgw_product_offers (
                offer_id, offer_type, item_id, category, subcategory, price_coins,
                partial_unit_price_coins, members_json, offer_status, sort_order,
                created_at_utc, updated_at_utc
             ) VALUES (
                :offer_id, :offer_type, NULL, :category, :subcategory, :price_coins,
                NULL, :members_json, :offer_status, :sort_order, :created_at, :updated_at
             )',
            $parameters
        );

        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_type = 'bundle', item_id = NULL, category = 'bundles', subcategory = 'checkers',
                 price_coins = 34000, partial_unit_price_coins = NULL,
                 members_json = :members_json, offer_status = 'active', sort_order = 560,
                 updated_at_utc = :updated_at
             WHERE offer_id = 'checkers-premium-bundle'",
            ['members_json' => $members, 'updated_at' => $now]
        );
    }
};
