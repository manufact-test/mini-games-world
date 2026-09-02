<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const SLOT = 'profile_badge';

    private const ITEMS = [
        'profile-badge-spark' => [
            'tier' => 'normal',
            'variant' => 'spark',
            'name' => 'Искра',
            'price' => 1000,
            'animated' => false,
            'sort' => 50,
        ],
        'profile-badge-crest' => [
            'tier' => 'rare',
            'variant' => 'crest',
            'name' => 'Герб',
            'price' => 2500,
            'animated' => false,
            'sort' => 51,
        ],
        'profile-badge-pulse' => [
            'tier' => 'animated',
            'variant' => 'pulse',
            'name' => 'Пульс',
            'price' => 6000,
            'animated' => true,
            'sort' => 52,
        ],
    ];

    public function version(): string
    {
        return '20260902_0020_add_profile_badges';
    }

    public function description(): string
    {
        return 'Seed the canonical MVP-19.3 profile badge catalogue and Store offers.';
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
            $this->ensureOffer($database, $itemId, $definition, $now);
        }
    }

    private function ensureCatalogItem(
        DatabaseConnectionInterface $database,
        string $itemId,
        array $definition,
        string $now
    ): void {
        $offerId = str_replace('profile-', '', $itemId);
        $metadata = json_encode([
            'display_name' => (string)$definition['name'],
            'tier' => (string)$definition['tier'],
            'variant' => (string)$definition['variant'],
            'animated' => (bool)$definition['animated'],
            'price_coins' => (int)$definition['price'],
            'offer_id' => $offerId,
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
                'item_type' => 'profile',
                'item_family' => 'badge',
                'equip_slot' => self::SLOT,
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'profile', item_family = 'badge', equip_slot = :equip_slot,
                 is_store_product = 1, starter_grant = 0, catalog_status = 'active',
                 metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id",
            [
                'equip_slot' => self::SLOT,
                'metadata_json' => $metadata,
                'updated_at' => $now,
                'item_id' => $itemId,
            ]
        );
    }

    private function ensureOffer(
        DatabaseConnectionInterface $database,
        string $itemId,
        array $definition,
        string $now
    ): void {
        $offerId = str_replace('profile-', '', $itemId);
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
                'category' => 'profile',
                'subcategory' => 'badge',
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
             SET offer_type = 'item', item_id = :item_id, category = 'profile', subcategory = 'badge',
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
