<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260925_0063_create_moderation_restrictions_appeals';
    }

    public function description(): string
    {
        return 'Create MVP-22.3 moderation actions, restriction/ban review, appeals and audit trail.';
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
CREATE TABLE IF NOT EXISTS mgw_moderation_actions (
    action_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    report_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    scope_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    starts_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_admin_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    second_review_by_admin_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    second_review_note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    second_reviewed_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_moderation_target (target_mgw_id, status_code, created_at_utc),
    INDEX idx_mgw_moderation_report (report_id, created_at_utc),
    INDEX idx_mgw_moderation_scope (target_mgw_id, scope_code, status_code, expires_at_utc),
    CONSTRAINT fk_mgw_moderation_action_report FOREIGN KEY (report_id)
        REFERENCES mgw_player_reports (report_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_moderation_action_user FOREIGN KEY (target_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_moderation_appeals (
    appeal_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    action_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message VARCHAR(1200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewed_by_admin_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    review_note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    reviewed_at_utc DATETIME(6) NULL,
    INDEX idx_mgw_moderation_appeal_user (target_mgw_id, status_code, created_at_utc),
    INDEX idx_mgw_moderation_appeal_action (action_id, created_at_utc),
    CONSTRAINT fk_mgw_moderation_appeal_action FOREIGN KEY (action_id)
        REFERENCES mgw_moderation_actions (action_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_moderation_appeal_user FOREIGN KEY (target_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_moderation_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    payload_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_moderation_events_entity (entity_type, entity_id, event_id),
    INDEX idx_mgw_moderation_events_created (created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_moderation_actions (
    action_id TEXT NOT NULL PRIMARY KEY,
    report_id TEXT NULL,
    target_mgw_id TEXT NOT NULL,
    action_type TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    note TEXT NOT NULL,
    scope_code TEXT NULL,
    starts_at_utc TEXT NOT NULL,
    expires_at_utc TEXT NULL,
    status_code TEXT NOT NULL,
    created_by_admin_ref TEXT NOT NULL,
    second_review_by_admin_ref TEXT NULL,
    second_review_note TEXT NULL,
    second_reviewed_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES mgw_player_reports (report_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    FOREIGN KEY (target_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_target ON mgw_moderation_actions (target_mgw_id, status_code, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_report ON mgw_moderation_actions (report_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_scope ON mgw_moderation_actions (target_mgw_id, scope_code, status_code, expires_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_moderation_appeals (
    appeal_id TEXT NOT NULL PRIMARY KEY,
    action_id TEXT NOT NULL,
    target_mgw_id TEXT NOT NULL,
    message TEXT NOT NULL,
    status_code TEXT NOT NULL,
    reviewed_by_admin_ref TEXT NULL,
    review_note TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    reviewed_at_utc TEXT NULL,
    FOREIGN KEY (action_id) REFERENCES mgw_moderation_actions (action_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (target_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_appeal_user ON mgw_moderation_appeals (target_mgw_id, status_code, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_appeal_action ON mgw_moderation_appeals (action_id, created_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_moderation_events (
    event_id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    payload_json TEXT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_events_entity ON mgw_moderation_events (entity_type, entity_id, event_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_moderation_events_created ON mgw_moderation_events (created_at_utc)');
    }
};
