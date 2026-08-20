<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const PAID_AVATARS = [
        'store-avatar-01' => ['rarity' => 'rare', 'price' => 250],
        'store-avatar-02' => ['rarity' => 'rare', 'price' => 250],
        'store-avatar-03' => ['rarity' => 'rare', 'price' => 250],
        'store-avatar-04' => ['rarity' => 'elite', 'price' => 300],
        'store-avatar-05' => ['rarity' => 'elite', 'price' => 300],
        'store-avatar-06' => ['rarity' => 'elite', 'price' => 300],
        'store-avatar-07' => ['rarity' => 'legendary', 'price' => 400],
        'store-avatar-08' => ['rarity' => 'legendary', 'price' => 400],
        'store-avatar-09' => ['rarity' => 'legendary', 'price' => 400],
    ];

    public function version(): string
    {
        return '20260820_0014_expand_avatar_economy_tiers';
    }

    public function description(): string
    {
        return 'Expand avatar economy to three free starters plus nine paid Rare, Elite and Legendary avatars.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        foreach (self::PAID_AVATARS as $itemId => $definition) {
            $rarity = (string)$definition['rarity'];
            $price = (int)$definition['price'];
            $metadata = json_encode(['rarity' => $rarity], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->ensureCatalogItem($database, $itemId, $metadata, $now);
            $this->ensureItemOffer($database, $itemId, $price, $now);
        }

        // The old five-avatar bundle belongs to the superseded launch economy.
        // Purchase history remains immutable; only future discovery/purchase is retired.
        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_status = 'retired', updated_at_utc = :updated_at
             WHERE offer_id = 'avatar-bundle-5'",
            ['updated_at' => $now]
        );
    }

    private function ensureCatalogItem(
        DatabaseConnectionInterface $database,
        string $itemId,
        string $metadata,
        string $now
    ): void {
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $database->execute(
            $prefix . ' INTO mgw_product_catalog (
                item_id, item_type, item_family, equip_slot, is_store_product, starter_grant,
                catalog_status, metadata_json, created_at_utc, updated_at_utc
             ) VALUES (
                :item_id, :item_type, :item_family, :equip_slot, :is_store_product, :starter_grant,
                :catalog_status, :metadata_json, :created_at, :updated_at
             )',
            [
                'item_id' => $itemId,
                'item_type' => 'profile',
                'item_family' => 'avatar',
                'equip_slot' => 'profile_avatar',
                'is_store_product' => 1,
                'starter_grant' => 0,
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'profile',
                 item_family = 'avatar',
                 equip_slot = 'profile_avatar',
                 is_store_product = 1,
                 starter_grant = 0,
                 catalog_status = 'active',
                 metadata_json = :metadata_json,
                 updated_at_utc = :updated_at
             WHERE item_id = :item_id",
            [
                'metadata_json' => $metadata,
                'updated_at' => $now,
                'item_id' => $itemId,
            ]
        );
    }

    private function ensureItemOffer(
        DatabaseConnectionInterface $database,
        string $itemId,
        int $priceCoins,
        string $now
    ): void {
        $offerId = 'avatar-' . substr($itemId, -2);
        $sortOrder = 10 + ((int)substr($itemId, -2) - 1);
        $members = json_encode([$itemId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $database->execute(
            $prefix . ' INTO mgw_product_offers (
                offer_id, offer_type, item_id, category, subcategory, price_coins,
                partial_unit_price_coins, members_json, offer_status, sort_order,
                created_at_utc, updated_at_utc
             ) VALUES (
                :offer_id, :offer_type, :item_id, :category, :subcategory, :price_coins,
                NULL, :members_json, :offer_status, :sort_order,
                :created_at, :updated_at
             )',
            [
                'offer_id' => $offerId,
                'offer_type' => 'item',
                'item_id' => $itemId,
                'category' => 'profile',
                'subcategory' => 'avatars',
                'price_coins' => $priceCoins,
                'members_json' => $members,
                'offer_status' => 'active',
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_type = 'item',
                 item_id = :item_id,
                 category = 'profile',
                 subcategory = 'avatars',
                 price_coins = :price_coins,
                 partial_unit_price_coins = NULL,
                 members_json = :members_json,
                 offer_status = 'active',
                 sort_order = :sort_order,
                 updated_at_utc = :updated_at
             WHERE offer_id = :offer_id",
            [
                'item_id' => $itemId,
                'price_coins' => $priceCoins,
                'members_json' => $members,
                'sort_order' => $sortOrder,
                'updated_at' => $now,
                'offer_id' => $offerId,
            ]
        );
    }
};
