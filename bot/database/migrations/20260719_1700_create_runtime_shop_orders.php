<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260719_1700_create_runtime_shop_orders';
    }

    public function description(): string
    {
        return 'Create mutable staging shop runtime order mirror';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'mysql') {
            $database->execute(
                'CREATE TABLE IF NOT EXISTS mgw_runtime_shop_orders (
                    order_ref VARCHAR(191) NOT NULL,
                    account_ref VARCHAR(255) NOT NULL,
                    legacy_user_id VARCHAR(191) NULL,
                    client_request_id VARCHAR(191) NULL,
                    status_raw VARCHAR(64) NOT NULL,
                    refund_done TINYINT(1) NOT NULL DEFAULT 0,
                    gold_amount BIGINT NOT NULL DEFAULT 0,
                    item_id VARCHAR(191) NULL,
                    denomination_id VARCHAR(191) NULL,
                    payload_json LONGTEXT NOT NULL,
                    payload_sha256 CHAR(64) NOT NULL,
                    source_created_at_utc DATETIME(6) NULL,
                    source_updated_at_utc DATETIME(6) NULL,
                    synced_at_utc DATETIME(6) NOT NULL,
                    PRIMARY KEY (order_ref),
                    KEY idx_mgw_runtime_shop_account (account_ref),
                    KEY idx_mgw_runtime_shop_status (status_raw),
                    KEY idx_mgw_runtime_shop_legacy_user (legacy_user_id),
                    UNIQUE KEY uq_mgw_runtime_shop_request (account_ref, client_request_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            return;
        }

        $database->execute(
            'CREATE TABLE IF NOT EXISTS mgw_runtime_shop_orders (
                order_ref TEXT NOT NULL PRIMARY KEY,
                account_ref TEXT NOT NULL,
                legacy_user_id TEXT NULL,
                client_request_id TEXT NULL,
                status_raw TEXT NOT NULL,
                refund_done INTEGER NOT NULL DEFAULT 0,
                gold_amount INTEGER NOT NULL DEFAULT 0,
                item_id TEXT NULL,
                denomination_id TEXT NULL,
                payload_json TEXT NOT NULL,
                payload_sha256 TEXT NOT NULL,
                source_created_at_utc TEXT NULL,
                source_updated_at_utc TEXT NULL,
                synced_at_utc TEXT NOT NULL
            )'
        );
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_shop_account ON mgw_runtime_shop_orders (account_ref)'
        );
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_shop_status ON mgw_runtime_shop_orders (status_raw)'
        );
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_shop_legacy_user ON mgw_runtime_shop_orders (legacy_user_id)'
        );
        $database->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_mgw_runtime_shop_request '
            . 'ON mgw_runtime_shop_orders (account_ref, client_request_id)'
        );
    }
};
