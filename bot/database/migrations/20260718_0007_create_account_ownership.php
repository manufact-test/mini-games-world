<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260718_0007_create_account_ownership';
    }

    public function description(): string
    {
        return 'Create stable MGW account ownership mapping without rewriting immutable ledger account references.';
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
CREATE TABLE IF NOT EXISTS mgw_account_ownership (
    account_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_subject VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    ownership_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy_json',
    linked_at_utc DATETIME(6) NOT NULL,
    verified_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_account_ownership_user (mgw_id),
    UNIQUE KEY uq_mgw_account_ownership_legacy_user (legacy_user_id),
    UNIQUE KEY uq_mgw_account_ownership_provider_subject (provider, provider_subject),
    INDEX idx_mgw_account_ownership_status (ownership_status, verified_at_utc),
    CONSTRAINT fk_mgw_account_ownership_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_account_ownership (
    account_ref TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    legacy_user_id TEXT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL,
    ownership_status TEXT NOT NULL DEFAULT 'active',
    source_type TEXT NOT NULL DEFAULT 'legacy_json',
    linked_at_utc TEXT NOT NULL,
    verified_at_utc TEXT NOT NULL,
    UNIQUE (mgw_id),
    UNIQUE (legacy_user_id),
    UNIQUE (provider, provider_subject),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_account_ownership_status '
            . 'ON mgw_account_ownership (ownership_status, verified_at_utc)'
        );
    }
};
