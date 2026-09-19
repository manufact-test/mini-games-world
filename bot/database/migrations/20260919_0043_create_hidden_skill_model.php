<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260919_0043_create_hidden_skill_model';
    }

    public function description(): string
    {
        return 'Create MVP-20.2 per-game hidden skill scores and idempotent human-match outcomes.';
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
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_control (
    control_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    model_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracking_started_at_utc DATETIME(6) NOT NULL,
    soft_carry_basis_points SMALLINT UNSIGNED NOT NULL DEFAULT 7500,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $database->execute(<<<'SQL'
INSERT IGNORE INTO mgw_hidden_skill_control (
    control_key, model_version, tracking_started_at_utc,
    soft_carry_basis_points, updated_at_utc
) VALUES (
    'global', 'elo-v1', UTC_TIMESTAMP(6), 7500, UTC_TIMESTAMP(6)
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_scores (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    skill_score INT UNSIGNED NOT NULL DEFAULT 1500,
    current_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    matches_played BIGINT UNSIGNED NOT NULL DEFAULT 0,
    wins BIGINT UNSIGNED NOT NULL DEFAULT 0,
    losses BIGINT UNSIGNED NOT NULL DEFAULT 0,
    draws BIGINT UNSIGNED NOT NULL DEFAULT 0,
    season_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (mgw_id, game_type),
    INDEX idx_mgw_hidden_skill_game_score (game_type, skill_score),
    INDEX idx_mgw_hidden_skill_season (current_season_id, game_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_outcomes (
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_a_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    player_b_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    player_a_before INT UNSIGNED NULL,
    player_b_before INT UNSIGNED NULL,
    player_a_after INT UNSIGNED NULL,
    player_b_after INT UNSIGNED NULL,
    player_a_delta SMALLINT NULL,
    player_b_delta SMALLINT NULL,
    skill_gap_before INT UNSIGNED NULL,
    outcome_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    finish_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    processed_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_hidden_skill_outcomes_players (player_a_mgw_id, player_b_mgw_id, processed_at_utc),
    INDEX idx_mgw_hidden_skill_outcomes_game (game_type, processed_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_control (
    control_key TEXT NOT NULL PRIMARY KEY,
    model_version TEXT NOT NULL,
    tracking_started_at_utc TEXT NOT NULL,
    soft_carry_basis_points INTEGER NOT NULL DEFAULT 7500,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute(<<<'SQL'
INSERT OR IGNORE INTO mgw_hidden_skill_control (
    control_key, model_version, tracking_started_at_utc,
    soft_carry_basis_points, updated_at_utc
) VALUES (
    'global', 'elo-v1', strftime('%Y-%m-%d %H:%M:%f', 'now'),
    7500, strftime('%Y-%m-%d %H:%M:%f', 'now')
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_scores (
    mgw_id TEXT NOT NULL,
    game_type TEXT NOT NULL,
    skill_score INTEGER NOT NULL DEFAULT 1500,
    current_season_id TEXT NOT NULL,
    matches_played INTEGER NOT NULL DEFAULT 0,
    wins INTEGER NOT NULL DEFAULT 0,
    losses INTEGER NOT NULL DEFAULT 0,
    draws INTEGER NOT NULL DEFAULT 0,
    season_matches INTEGER NOT NULL DEFAULT 0,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (mgw_id, game_type)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_hidden_skill_game_score ON mgw_hidden_skill_scores (game_type, skill_score)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_hidden_skill_season ON mgw_hidden_skill_scores (current_season_id, game_type)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_hidden_skill_outcomes (
    match_id TEXT NOT NULL PRIMARY KEY,
    game_type TEXT NOT NULL,
    season_id TEXT NOT NULL,
    player_a_mgw_id TEXT NULL,
    player_b_mgw_id TEXT NULL,
    player_a_before INTEGER NULL,
    player_b_before INTEGER NULL,
    player_a_after INTEGER NULL,
    player_b_after INTEGER NULL,
    player_a_delta INTEGER NULL,
    player_b_delta INTEGER NULL,
    skill_gap_before INTEGER NULL,
    outcome_code TEXT NOT NULL,
    finish_reason TEXT NULL,
    processed_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_hidden_skill_outcomes_players ON mgw_hidden_skill_outcomes (player_a_mgw_id, player_b_mgw_id, processed_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_hidden_skill_outcomes_game ON mgw_hidden_skill_outcomes (game_type, processed_at_utc)');
    }
};
