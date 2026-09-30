<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260930_0070_create_account_link_challenges';
    }

    public function description(): string
    {
        return 'Create one-time Android-to-Telegram account-link challenge and audit lifecycle.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_account_link_challenges (
    challenge_id TEXT NOT NULL PRIMARY KEY,
    token_sha256 TEXT NOT NULL UNIQUE,
    source_mgw_id TEXT NOT NULL,
    source_android_subject TEXT NOT NULL,
    source_legacy_user_id TEXT NOT NULL,
    target_mgw_id TEXT NULL,
    target_telegram_subject TEXT NULL,
    target_legacy_user_id TEXT NULL,
    link_status TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NOT NULL,
    claimed_at_utc TEXT NULL,
    confirmed_at_utc TEXT NULL,
    db_linked_at_utc TEXT NULL,
    finalized_at_utc TEXT NULL,
    cancelled_at_utc TEXT NULL,
    retired_balance_amount INTEGER NOT NULL DEFAULT 0,
    last_error_code TEXT NULL,
    CHECK (link_status IN ('pending','claimed','confirmed','db_linked','linked','cancelled','expired')),
    FOREIGN KEY (source_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (target_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_account_link_source_status '
                . 'ON mgw_account_link_challenges (source_mgw_id, link_status, created_at_utc)'
            );
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_account_link_expiry '
                . 'ON mgw_account_link_challenges (link_status, expires_at_utc)'
            );
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_account_link_target '
                . 'ON mgw_account_link_challenges (target_mgw_id, link_status, created_at_utc)'
            );
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_account_link_challenges (
    challenge_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_android_subject CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    target_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_telegram_subject VARCHAR(191) COLLATE utf8mb4_bin NULL,
    target_legacy_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
    link_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    claimed_at_utc DATETIME(6) NULL,
    confirmed_at_utc DATETIME(6) NULL,
    db_linked_at_utc DATETIME(6) NULL,
    finalized_at_utc DATETIME(6) NULL,
    cancelled_at_utc DATETIME(6) NULL,
    retired_balance_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY uq_mgw_account_link_token (token_sha256),
    INDEX idx_mgw_account_link_source_status (source_mgw_id, link_status, created_at_utc),
    INDEX idx_mgw_account_link_expiry (link_status, expires_at_utc),
    INDEX idx_mgw_account_link_target (target_mgw_id, link_status, created_at_utc),
    CONSTRAINT chk_mgw_account_link_status CHECK (
        link_status IN ('pending','claimed','confirmed','db_linked','linked','cancelled','expired')
    ),
    CONSTRAINT fk_mgw_account_link_source FOREIGN KEY (source_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_account_link_target FOREIGN KEY (target_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
