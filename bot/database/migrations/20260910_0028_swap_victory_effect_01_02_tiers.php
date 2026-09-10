<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260910_0028_swap_victory_effect_01_02_tiers';
    }

    public function description(): string
    {
        return 'Swap Firework Salvo to the 5k tier and Spark Burst to the 8.5k tier.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $salvoMetadata = json_encode([
            'display_name' => 'Салют победителя',
            'tier' => 'tier-1',
            'variant' => 'firework-salvo',
            'price_coins' => 5000,
            'duration_ms' => 2900,
            'offer_id' => 'victory-effect-02',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $sparkMetadata = json_encode([
            'display_name' => 'Искровой залп',
            'tier' => 'tier-2',
            'variant' => 'spark-burst',
            'price_coins' => 8500,
            'duration_ms' => 2200,
            'offer_id' => 'victory-effect-01',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $database->execute(
            'UPDATE mgw_product_catalog
             SET metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id',
            [
                'metadata_json' => $salvoMetadata,
                'updated_at' => $now,
                'item_id' => 'profile-victory-effect-02',
            ]
        );
        $database->execute(
            'UPDATE mgw_product_offers
             SET price_coins = :price_coins, sort_order = :sort_order, updated_at_utc = :updated_at
             WHERE offer_id = :offer_id',
            [
                'price_coins' => 5000,
                'sort_order' => 73,
                'updated_at' => $now,
                'offer_id' => 'victory-effect-02',
            ]
        );

        $database->execute(
            'UPDATE mgw_product_catalog
             SET metadata_json = :metadata_json, updated_at_utc = :updated_at
             WHERE item_id = :item_id',
            [
                'metadata_json' => $sparkMetadata,
                'updated_at' => $now,
                'item_id' => 'profile-victory-effect-01',
            ]
        );
        $database->execute(
            'UPDATE mgw_product_offers
             SET price_coins = :price_coins, sort_order = :sort_order, updated_at_utc = :updated_at
             WHERE offer_id = :offer_id',
            [
                'price_coins' => 8500,
                'sort_order' => 74,
                'updated_at' => $now,
                'offer_id' => 'victory-effect-01',
            ]
        );
    }
};
