<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260926_0065_create_system_admin_control';
    }

    public function description(): string
    {
        return 'Create MVP-22.5 durable system readiness, staging acceptance and activation audit state.';
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
CREATE TABLE IF NOT EXISTS mgw_system_admin_control (
    control_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    readiness_threshold INT UNSIGNED NOT NULL DEFAULT 500,
    readiness_reached_at_utc DATETIME(6) NULL,
    readiness_acknowledged_at_utc DATETIME(6) NULL,
    readiness_acknowledged_by VARCHAR(191) COLLATE utf8mb4_bin NULL,
    staging_accepted_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    staging_accepted_by VARCHAR(191) COLLATE utf8mb4_bin NULL,
    staging_accepted_at_utc DATETIME(6) NULL,
    staging_checklist_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    staging_notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    staging_rehearsal_active TINYINT(1) NOT NULL DEFAULT 0,
    staging_rehearsal_started_at_utc DATETIME(6) NULL,
    staging_rehearsal_started_by VARCHAR(191) COLLATE utf8mb4_bin NULL,
    activation_announcement_event_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activation_announcement_sent_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_system_admin_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    reason_text VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    before_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    after_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_system_admin_audit_action (action_code, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
INSERT IGNORE INTO mgw_system_admin_control (
    control_key, readiness_threshold, updated_at_utc
) VALUES (
    'global', 500, UTC_TIMESTAMP(6)
)
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_system_admin_control (
    control_key TEXT NOT NULL PRIMARY KEY,
    readiness_threshold INTEGER NOT NULL DEFAULT 500,
    readiness_reached_at_utc TEXT NULL,
    readiness_acknowledged_at_utc TEXT NULL,
    readiness_acknowledged_by TEXT NULL,
    staging_accepted_sha TEXT NULL,
    staging_accepted_by TEXT NULL,
    staging_accepted_at_utc TEXT NULL,
    staging_checklist_json TEXT NULL,
    staging_notes TEXT NULL,
    staging_rehearsal_active INTEGER NOT NULL DEFAULT 0,
    staging_rehearsal_started_at_utc TEXT NULL,
    staging_rehearsal_started_by TEXT NULL,
    activation_announcement_event_id TEXT NULL,
    activation_announcement_sent_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_system_admin_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    action_code TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    reason_text TEXT NULL,
    before_json TEXT NOT NULL,
    after_json TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_system_admin_audit_action ON mgw_system_admin_audit (action_code, created_at_utc)');

        $database->execute(<<<'SQL'
INSERT OR IGNORE INTO mgw_system_admin_control (
    control_key, readiness_threshold, updated_at_utc
) VALUES (
    'global', 500, strftime('%Y-%m-%d %H:%M:%f', 'now')
)
SQL);
    }
};
