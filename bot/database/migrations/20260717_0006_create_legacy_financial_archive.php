<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260717_0006_create_legacy_financial_archive';
    }

    public function description(): string
    {
        return 'Create historical legacy payment, shop order and related transaction archives without runtime cutover.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_payments (
    legacy_payment_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    provider VARCHAR(64) COLLATE utf8mb4_bin NULL,
    status_raw VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    status_normalized VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    room_raw VARCHAR(32) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    coin_amount BIGINT NULL,
    fiat_amount_minor BIGINT NULL,
    currency VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    balance_applied TINYINT(1) NOT NULL DEFAULT 0,
    source_created_at_utc DATETIME(6) NULL,
    source_updated_at_utc DATETIME(6) NULL,
    source_decided_at_utc DATETIME(6) NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    archive_batch_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_index BIGINT UNSIGNED NOT NULL,
    archived_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_legacy_payments_source (source_file, source_index),
    INDEX idx_mgw_legacy_payments_account (account_ref, source_created_at_utc),
    INDEX idx_mgw_legacy_payments_status (status_normalized, source_created_at_utc),
    INDEX idx_mgw_legacy_payments_hash (snapshot_sha256),
    CONSTRAINT chk_mgw_legacy_payments_status CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown')),
    CONSTRAINT chk_mgw_legacy_payments_applied CHECK (balance_applied IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_shop_orders (
    legacy_order_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    status_raw VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    status_normalized VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    refund_done TINYINT(1) NOT NULL DEFAULT 0,
    gold_amount BIGINT NULL,
    item_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    denomination_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    provider VARCHAR(191) COLLATE utf8mb4_bin NULL,
    country_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_created_at_utc DATETIME(6) NULL,
    source_updated_at_utc DATETIME(6) NULL,
    source_decided_at_utc DATETIME(6) NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    archive_batch_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_index BIGINT UNSIGNED NOT NULL,
    archived_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_legacy_orders_source (source_file, source_index),
    INDEX idx_mgw_legacy_orders_account (account_ref, source_created_at_utc),
    INDEX idx_mgw_legacy_orders_status (status_normalized, source_created_at_utc),
    INDEX idx_mgw_legacy_orders_hash (snapshot_sha256),
    CONSTRAINT chk_mgw_legacy_orders_status CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown')),
    CONSTRAINT chk_mgw_legacy_orders_refund CHECK (refund_done IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_financial_transactions (
    legacy_transaction_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    legacy_payment_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    legacy_order_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    type_raw VARCHAR(64) COLLATE utf8mb4_bin NULL,
    category_raw VARCHAR(64) COLLATE utf8mb4_bin NULL,
    status_normalized VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    room_raw VARCHAR(32) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    amount BIGINT NULL,
    balance_before BIGINT NULL,
    balance_after BIGINT NULL,
    fiat_amount_minor BIGINT NULL,
    currency VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_created_at_utc DATETIME(6) NULL,
    snapshot_json JSON NOT NULL,
    snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    archive_batch_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_file VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_index BIGINT UNSIGNED NOT NULL,
    archived_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_legacy_financial_tx_source (source_file, source_index),
    INDEX idx_mgw_legacy_financial_tx_account (account_ref, source_created_at_utc),
    INDEX idx_mgw_legacy_financial_tx_payment (legacy_payment_id),
    INDEX idx_mgw_legacy_financial_tx_order (legacy_order_id),
    INDEX idx_mgw_legacy_financial_tx_hash (snapshot_sha256),
    CONSTRAINT chk_mgw_legacy_financial_tx_status CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_payments (
    legacy_payment_id TEXT NOT NULL PRIMARY KEY,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    provider TEXT NULL,
    status_raw TEXT NOT NULL,
    status_normalized TEXT NOT NULL CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown')),
    room_raw TEXT NULL,
    asset_code TEXT NULL,
    coin_amount INTEGER NULL,
    fiat_amount_minor INTEGER NULL,
    currency TEXT NULL,
    balance_applied INTEGER NOT NULL DEFAULT 0 CHECK (balance_applied IN (0, 1)),
    source_created_at_utc TEXT NULL,
    source_updated_at_utc TEXT NULL,
    source_decided_at_utc TEXT NULL,
    snapshot_json TEXT NOT NULL,
    snapshot_sha256 TEXT NOT NULL,
    archive_batch_id TEXT NOT NULL,
    source_file TEXT NOT NULL,
    source_index INTEGER NOT NULL CHECK (source_index >= 0),
    archived_at_utc TEXT NOT NULL,
    UNIQUE (source_file, source_index)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_payments_account ON mgw_legacy_payments (account_ref, source_created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_payments_status ON mgw_legacy_payments (status_normalized, source_created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_payments_hash ON mgw_legacy_payments (snapshot_sha256)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_shop_orders (
    legacy_order_id TEXT NOT NULL PRIMARY KEY,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    status_raw TEXT NOT NULL,
    status_normalized TEXT NOT NULL CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown')),
    refund_done INTEGER NOT NULL DEFAULT 0 CHECK (refund_done IN (0, 1)),
    gold_amount INTEGER NULL,
    item_id TEXT NULL,
    denomination_id TEXT NULL,
    provider TEXT NULL,
    country_code TEXT NULL,
    source_created_at_utc TEXT NULL,
    source_updated_at_utc TEXT NULL,
    source_decided_at_utc TEXT NULL,
    snapshot_json TEXT NOT NULL,
    snapshot_sha256 TEXT NOT NULL,
    archive_batch_id TEXT NOT NULL,
    source_file TEXT NOT NULL,
    source_index INTEGER NOT NULL CHECK (source_index >= 0),
    archived_at_utc TEXT NOT NULL,
    UNIQUE (source_file, source_index)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_orders_account ON mgw_legacy_shop_orders (account_ref, source_created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_orders_status ON mgw_legacy_shop_orders (status_normalized, source_created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_orders_hash ON mgw_legacy_shop_orders (snapshot_sha256)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_financial_transactions (
    legacy_transaction_id TEXT NOT NULL PRIMARY KEY,
    account_ref TEXT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    legacy_payment_id TEXT NULL,
    legacy_order_id TEXT NULL,
    type_raw TEXT NULL,
    category_raw TEXT NULL,
    status_normalized TEXT NOT NULL CHECK (status_normalized IN ('pending', 'completed', 'rejected', 'cancelled', 'unknown')),
    room_raw TEXT NULL,
    asset_code TEXT NULL,
    amount INTEGER NULL,
    balance_before INTEGER NULL,
    balance_after INTEGER NULL,
    fiat_amount_minor INTEGER NULL,
    currency TEXT NULL,
    source_created_at_utc TEXT NULL,
    snapshot_json TEXT NOT NULL,
    snapshot_sha256 TEXT NOT NULL,
    archive_batch_id TEXT NOT NULL,
    source_file TEXT NOT NULL,
    source_index INTEGER NOT NULL CHECK (source_index >= 0),
    archived_at_utc TEXT NOT NULL,
    UNIQUE (source_file, source_index)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_financial_tx_account ON mgw_legacy_financial_transactions (account_ref, source_created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_financial_tx_payment ON mgw_legacy_financial_transactions (legacy_payment_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_financial_tx_order ON mgw_legacy_financial_transactions (legacy_order_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_legacy_financial_tx_hash ON mgw_legacy_financial_transactions (snapshot_sha256)');
    }
};
