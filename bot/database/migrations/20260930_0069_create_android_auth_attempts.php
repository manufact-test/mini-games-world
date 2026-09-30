<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260930_0069_create_android_auth_attempts';
    }

    public function description(): string
    {
        return 'Create bounded staging Android device-auth attempt telemetry without storing raw IPs or credentials.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_android_auth_attempts (
    attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope_code TEXT NOT NULL,
    actor_hash TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_android_auth_attempts_actor
                 ON mgw_android_auth_attempts (scope_code, actor_hash, created_at_utc)'
            );
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_android_auth_attempts (
    attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_android_auth_attempts_actor (scope_code, actor_hash, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
