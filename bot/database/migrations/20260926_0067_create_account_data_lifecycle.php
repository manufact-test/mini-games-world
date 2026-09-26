<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260926_0067_create_account_data_lifecycle';
    }

    public function description(): string
    {
        return 'Create MVP-22.8 account deletion/data-export request lifecycle without replacing existing account, identity or audit owners.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_account_data_requests (
    request_id TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    request_type TEXT NOT NULL,
    request_status TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NOT NULL,
    requested_at_utc TEXT NOT NULL,
    execute_after_utc TEXT NULL,
    completed_at_utc TEXT NULL,
    cancelled_at_utc TEXT NULL,
    artifact_name TEXT NULL,
    artifact_sha256 TEXT NULL,
    artifact_size INTEGER NULL,
    artifact_expires_at_utc TEXT NULL,
    last_error TEXT NULL,
    metadata_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_account_data_user_type ON mgw_account_data_requests (mgw_id, request_type, requested_at_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_account_data_due ON mgw_account_data_requests (request_type, request_status, execute_after_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_account_data_artifact_expiry ON mgw_account_data_requests (request_type, request_status, artifact_expires_at_utc)');
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_deleted_identity_tombstones (
    provider TEXT NOT NULL,
    provider_subject_hmac TEXT NOT NULL,
    deletion_request_id TEXT NOT NULL,
    block_until_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (provider, provider_subject_hmac),
    FOREIGN KEY (deletion_request_id) REFERENCES mgw_account_data_requests (request_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_deleted_identity_tombstone_expiry ON mgw_deleted_identity_tombstones (block_until_utc)');
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_account_data_requests (
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    execute_after_utc DATETIME(6) NULL,
    completed_at_utc DATETIME(6) NULL,
    cancelled_at_utc DATETIME(6) NULL,
    artifact_name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    artifact_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    artifact_size BIGINT UNSIGNED NULL,
    artifact_expires_at_utc DATETIME(6) NULL,
    last_error TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    metadata_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_account_data_user_type (mgw_id, request_type, requested_at_utc),
    INDEX idx_mgw_account_data_due (request_type, request_status, execute_after_utc),
    INDEX idx_mgw_account_data_artifact_expiry (request_type, request_status, artifact_expires_at_utc),
    CONSTRAINT fk_mgw_account_data_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_deleted_identity_tombstones (
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_subject_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    deletion_request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    block_until_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (provider, provider_subject_hmac),
    INDEX idx_mgw_deleted_identity_tombstone_expiry (block_until_utc),
    CONSTRAINT fk_mgw_deleted_identity_tombstone_request FOREIGN KEY (deletion_request_id)
        REFERENCES mgw_account_data_requests (request_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
