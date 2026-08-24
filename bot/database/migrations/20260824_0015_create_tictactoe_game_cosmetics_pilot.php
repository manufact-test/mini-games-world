<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-ttt-field-classic' => ['layer'=>'theme', 'variant'=>'classic', 'name'=>'Классическое поле', 'slot'=>'game_tictactoe_theme', 'price'=>3000, 'sort'=>200],
        'game-ttt-field-dark' => ['layer'=>'theme', 'variant'=>'dark', 'name'=>'Тёмное поле', 'slot'=>'game_tictactoe_theme', 'price'=>5000, 'sort'=>201],
        'game-ttt-field-glass' => ['layer'=>'theme', 'variant'=>'glass', 'name'=>'3D Glass поле', 'slot'=>'game_tictactoe_theme', 'price'=>8000, 'sort'=>202],
        'game-ttt-field-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновое поле', 'slot'=>'game_tictactoe_theme', 'price'=>12000, 'sort'=>203],
        'game-ttt-marks-classic' => ['layer'=>'elements', 'variant'=>'classic', 'name'=>'Классические знаки', 'slot'=>'game_tictactoe_elements', 'price'=>3000, 'sort'=>220],
        'game-ttt-marks-3d' => ['layer'=>'elements', 'variant'=>'3d', 'name'=>'3D знаки', 'slot'=>'game_tictactoe_elements', 'price'=>6000, 'sort'=>221],
        'game-ttt-marks-metal' => ['layer'=>'elements', 'variant'=>'metal', 'name'=>'Металлические знаки', 'slot'=>'game_tictactoe_elements', 'price'=>9000, 'sort'=>222],
        'game-ttt-marks-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые знаки', 'slot'=>'game_tictactoe_elements', 'price'=>12500, 'sort'=>223],
        'game-ttt-effect-sign' => ['layer'=>'effect', 'variant'=>'sign', 'name'=>'Эффект знака', 'slot'=>'game_tictactoe_effect_sign', 'event'=>'sign', 'price'=>2500, 'sort'=>240],
        'game-ttt-effect-winning-line' => ['layer'=>'effect', 'variant'=>'winning-line', 'name'=>'Победная линия', 'slot'=>'game_tictactoe_effect_winning_line', 'event'=>'winning_line', 'price'=>5000, 'sort'=>241],
        'game-ttt-effect-strike' => ['layer'=>'effect', 'variant'=>'strike-through', 'name'=>'Strike-through', 'slot'=>'game_tictactoe_effect_strike_through', 'event'=>'strike_through', 'price'=>7500, 'sort'=>242],
    ];

    private const PREMIUM_MEMBERS = [
        'game-ttt-field-neon',
        'game-ttt-marks-neon',
        'game-ttt-effect-sign',
        'game-ttt-effect-winning-line',
        'game-ttt-effect-strike',
    ];

    public function version(): string
    {
        return '20260824_0015_create_tictactoe_game_cosmetics_pilot';
    }

    public function description(): string
    {
        return 'Seed the generic game cosmetics contract and the Tic Tac Toe purchase-preview-equip-opponent pilot.';
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
            'game_type' => 'tictactoe',
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
                'item_family' => 'game_tictactoe',
                'equip_slot' => (string)$definition['slot'],
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_tictactoe', equip_slot = :equip_slot,
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
                'subcategory' => 'tictactoe',
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
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'tictactoe',
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
            'offer_id' => 'ttt-premium-bundle',
            'offer_type' => 'bundle',
            'category' => 'bundles',
            'subcategory' => 'tictactoe',
            'price_coins' => 34000,
            'members_json' => $members,
            'offer_status' => 'active',
            'sort_order' => 300,
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
             SET offer_type = 'bundle', item_id = NULL, category = 'bundles', subcategory = 'tictactoe',
                 price_coins = 34000, partial_unit_price_coins = NULL,
                 members_json = :members_json, offer_status = 'active', sort_order = 300,
                 updated_at_utc = :updated_at
             WHERE offer_id = 'ttt-premium-bundle'",
            ['members_json' => $members, 'updated_at' => $now]
        );
    }
};
