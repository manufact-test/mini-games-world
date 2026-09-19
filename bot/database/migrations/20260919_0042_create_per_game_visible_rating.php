<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260919_0042_create_per_game_visible_rating';
    }

    public function description(): string
    {
        return 'Create MVP-20.1 per-game visible rating control, scores and idempotent match outcomes.';
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
CREATE TABLE IF NOT EXISTS mgw_rating_control (
    control_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    competition_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracking_started_at_utc DATETIME(6) NOT NULL,
    activated_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_scores (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rated_wins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (season_id, mgw_id, game_type),
    INDEX idx_mgw_game_rating_scores_game (season_id, game_type, points, rated_wins),
    INDEX idx_mgw_game_rating_scores_user (mgw_id, season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_outcomes (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    competition_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    winner_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    points_delta SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    outcome_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    match_source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    finish_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    finished_at_utc DATETIME(6) NULL,
    processed_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_game_rating_outcomes_winner (winner_mgw_id, season_id, game_type),
    INDEX idx_mgw_game_rating_outcomes_processed (processed_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
INSERT IGNORE INTO mgw_rating_control (
    control_key, competition_state, current_season_id,
    tracking_started_at_utc, activated_at_utc, updated_at_utc
) VALUES (
    'global', 'preseason', 'preseason',
    UTC_TIMESTAMP(6), NULL, UTC_TIMESTAMP(6)
)
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_control (
    control_key TEXT NOT NULL PRIMARY KEY,
    competition_state TEXT NOT NULL,
    current_season_id TEXT NOT NULL,
    tracking_started_at_utc TEXT NOT NULL,
    activated_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_scores (
    season_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    rated_wins INTEGER NOT NULL DEFAULT 0,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (season_id, mgw_id, game_type)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_game_rating_scores_game ON mgw_game_rating_scores (season_id, game_type, points, rated_wins)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_game_rating_scores_user ON mgw_game_rating_scores (mgw_id, season_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_game_rating_outcomes (
    match_id TEXT NOT NULL PRIMARY KEY,
    season_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    competition_state TEXT NOT NULL,
    winner_mgw_id TEXT NULL,
    points_delta INTEGER NOT NULL DEFAULT 0,
    outcome_code TEXT NOT NULL,
    match_source TEXT NULL,
    finish_reason TEXT NULL,
    finished_at_utc TEXT NULL,
    processed_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_game_rating_outcomes_winner ON mgw_game_rating_outcomes (winner_mgw_id, season_id, game_type)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_game_rating_outcomes_processed ON mgw_game_rating_outcomes (processed_at_utc)');

        $database->execute(<<<'SQL'
INSERT OR IGNORE INTO mgw_rating_control (
    control_key, competition_state, current_season_id,
    tracking_started_at_utc, activated_at_utc, updated_at_utc
) VALUES (
    'global', 'preseason', 'preseason',
    strftime('%Y-%m-%d %H:%M:%f', 'now'), NULL,
    strftime('%Y-%m-%d %H:%M:%f', 'now')
)
SQL);
    }
};
