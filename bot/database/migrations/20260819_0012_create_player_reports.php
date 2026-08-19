<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260819_0012_create_player_reports';
    }

    public function description(): string
    {
        return 'Create the minimal canonical player report moderation queue.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_player_reports (
    report_id TEXT NOT NULL PRIMARY KEY,
    reporter_mgw_id TEXT NOT NULL,
    target_mgw_id TEXT NOT NULL,
    reason TEXT NOT NULL,
    details TEXT NULL,
    related_match_id TEXT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    reviewed_at_utc TEXT NULL,
    resolved_at_utc TEXT NULL,
    last_admin_ref TEXT NULL,
    FOREIGN KEY (reporter_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (target_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (related_match_id) REFERENCES mgw_matches (match_id) ON DELETE SET NULL ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_player_reports_queue ON mgw_player_reports (status, created_at_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_player_reports_reporter ON mgw_player_reports (reporter_mgw_id, created_at_utc)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_player_reports_target ON mgw_player_reports (target_mgw_id, created_at_utc)');
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_player_reports (
    report_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    reporter_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    details VARCHAR(800) NULL,
    related_match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    reviewed_at_utc DATETIME(6) NULL,
    resolved_at_utc DATETIME(6) NULL,
    last_admin_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    INDEX idx_mgw_player_reports_queue (status, created_at_utc),
    INDEX idx_mgw_player_reports_reporter (reporter_mgw_id, created_at_utc),
    INDEX idx_mgw_player_reports_target (target_mgw_id, created_at_utc),
    CONSTRAINT fk_mgw_player_reports_reporter FOREIGN KEY (reporter_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_player_reports_target FOREIGN KEY (target_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_player_reports_match FOREIGN KEY (related_match_id)
        REFERENCES mgw_matches (match_id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
