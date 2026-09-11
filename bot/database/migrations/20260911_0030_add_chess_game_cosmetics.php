<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-chess-board-wood' => ['layer'=>'theme', 'variant'=>'wood', 'name'=>'Деревянная доска', 'slot'=>'game_chess_theme', 'price'=>3000, 'sort'=>400],
        'game-chess-board-tournament-dark' => ['layer'=>'theme', 'variant'=>'tournament-dark', 'name'=>'Тёмная турнирная доска', 'slot'=>'game_chess_theme', 'price'=>5000, 'sort'=>401],
        'game-chess-board-marble' => ['layer'=>'theme', 'variant'=>'marble', 'name'=>'Мраморная доска', 'slot'=>'game_chess_theme', 'price'=>8000, 'sort'=>402],
        'game-chess-board-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновая доска', 'slot'=>'game_chess_theme', 'price'=>12000, 'sort'=>403],
        'game-chess-pieces-wood' => ['layer'=>'elements', 'variant'=>'wood', 'name'=>'Деревянные фигуры', 'slot'=>'game_chess_elements', 'price'=>3000, 'sort'=>420],
        'game-chess-pieces-marble' => ['layer'=>'elements', 'variant'=>'marble', 'name'=>'Мраморные фигуры', 'slot'=>'game_chess_elements', 'price'=>6000, 'sort'=>421],
        'game-chess-pieces-metal' => ['layer'=>'elements', 'variant'=>'metal', 'name'=>'Металлические фигуры', 'slot'=>'game_chess_elements', 'price'=>9000, 'sort'=>422],
        'game-chess-pieces-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые фигуры', 'slot'=>'game_chess_elements', 'price'=>12500, 'sort'=>423],
        'game-chess-effect-move' => ['layer'=>'effect', 'variant'=>'move', 'name'=>'Эффект хода', 'slot'=>'game_chess_effect', 'event'=>'move', 'price'=>2500, 'sort'=>440],
        'game-chess-effect-capture' => ['layer'=>'effect', 'variant'=>'capture', 'name'=>'Эффект взятия', 'slot'=>'game_chess_effect', 'event'=>'capture', 'price'=>5000, 'sort'=>441],
        'game-chess-effect-check' => ['layer'=>'effect', 'variant'=>'check', 'name'=>'Эффект шаха', 'slot'=>'game_chess_effect', 'event'=>'check', 'price'=>7500, 'sort'=>442],
    ];

    public function version(): string
    {
        return '20260911_0030_add_chess_game_cosmetics';
    }

    public function description(): string
    {
        return 'Add the eleven canonical Chess cosmetic items without publishing an unapproved premium bundle composition.';
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

    private function ensureCatalogItem(
        DatabaseConnectionInterface $database,
        string $itemId,
        array $definition,
        string $now
    ): void {
        $metadata = json_encode([
            'game_type' => 'chess',
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
                'item_family' => 'game_chess',
                'equip_slot' => (string)$definition['slot'],
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_chess', equip_slot = :equip_slot,
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
                'subcategory' => 'chess',
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
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'chess',
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
};