<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface
{
    public function version(): string
    {
        return '20260819_0010_create_match_event_log';
    }

    public function description(): string
    {
        return 'Create compact authoritative match event log for replay storage';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(
                'CREATE TABLE IF NOT EXISTS mgw_match_events (
                    event_id TEXT PRIMARY KEY,
                    match_id TEXT NOT NULL,
                    primary_revision INTEGER NOT NULL,
                    event_ordinal INTEGER NOT NULL,
                    snapshot_state_version INTEGER NOT NULL,
                    event_type TEXT NOT NULL,
                    occurred_at_utc TEXT NOT NULL,
                    actor_user_id TEXT NULL,
                    game_type TEXT NOT NULL,
                    rules_version TEXT NOT NULL,
                    engine_version TEXT NOT NULL,
                    payload_json TEXT NOT NULL,
                    before_state_sha256 TEXT NULL,
                    after_state_sha256 TEXT NOT NULL,
                    retention_class TEXT NOT NULL,
                    retain_until_utc TEXT NULL,
                    created_at_utc TEXT NOT NULL,
                    UNIQUE (match_id, primary_revision, event_ordinal)
                )'
            );
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_match_events_match_order
                 ON mgw_match_events (match_id, primary_revision, event_ordinal)'
            );
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_match_events_retention
                 ON mgw_match_events (retention_class, retain_until_utc)'
            );
            return;
        }

        if ($database->driver() !== 'mysql') {
            throw new RuntimeException('Match event log migration supports only MySQL or SQLite.');
        }

        $database->execute(
            'CREATE TABLE IF NOT EXISTS mgw_match_events (
                event_id CHAR(64) NOT NULL,
                match_id VARCHAR(191) NOT NULL,
                primary_revision BIGINT UNSIGNED NOT NULL,
                event_ordinal SMALLINT UNSIGNED NOT NULL,
                snapshot_state_version BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                occurred_at_utc VARCHAR(40) NOT NULL,
                actor_user_id VARCHAR(191) NULL,
                game_type VARCHAR(60) NOT NULL,
                rules_version CHAR(64) NOT NULL,
                engine_version CHAR(64) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                before_state_sha256 CHAR(64) NULL,
                after_state_sha256 CHAR(64) NOT NULL,
                retention_class VARCHAR(40) NOT NULL,
                retain_until_utc VARCHAR(40) NULL,
                created_at_utc VARCHAR(40) NOT NULL,
                PRIMARY KEY (event_id),
                UNIQUE KEY uq_mgw_match_events_order (match_id, primary_revision, event_ordinal),
                KEY idx_mgw_match_events_match_order (match_id, primary_revision, event_ordinal),
                KEY idx_mgw_match_events_retention (retention_class, retain_until_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
};
