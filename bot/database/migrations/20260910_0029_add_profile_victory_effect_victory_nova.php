<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const SLOT = 'profile_victory_effect';
    private const ITEM_ID = 'profile-victory-effect-03';
    private const OFFER_ID = 'victory-effect-03';
    private const PRICE = 12500;
    private const DURATION_MS = 3500;

    public function version(): string
    {
        return '20260910_0029_add_profile_victory_effect_victory_nova';
    }

    public function description(): string
    {
        return 'Seed MVP-19.3 flagship Victory Effect III: Victory Nova.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $metadata = json_encode([
            'display_name' => 'Победная сверхновая',
            'tier' => 'tier-3',
            'variant' => 'victory-nova',
            'price_coins' => self::PRICE,
            'duration_ms' => self::DURATION_MS,
            'offer_id' => self::OFFER_ID,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $members = json_encode([self::ITEM_ID], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
                'item_id' => self::ITEM_ID,
                'item_type' => 'profile',
                'item_family' => 'victory_effect',
                'equip_slot' => self::SLOT,
                'catalog_status' => 'active',
                'metadata_json' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_catalog
             SET item_type = 'profile', item_family = 'victory_effect', equip_slot = :equip_slot,
                 is_store_product = 1, starter_grant = 0, catalog_status = 'active',
                 metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id",
            [
                'equip_slot' => self::SLOT,
                'metadata_json' => $metadata,
                'updated_at' => $now,
                'item_id' => self::ITEM_ID,
            ]
        );

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
                'offer_id' => self::OFFER_ID,
                'offer_type' => 'item',
                'item_id' => self::ITEM_ID,
                'category' => 'profile',
                'subcategory' => 'victory_effect',
                'price_coins' => self::PRICE,
                'members_json' => $members,
                'offer_status' => 'active',
                'sort_order' => 75,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $database->execute(
            "UPDATE mgw_product_offers
             SET offer_type = 'item', item_id = :item_id, category = 'profile', subcategory = 'victory_effect',
                 price_coins = :price_coins, partial_unit_price_coins = NULL,
                 members_json = :members_json, offer_status = 'active', sort_order = :sort_order,
                 updated_at_utc = :updated_at
             WHERE offer_id = :offer_id",
            [
                'item_id' => self::ITEM_ID,
                'price_coins' => self::PRICE,
                'members_json' => $members,
                'sort_order' => 75,
                'updated_at' => $now,
                'offer_id' => self::OFFER_ID,
            ]
        );
    }
};
