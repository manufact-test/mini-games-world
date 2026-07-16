<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260716_0002_create_accounts_identities_sessions';
    }

    public function description(): string
    {
        return 'Create provider-neutral MGW users, identities, devices and sessions.';
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
CREATE TABLE IF NOT EXISTS mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    display_name VARCHAR(80) NOT NULL,
    username VARCHAR(80) NULL,
    avatar_provider VARCHAR(32) NULL,
    avatar_external_ref TEXT NULL,
    avatar_storage_key VARCHAR(255) NULL,
    avatar_mime_type VARCHAR(64) NULL,
    avatar_width SMALLINT UNSIGNED NULL,
    avatar_height SMALLINT UNSIGNED NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_users_status_seen (status, last_seen_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_identities (
    identity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_subject VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    provider_username VARCHAR(80) NULL,
    linked_at_utc DATETIME(6) NOT NULL,
    last_authenticated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_identity_provider_subject (provider, provider_subject),
    UNIQUE KEY uq_mgw_identity_user_provider (mgw_id, provider),
    CONSTRAINT fk_mgw_identities_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_devices (
    device_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_device_user_hash (mgw_id, device_key_hash),
    INDEX idx_mgw_devices_user_seen (mgw_id, last_seen_at_utc),
    CONSTRAINT fk_mgw_devices_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_sessions (
    session_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at_utc DATETIME(6) NOT NULL,
    last_seen_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_sessions_user_seen (mgw_id, last_seen_at_utc),
    INDEX idx_mgw_sessions_expiry (expires_at_utc, revoked_at_utc),
    CONSTRAINT fk_mgw_sessions_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_sessions_device FOREIGN KEY (device_id)
        REFERENCES mgw_devices (device_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_users (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'active',
    display_name TEXT NOT NULL,
    username TEXT NULL,
    avatar_provider TEXT NULL,
    avatar_external_ref TEXT NULL,
    avatar_storage_key TEXT NULL,
    avatar_mime_type TEXT NULL,
    avatar_width INTEGER NULL,
    avatar_height INTEGER NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_users_status_seen ON mgw_users (status, last_seen_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_identities (
    identity_id INTEGER PRIMARY KEY AUTOINCREMENT,
    mgw_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    provider_subject TEXT NOT NULL,
    provider_username TEXT NULL,
    linked_at_utc TEXT NOT NULL,
    last_authenticated_at_utc TEXT NOT NULL,
    UNIQUE (provider, provider_subject),
    UNIQUE (mgw_id, provider),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_devices (
    device_id INTEGER PRIMARY KEY AUTOINCREMENT,
    mgw_id TEXT NOT NULL,
    device_key_hash TEXT NOT NULL,
    platform TEXT NOT NULL,
    first_seen_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL,
    UNIQUE (mgw_id, device_key_hash),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_devices_user_seen ON mgw_devices (mgw_id, last_seen_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_sessions (
    session_key_hash TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    device_id INTEGER NULL,
    provider TEXT NOT NULL,
    issued_at_utc TEXT NOT NULL,
    last_seen_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    FOREIGN KEY (device_id) REFERENCES mgw_devices (device_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_sessions_user_seen ON mgw_sessions (mgw_id, last_seen_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_sessions_expiry ON mgw_sessions (expires_at_utc, revoked_at_utc)');
    }
};
