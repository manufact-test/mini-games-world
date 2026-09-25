<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260925_0062_create_compensations';
    }

    public function description(): string
    {
        return 'Create MVP-22.2 compensation workflow audit linked to canonical ledger operations.';
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
CREATE TABLE IF NOT EXISTS mgw_compensations (
    compensation_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    request_token VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    original_entry_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_operation_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    original_entry_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_category VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_source_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount BIGINT NOT NULL,
    reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    requires_second_confirmation TINYINT(1) NOT NULL DEFAULT 0,
    status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_by_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    confirmed_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    ledger_operation_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    ledger_entry_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    available_before BIGINT NULL,
    available_after BIGINT NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    confirmed_at_utc DATETIME(6) NULL,
    applied_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_compensation_request_token (request_token),
    UNIQUE KEY uq_mgw_compensation_ledger_operation (ledger_operation_key),
    UNIQUE KEY uq_mgw_compensation_ledger_entry (ledger_entry_id),
    INDEX idx_mgw_compensation_original_entry (original_entry_id, requested_at_utc),
    INDEX idx_mgw_compensation_account (account_ref, requested_at_utc),
    INDEX idx_mgw_compensation_mgw (mgw_id, requested_at_utc),
    INDEX idx_mgw_compensation_status (status_code, updated_at_utc),
    CONSTRAINT chk_mgw_compensation_amount_positive CHECK (amount > 0),
    CONSTRAINT fk_mgw_compensation_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_compensation_original_entry FOREIGN KEY (original_entry_id)
        REFERENCES mgw_ledger_entries (entry_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_compensation_ledger_entry FOREIGN KEY (ledger_entry_id)
        REFERENCES mgw_ledger_entries (entry_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_compensations (
    compensation_id TEXT NOT NULL PRIMARY KEY,
    request_token TEXT NOT NULL UNIQUE,
    original_entry_id TEXT NOT NULL,
    original_operation_key TEXT NOT NULL,
    original_entry_sha256 TEXT NOT NULL,
    original_category TEXT NOT NULL,
    original_source_type TEXT NOT NULL,
    original_source_ref TEXT NULL,
    account_ref TEXT NOT NULL,
    mgw_id TEXT NULL,
    legacy_user_id TEXT NULL,
    asset_code TEXT NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    reason TEXT NOT NULL,
    requires_second_confirmation INTEGER NOT NULL DEFAULT 0,
    status_code TEXT NOT NULL,
    requested_by_ref TEXT NOT NULL,
    confirmed_by_ref TEXT NULL,
    ledger_operation_key TEXT NOT NULL UNIQUE,
    ledger_entry_id TEXT NULL UNIQUE,
    available_before INTEGER NULL,
    available_after INTEGER NULL,
    requested_at_utc TEXT NOT NULL,
    confirmed_at_utc TEXT NULL,
    applied_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    FOREIGN KEY (original_entry_id) REFERENCES mgw_ledger_entries (entry_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (ledger_entry_id) REFERENCES mgw_ledger_entries (entry_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_compensation_original_entry ON mgw_compensations (original_entry_id, requested_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_compensation_account ON mgw_compensations (account_ref, requested_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_compensation_mgw ON mgw_compensations (mgw_id, requested_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_compensation_status ON mgw_compensations (status_code, updated_at_utc)');
    }
};
