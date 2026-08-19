<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const STORE_AVATARS = [
        'store-avatar-01',
        'store-avatar-02',
        'store-avatar-03',
        'store-avatar-04',
        'store-avatar-05',
    ];

    public function version(): string
    {
        return '20260819_0013_create_cosmetic_store_offers_purchases';
    }

    public function description(): string
    {
        return 'Create canonical digital Store offers and durable cosmetic purchase audit.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->createSqlite($database);
        } else {
            $this->createMysql($database);
        }
        $this->seedLaunchOffers($database);
    }

    private function createMysql(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_product_offers (
    offer_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    offer_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    category VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subcategory VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    price_coins INT UNSIGNED NOT NULL,
    partial_unit_price_coins INT UNSIGNED NULL,
    members_json TEXT NULL,
    offer_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_product_offers_surface (category, subcategory, offer_status, sort_order),
    INDEX idx_mgw_product_offers_item (item_id, offer_status),
    CONSTRAINT fk_mgw_product_offers_item FOREIGN KEY (item_id)
        REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_mgw_product_offers_type CHECK (offer_type IN ('item','bundle')),
    CONSTRAINT chk_mgw_product_offers_status CHECK (offer_status IN ('active','hidden','retired')),
    CONSTRAINT chk_mgw_product_offers_price CHECK (price_coins > 0),
    CONSTRAINT chk_mgw_product_offers_partial CHECK (partial_unit_price_coins IS NULL OR partial_unit_price_coins > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_cosmetic_purchases (
    purchase_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    request_token VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    offer_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    price_coins INT UNSIGNED NOT NULL,
    items_json TEXT NOT NULL,
    purchase_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'completed',
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_cosmetic_purchase_request (mgw_id, request_token),
    INDEX idx_mgw_cosmetic_purchase_user (mgw_id, created_at_utc),
    INDEX idx_mgw_cosmetic_purchase_offer (offer_id, created_at_utc),
    CONSTRAINT fk_mgw_cosmetic_purchase_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_cosmetic_purchase_offer FOREIGN KEY (offer_id)
        REFERENCES mgw_product_offers (offer_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_mgw_cosmetic_purchase_price CHECK (price_coins > 0),
    CONSTRAINT chk_mgw_cosmetic_purchase_status CHECK (purchase_status IN ('completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function createSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_product_offers (
    offer_id TEXT NOT NULL PRIMARY KEY,
    offer_type TEXT NOT NULL CHECK (offer_type IN ('item','bundle')),
    item_id TEXT NULL,
    category TEXT NOT NULL,
    subcategory TEXT NULL,
    price_coins INTEGER NOT NULL CHECK (price_coins > 0),
    partial_unit_price_coins INTEGER NULL CHECK (partial_unit_price_coins IS NULL OR partial_unit_price_coins > 0),
    members_json TEXT NULL,
    offer_status TEXT NOT NULL DEFAULT 'active' CHECK (offer_status IN ('active','hidden','retired')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (item_id) REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_product_offers_surface ON mgw_product_offers (category, subcategory, offer_status, sort_order)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_product_offers_item ON mgw_product_offers (item_id, offer_status)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_cosmetic_purchases (
    purchase_id TEXT NOT NULL PRIMARY KEY,
    request_token TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    account_ref TEXT NOT NULL,
    legacy_user_id TEXT NOT NULL,
    offer_id TEXT NOT NULL,
    price_coins INTEGER NOT NULL CHECK (price_coins > 0),
    items_json TEXT NOT NULL,
    purchase_status TEXT NOT NULL DEFAULT 'completed' CHECK (purchase_status IN ('completed')),
    created_at_utc TEXT NOT NULL,
    UNIQUE (mgw_id, request_token),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (offer_id) REFERENCES mgw_product_offers (offer_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_cosmetic_purchase_user ON mgw_cosmetic_purchases (mgw_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_cosmetic_purchase_offer ON mgw_cosmetic_purchases (offer_id, created_at_utc)');
    }

    private function seedLaunchOffers(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::STORE_AVATARS as $index => $itemId) {
            $this->insertOffer(
                $database,
                'avatar-' . substr($itemId, -2),
                'item',
                $itemId,
                300,
                null,
                [$itemId],
                10 + $index,
                $now
            );
        }
        $this->insertOffer(
            $database,
            'avatar-bundle-5',
            'bundle',
            null,
            1200,
            240,
            self::STORE_AVATARS,
            100,
            $now
        );
    }

    private function insertOffer(
        DatabaseConnectionInterface $database,
        string $offerId,
        string $offerType,
        ?string $itemId,
        int $priceCoins,
        ?int $partialUnitPriceCoins,
        array $members,
        int $sortOrder,
        string $now
    ): void {
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $database->execute(
            $prefix . ' INTO mgw_product_offers (
                offer_id, offer_type, item_id, category, subcategory, price_coins,
                partial_unit_price_coins, members_json, offer_status, sort_order,
                created_at_utc, updated_at_utc
             ) VALUES (
                :offer_id, :offer_type, :item_id, :category, :subcategory, :price_coins,
                :partial_unit_price_coins, :members_json, :offer_status, :sort_order,
                :created_at_utc, :updated_at_utc
             )',
            [
                'offer_id' => $offerId,
                'offer_type' => $offerType,
                'item_id' => $itemId,
                'category' => 'profile',
                'subcategory' => 'avatars',
                'price_coins' => $priceCoins,
                'partial_unit_price_coins' => $partialUnitPriceCoins,
                'members_json' => json_encode(array_values($members), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'offer_status' => 'active',
                'sort_order' => $sortOrder,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ]
        );
    }
};
