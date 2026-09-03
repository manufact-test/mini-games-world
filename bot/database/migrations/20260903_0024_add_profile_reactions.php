<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const SLOT = 'profile_reaction_set';

    private const ITEMS = [
        'profile-reaction-wave' => [
            'tier' => 'single', 'name' => 'Привет', 'subtitle' => 'Одна реакция',
            'price' => 500, 'sort' => 60, 'reactions' => ['wave'],
        ],
        'profile-reaction-clap' => [
            'tier' => 'single', 'name' => 'Браво', 'subtitle' => 'Одна реакция',
            'price' => 500, 'sort' => 61, 'reactions' => ['clap'],
        ],
        'profile-reaction-heart' => [
            'tier' => 'single', 'name' => 'Сердце', 'subtitle' => 'Одна реакция',
            'price' => 500, 'sort' => 62, 'reactions' => ['heart'],
        ],
        'profile-reaction-fire' => [
            'tier' => 'single', 'name' => 'Огонь', 'subtitle' => 'Одна реакция',
            'price' => 500, 'sort' => 63, 'reactions' => ['fire'],
        ],
        'profile-reaction-pack-4' => [
            'tier' => 'pack4', 'name' => 'Четвёрка', 'subtitle' => 'Набор из 4 реакций',
            'price' => 1500, 'sort' => 64, 'reactions' => ['wave', 'clap', 'heart', 'fire'],
        ],
        'profile-reaction-pack-large' => [
            'tier' => 'large', 'name' => 'Большой набор', 'subtitle' => 'Набор из 8 реакций',
            'price' => 3500, 'sort' => 65,
            'reactions' => ['wave', 'clap', 'heart', 'fire', 'target', 'spark', 'crown', 'handshake'],
        ],
    ];

    public function version(): string
    {
        return '20260903_0024_add_profile_reactions';
    }

    public function description(): string
    {
        return 'Seed the canonical MVP-19.3 profile reaction-set catalogue and Store offers.';
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

    private function ensureCatalogItem(DatabaseConnectionInterface $database, string $itemId, array $definition, string $now): void
    {
        $metadata = json_encode([
            'display_name' => (string)$definition['name'],
            'subtitle' => (string)$definition['subtitle'],
            'tier' => (string)$definition['tier'],
            'price_coins' => (int)$definition['price'],
            'offer_id' => str_replace('profile-', '', $itemId),
            'reactions' => array_values((array)$definition['reactions']),
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
                'item_family' => 'reaction',
                'equip_slot' => self::SLOT,
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'profile', item_family = 'reaction', equip_slot = :equip_slot,
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

    private function ensureOffer(DatabaseConnectionInterface $database, string $itemId, array $definition, string $now): void
    {
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
                'subcategory' => 'reaction',
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
             SET offer_type = 'item', item_id = :item_id, category = 'profile', subcategory = 'reaction',
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