<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260717_0005_create_balances_ledger_reservations';
    }

    public function description(): string
    {
        return 'Create balances, append-only ledger, reservations and idempotency foundations without runtime cutover.';
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
CREATE TABLE IF NOT EXISTS mgw_balances (
    balance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    available_amount BIGINT NOT NULL DEFAULT 0,
    reserved_amount BIGINT NOT NULL DEFAULT 0,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_balances_account_asset (account_ref, asset_code),
    INDEX idx_mgw_balances_mgw (mgw_id, asset_code),
    INDEX idx_mgw_balances_legacy (legacy_user_id, asset_code),
    CONSTRAINT chk_mgw_balances_available_nonnegative CHECK (available_amount >= 0),
    CONSTRAINT chk_mgw_balances_reserved_nonnegative CHECK (reserved_amount >= 0),
    CONSTRAINT fk_mgw_balances_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_idempotency_keys (
    operation_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    operation_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_ref VARCHAR(255) COLLATE utf8mb4_bin NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_idempotency_status_expiry (status, expires_at_utc),
    INDEX idx_mgw_idempotency_owner_created (owner_ref, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_reservations (
    reservation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    idempotency_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount BIGINT NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    metadata_json JSON NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    consumed_at_utc DATETIME(6) NULL,
    released_at_utc DATETIME(6) NULL,
    UNIQUE KEY uq_mgw_reservations_idempotency (idempotency_key),
    INDEX idx_mgw_reservations_account_status (account_ref, asset_code, status, updated_at_utc),
    INDEX idx_mgw_reservations_expiry (status, expires_at_utc),
    INDEX idx_mgw_reservations_source (source_type, source_ref),
    CONSTRAINT chk_mgw_reservations_amount_positive CHECK (amount > 0),
    CONSTRAINT fk_mgw_reservations_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_ledger_entries (
    ledger_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    available_delta BIGINT NOT NULL DEFAULT 0,
    reserved_delta BIGINT NOT NULL DEFAULT 0,
    available_before BIGINT NOT NULL,
    available_after BIGINT NOT NULL,
    reserved_before BIGINT NOT NULL,
    reserved_after BIGINT NOT NULL,
    category VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    reservation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NULL,
    previous_entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_ledger_entry_id (entry_id),
    UNIQUE KEY uq_mgw_ledger_idempotency (idempotency_key),
    INDEX idx_mgw_ledger_account_asset (account_ref, asset_code, ledger_sequence),
    INDEX idx_mgw_ledger_mgw_created (mgw_id, created_at_utc),
    INDEX idx_mgw_ledger_legacy_created (legacy_user_id, created_at_utc),
    INDEX idx_mgw_ledger_source (source_type, source_ref),
    INDEX idx_mgw_ledger_integrity (account_ref, asset_code, entry_sha256),
    CONSTRAINT chk_mgw_ledger_available_before_nonnegative CHECK (available_before >= 0),
    CONSTRAINT chk_mgw_ledger_available_after_nonnegative CHECK (available_after >= 0),
    CONSTRAINT chk_mgw_ledger_reserved_before_nonnegative CHECK (reserved_before >= 0),
    CONSTRAINT chk_mgw_ledger_reserved_after_nonnegative CHECK (reserved_after >= 0),
    CONSTRAINT chk_mgw_ledger_available_math CHECK (available_after = available_before + available_delta),
    CONSTRAINT chk_mgw_ledger_reserved_math CHECK (reserved_after = reserved_before + reserved_delta),
    CONSTRAINT fk_mgw_ledger_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_ledger_reservation FOREIGN KEY (reservation_id)
        REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_reservation_events (
    event_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reservation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    available_delta BIGINT NOT NULL DEFAULT 0,
    reserved_delta BIGINT NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    event_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_reservation_event_id (event_id),
    UNIQUE KEY uq_mgw_reservation_event_key (reservation_id, event_key),
    INDEX idx_mgw_reservation_events_created (reservation_id, created_at_utc),
    CONSTRAINT fk_mgw_reservation_events_reservation FOREIGN KEY (reservation_id)
        REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_balances (
    balance_id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    asset_code TEXT NOT NULL,
    available_amount INTEGER NOT NULL DEFAULT 0 CHECK (available_amount >= 0),
    reserved_amount INTEGER NOT NULL DEFAULT 0 CHECK (reserved_amount >= 0),
    version INTEGER NOT NULL DEFAULT 0,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    UNIQUE (account_ref, asset_code),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_balances_mgw ON mgw_balances (mgw_id, asset_code)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_balances_legacy ON mgw_balances (legacy_user_id, asset_code)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_idempotency_keys (
    operation_key TEXT NOT NULL PRIMARY KEY,
    operation_type TEXT NOT NULL,
    owner_ref TEXT NULL,
    request_sha256 TEXT NOT NULL,
    status TEXT NOT NULL,
    result_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_idempotency_status_expiry ON mgw_idempotency_keys (status, expires_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_idempotency_owner_created ON mgw_idempotency_keys (owner_ref, created_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_reservations (
    reservation_id TEXT NOT NULL PRIMARY KEY,
    idempotency_key TEXT NOT NULL UNIQUE,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    asset_code TEXT NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    status TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NULL,
    metadata_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL,
    consumed_at_utc TEXT NULL,
    released_at_utc TEXT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_reservations_account_status ON mgw_reservations (account_ref, asset_code, status, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_reservations_expiry ON mgw_reservations (status, expires_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_reservations_source ON mgw_reservations (source_type, source_ref)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_ledger_entries (
    ledger_sequence INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id TEXT NOT NULL UNIQUE,
    idempotency_key TEXT NOT NULL UNIQUE,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    asset_code TEXT NOT NULL,
    available_delta INTEGER NOT NULL DEFAULT 0,
    reserved_delta INTEGER NOT NULL DEFAULT 0,
    available_before INTEGER NOT NULL CHECK (available_before >= 0),
    available_after INTEGER NOT NULL CHECK (available_after >= 0),
    reserved_before INTEGER NOT NULL CHECK (reserved_before >= 0),
    reserved_after INTEGER NOT NULL CHECK (reserved_after >= 0),
    category TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NULL,
    reservation_id TEXT NULL,
    metadata_json TEXT NULL,
    previous_entry_sha256 TEXT NULL,
    entry_sha256 TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    CHECK (available_after = available_before + available_delta),
    CHECK (reserved_after = reserved_before + reserved_delta),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    FOREIGN KEY (reservation_id) REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_ledger_account_asset ON mgw_ledger_entries (account_ref, asset_code, ledger_sequence)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_ledger_mgw_created ON mgw_ledger_entries (mgw_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_ledger_legacy_created ON mgw_ledger_entries (legacy_user_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_ledger_source ON mgw_ledger_entries (source_type, source_ref)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_ledger_integrity ON mgw_ledger_entries (account_ref, asset_code, entry_sha256)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_reservation_events (
    event_sequence INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    reservation_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    event_type TEXT NOT NULL,
    available_delta INTEGER NOT NULL DEFAULT 0,
    reserved_delta INTEGER NOT NULL DEFAULT 0,
    metadata_json TEXT NULL,
    event_sha256 TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    UNIQUE (reservation_id, event_key),
    FOREIGN KEY (reservation_id) REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_reservation_events_created ON mgw_reservation_events (reservation_id, created_at_utc)');
    }
};
