<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0046_create_seasonal_awards';
    }

    public function description(): string
    {
        return 'Create MVP-20.5 auditable seasonal badge and temporary top-3 frame awards.';
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
CREATE TABLE IF NOT EXISTS mgw_season_award_runs (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    standings_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    reconciled_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (season_id, game_type),
    INDEX idx_mgw_season_award_runs_target (target_season_id, updated_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_awards (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rank_position SMALLINT UNSIGNED NOT NULL,
    badge_tier VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    frame_place TINYINT UNSIGNED NULL,
    frame_target_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    frame_valid_from_at_utc DATETIME(6) NULL,
    frame_valid_until_at_utc DATETIME(6) NULL,
    award_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    granted_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (season_id, game_type, mgw_id),
    INDEX idx_mgw_season_awards_user (mgw_id, award_state, season_id),
    INDEX idx_mgw_season_awards_rank (season_id, game_type, award_state, rank_position),
    INDEX idx_mgw_season_awards_frame (mgw_id, award_state, frame_valid_from_at_utc, frame_valid_until_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_award_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_season_award_audit_scope (season_id, game_type, revision, audit_id),
    INDEX idx_mgw_season_award_audit_user (mgw_id, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_award_runs (
    season_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    target_season_id TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    standings_fingerprint TEXT NOT NULL,
    last_reason TEXT NOT NULL,
    last_actor_ref TEXT NOT NULL,
    reconciled_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (season_id, game_type)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_award_runs_target ON mgw_season_award_runs (target_season_id, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_awards (
    season_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    rank_position INTEGER NOT NULL,
    badge_tier TEXT NOT NULL,
    frame_place INTEGER NULL,
    frame_target_season_id TEXT NULL,
    frame_valid_from_at_utc TEXT NULL,
    frame_valid_until_at_utc TEXT NULL,
    award_state TEXT NOT NULL,
    revision INTEGER NOT NULL,
    granted_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (season_id, game_type, mgw_id)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_awards_user ON mgw_season_awards (mgw_id, award_state, season_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_awards_rank ON mgw_season_awards (season_id, game_type, award_state, rank_position)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_awards_frame ON mgw_season_awards (mgw_id, award_state, frame_valid_from_at_utc, frame_valid_until_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_award_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    revision INTEGER NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_award_audit_scope ON mgw_season_award_audit (season_id, game_type, revision, audit_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_award_audit_user ON mgw_season_award_audit (mgw_id, created_at_utc)');
    }
};
