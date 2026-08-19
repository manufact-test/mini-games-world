<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    private const STARTER_AVATARS = [
        'starter-default-01',
        'starter-default-02',
        'starter-default-03',
    ];

    private const STORE_AVATARS = [
        'store-avatar-01',
        'store-avatar-02',
        'store-avatar-03',
        'store-avatar-04',
        'store-avatar-05',
    ];

    public function version(): string
    {
        return '20260819_0012_create_product_catalog_inventory_equipment';
    }

    public function description(): string
    {
        return 'Create canonical product catalogue, permanent inventory ownership and equip slots.';
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

        $this->seedAvatarCatalogue($database);
        $this->backfillStarterOwnership($database);
        $this->backfillAvatarEquipment($database);
    }

    private function createMysql(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_product_catalog (
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    item_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_family VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    equip_slot VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    is_store_product TINYINT(1) NOT NULL DEFAULT 0,
    starter_grant TINYINT(1) NOT NULL DEFAULT 0,
    catalog_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    metadata_json TEXT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_catalog_type_status (item_type, catalog_status),
    INDEX idx_mgw_catalog_store (is_store_product, catalog_status),
    CONSTRAINT chk_mgw_catalog_type CHECK (item_type IN ('profile','game','bundle','seasonal','showcase')),
    CONSTRAINT chk_mgw_catalog_status CHECK (catalog_status IN ('active','hidden','retired')),
    CONSTRAINT chk_mgw_catalog_store CHECK (is_store_product IN (0,1)),
    CONSTRAINT chk_mgw_catalog_starter CHECK (starter_grant IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_inventory_items (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    acquired_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    acquired_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    acquired_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (mgw_id, item_id),
    INDEX idx_mgw_inventory_item (item_id, acquired_at_utc),
    CONSTRAINT fk_mgw_inventory_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_inventory_catalog FOREIGN KEY (item_id)
        REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_equipped_items (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    equip_slot VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    equipped_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (mgw_id, equip_slot),
    INDEX idx_mgw_equipped_item (item_id, equipped_at_utc),
    CONSTRAINT fk_mgw_equipped_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_equipped_inventory FOREIGN KEY (mgw_id, item_id)
        REFERENCES mgw_inventory_items (mgw_id, item_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_equipped_catalog FOREIGN KEY (item_id)
        REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function createSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_product_catalog (
    item_id TEXT NOT NULL PRIMARY KEY,
    item_type TEXT NOT NULL CHECK (item_type IN ('profile','game','bundle','seasonal','showcase')),
    item_family TEXT NOT NULL,
    equip_slot TEXT NULL,
    is_store_product INTEGER NOT NULL DEFAULT 0 CHECK (is_store_product IN (0,1)),
    starter_grant INTEGER NOT NULL DEFAULT 0 CHECK (starter_grant IN (0,1)),
    catalog_status TEXT NOT NULL DEFAULT 'active' CHECK (catalog_status IN ('active','hidden','retired')),
    metadata_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_catalog_type_status ON mgw_product_catalog (item_type, catalog_status)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_catalog_store ON mgw_product_catalog (is_store_product, catalog_status)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_inventory_items (
    mgw_id TEXT NOT NULL,
    item_id TEXT NOT NULL,
    acquired_source TEXT NOT NULL,
    acquired_ref TEXT NULL,
    acquired_at_utc TEXT NOT NULL,
    PRIMARY KEY (mgw_id, item_id),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (item_id) REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_inventory_item ON mgw_inventory_items (item_id, acquired_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_equipped_items (
    mgw_id TEXT NOT NULL,
    equip_slot TEXT NOT NULL,
    item_id TEXT NOT NULL,
    equipped_at_utc TEXT NOT NULL,
    PRIMARY KEY (mgw_id, equip_slot),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id, item_id) REFERENCES mgw_inventory_items (mgw_id, item_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (item_id) REFERENCES mgw_product_catalog (item_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_equipped_item ON mgw_equipped_items (item_id, equipped_at_utc)');
    }

    private function seedAvatarCatalogue(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::STARTER_AVATARS as $itemId) {
            $this->insertCatalogItem($database, $itemId, false, true, $now);
        }
        foreach (self::STORE_AVATARS as $itemId) {
            $this->insertCatalogItem($database, $itemId, true, false, $now);
        }
    }

    private function insertCatalogItem(
        DatabaseConnectionInterface $database,
        string $itemId,
        bool $storeProduct,
        bool $starterGrant,
        string $now
    ): void {
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $database->execute(
            $prefix . ' INTO mgw_product_catalog (
                item_id, item_type, item_family, equip_slot, is_store_product, starter_grant,
                catalog_status, metadata_json, created_at_utc, updated_at_utc
             ) VALUES (
                :item_id, :item_type, :item_family, :equip_slot, :is_store_product, :starter_grant,
                :catalog_status, NULL, :created_at, :updated_at
             )',
            [
                'item_id' => $itemId,
                'item_type' => 'profile',
                'item_family' => 'avatar',
                'equip_slot' => 'profile_avatar',
                'is_store_product' => $storeProduct ? 1 : 0,
                'starter_grant' => $starterGrant ? 1 : 0,
                'catalog_status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function backfillStarterOwnership(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach (self::STARTER_AVATARS as $itemId) {
            $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $database->execute(
                $prefix . ' INTO mgw_inventory_items (mgw_id, item_id, acquired_source, acquired_ref, acquired_at_utc)
                 SELECT mgw_id, :item_id, :source, NULL, :acquired_at FROM mgw_users',
                ['item_id' => $itemId, 'source' => 'starter_bootstrap', 'acquired_at' => $now]
            );
        }
    }

    private function backfillAvatarEquipment(DatabaseConnectionInterface $database): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $prefix = $database->driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $database->execute(
            $prefix . " INTO mgw_equipped_items (mgw_id, equip_slot, item_id, equipped_at_utc)
             SELECT mgw_id, 'profile_avatar',
                    CASE WHEN equipped_avatar_item_id IN ('starter-default-01','starter-default-02','starter-default-03')
                         THEN equipped_avatar_item_id ELSE 'starter-default-01' END,
                    :equipped_at
             FROM mgw_users",
            ['equipped_at' => $now]
        );
        $database->execute(
            "UPDATE mgw_users
             SET equipped_avatar_item_id = 'starter-default-01'
             WHERE equipped_avatar_item_id IS NULL OR equipped_avatar_item_id = ''"
        );
    }
};
