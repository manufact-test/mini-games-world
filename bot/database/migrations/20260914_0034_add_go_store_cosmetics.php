<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const ITEMS = [
        'game-go-board-wood' => ['layer'=>'theme', 'variant'=>'wood', 'name'=>'Деревянная доска', 'slot'=>'game_go_theme', 'price'=>3000, 'sort'=>640],
        'game-go-board-dark' => ['layer'=>'theme', 'variant'=>'dark', 'name'=>'Тёмная доска', 'slot'=>'game_go_theme', 'price'=>5000, 'sort'=>641],
        'game-go-board-stone' => ['layer'=>'theme', 'variant'=>'stone', 'name'=>'Каменная доска', 'slot'=>'game_go_theme', 'price'=>8000, 'sort'=>642],
        'game-go-board-neon' => ['layer'=>'theme', 'variant'=>'neon', 'name'=>'Неоновая доска', 'slot'=>'game_go_theme', 'price'=>12000, 'sort'=>643],
        'game-go-stones-classic' => ['layer'=>'elements', 'variant'=>'classic', 'name'=>'Классические камни', 'slot'=>'game_go_elements', 'price'=>3000, 'sort'=>660],
        'game-go-stones-marble' => ['layer'=>'elements', 'variant'=>'marble', 'name'=>'Мраморные камни', 'slot'=>'game_go_elements', 'price'=>6000, 'sort'=>661],
        'game-go-stones-glass' => ['layer'=>'elements', 'variant'=>'glass', 'name'=>'Стеклянные камни', 'slot'=>'game_go_elements', 'price'=>9000, 'sort'=>662],
        'game-go-stones-neon' => ['layer'=>'elements', 'variant'=>'neon', 'name'=>'Неоновые камни', 'slot'=>'game_go_elements', 'price'=>12500, 'sort'=>663],
        'game-go-effect-placement' => ['layer'=>'effect', 'variant'=>'placement', 'name'=>'Эффект постановки', 'slot'=>'game_go_effect', 'event'=>'placement', 'price'=>2500, 'sort'=>680],
        'game-go-effect-group-capture' => ['layer'=>'effect', 'variant'=>'group-capture', 'name'=>'Захват группы', 'slot'=>'game_go_effect', 'event'=>'group_capture', 'price'=>5000, 'sort'=>681],
        'game-go-effect-territory-finish' => ['layer'=>'effect', 'variant'=>'territory-finish', 'name'=>'Завершение территории', 'slot'=>'game_go_effect', 'event'=>'territory_finish', 'price'=>7500, 'sort'=>682],
    ];

    public function version(): string
    {
        return '20260914_0034_add_go_store_cosmetics';
    }

    public function description(): string
    {
        return 'Add the MVP-19.8 Go Store catalogue: four boards, four stone sets, and three effects. Bundles remain out of scope.';
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
            'game_type' => 'go',
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
                'item_family'=>'game_go',
                'equip_slot'=>(string)$definition['slot'],
                'catalog_status'=>'active',
                'metadata_json'=>$metadata,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'game', item_family = 'game_go', equip_slot = :equip_slot,
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
                'subcategory'=>'go',
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
             SET offer_type = 'item', item_id = :item_id, category = 'games', subcategory = 'go',
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
