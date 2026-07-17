<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260717_0004_create_legacy_realtime_shadow';
    }

    public function description(): string
    {
        return 'Create an exact server-only shadow copy of legacy realtime JSON records.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $sql = $database->driver() === 'sqlite'
            ? <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_realtime_shadow (
    entity_type TEXT NOT NULL,
    entity_key TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    payload_sha256 TEXT NOT NULL,
    source_updated_at_utc TEXT NULL,
    synced_at_utc TEXT NOT NULL,
    PRIMARY KEY (entity_type, entity_key)
)
SQL
            : <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_legacy_realtime_shadow (
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    payload_json JSON NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_updated_at_utc DATETIME(6) NULL,
    synced_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (entity_type, entity_key),
    INDEX idx_mgw_legacy_shadow_hash (entity_type, payload_sha256),
    INDEX idx_mgw_legacy_shadow_synced (synced_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $database->execute($sql);
    }
};
