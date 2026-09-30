<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20261001_0071_create_android_reauth_challenges';
    }

    public function description(): string
    {
        return 'Create short-lived Android sensitive-action reauthentication challenges.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_android_reauth_challenges (
    challenge_id TEXT NOT NULL PRIMARY KEY,
    mgw_id TEXT NOT NULL,
    android_subject TEXT NOT NULL,
    session_key_hash TEXT NOT NULL,
    challenge_status TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NOT NULL,
    confirmed_at_utc TEXT NULL,
    grant_expires_at_utc TEXT NULL,
    cancelled_at_utc TEXT NULL,
    CHECK (challenge_status IN ('pending','confirmed','cancelled','expired')),
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_android_reauth_session '
                . 'ON mgw_android_reauth_challenges (session_key_hash, challenge_status, grant_expires_at_utc)'
            );
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_android_reauth_user_created '
                . 'ON mgw_android_reauth_challenges (mgw_id, created_at_utc)'
            );
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_android_reauth_challenges (
    challenge_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    android_subject CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    challenge_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    confirmed_at_utc DATETIME(6) NULL,
    grant_expires_at_utc DATETIME(6) NULL,
    cancelled_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_android_reauth_session (session_key_hash, challenge_status, grant_expires_at_utc),
    INDEX idx_mgw_android_reauth_user_created (mgw_id, created_at_utc),
    CONSTRAINT chk_mgw_android_reauth_status CHECK (
        challenge_status IN ('pending','confirmed','cancelled','expired')
    ),
    CONSTRAINT fk_mgw_android_reauth_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
