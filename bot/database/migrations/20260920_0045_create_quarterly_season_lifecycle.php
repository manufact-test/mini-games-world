<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0045_create_quarterly_season_lifecycle';
    }

    public function description(): string
    {
        return 'Create MVP-20.4 quarterly season lifecycle, readiness, reminders and idempotent boundary operations.';
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
CREATE TABLE IF NOT EXISTS mgw_rating_seasons (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    calendar_year SMALLINT UNSIGNED NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    timezone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    calendar_start_at_utc DATETIME(6) NOT NULL,
    calendar_end_at_utc DATETIME(6) NOT NULL,
    official_start_at_utc DATETIME(6) NOT NULL,
    season_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    finalization_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    standings_frozen_at_utc DATETIME(6) NULL,
    finalization_started_at_utc DATETIME(6) NULL,
    finalized_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_rating_seasons_calendar (calendar_year, quarter),
    INDEX idx_mgw_rating_seasons_state_end (season_state, calendar_end_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_reward_packages (
    target_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    package_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seasonal_awards_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    top3_frames_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    yearly_medal_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    localization_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_validation_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ready_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_season_reward_packages_state (package_state, updated_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_preparation_reminders (
    ending_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkpoint_days SMALLINT UNSIGNED NOT NULL,
    due_at_utc DATETIME(6) NOT NULL,
    reminder_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    became_due_at_utc DATETIME(6) NULL,
    resolved_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (ending_season_id, checkpoint_days),
    INDEX idx_mgw_season_preparation_reminders_due (reminder_state, due_at_utc),
    INDEX idx_mgw_season_preparation_reminders_target (target_season_id, reminder_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_boundary_operations (
    ending_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    target_season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    boundary_at_utc DATETIME(6) NOT NULL,
    operation_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    block_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    started_at_utc DATETIME(6) NOT NULL,
    completed_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_season_boundary_operations_state (operation_state, boundary_at_utc),
    INDEX idx_mgw_season_boundary_operations_target (target_season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_seasons (
    season_id TEXT NOT NULL PRIMARY KEY,
    calendar_year INTEGER NOT NULL,
    quarter INTEGER NOT NULL,
    timezone TEXT NOT NULL,
    calendar_start_at_utc TEXT NOT NULL,
    calendar_end_at_utc TEXT NOT NULL,
    official_start_at_utc TEXT NOT NULL,
    season_state TEXT NOT NULL,
    finalization_reason TEXT NULL,
    standings_frozen_at_utc TEXT NULL,
    finalization_started_at_utc TEXT NULL,
    finalized_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_mgw_rating_seasons_calendar ON mgw_rating_seasons (calendar_year, quarter)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_seasons_state_end ON mgw_rating_seasons (season_state, calendar_end_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_reward_packages (
    target_season_id TEXT NOT NULL PRIMARY KEY,
    package_state TEXT NOT NULL,
    seasonal_awards_state TEXT NOT NULL,
    top3_frames_state TEXT NOT NULL,
    yearly_medal_state TEXT NOT NULL,
    localization_state TEXT NOT NULL,
    preview_validation_state TEXT NOT NULL,
    ready_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_reward_packages_state ON mgw_season_reward_packages (package_state, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_preparation_reminders (
    ending_season_id TEXT NOT NULL,
    target_season_id TEXT NOT NULL,
    checkpoint_days INTEGER NOT NULL,
    due_at_utc TEXT NOT NULL,
    reminder_state TEXT NOT NULL,
    became_due_at_utc TEXT NULL,
    resolved_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (ending_season_id, checkpoint_days)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_preparation_reminders_due ON mgw_season_preparation_reminders (reminder_state, due_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_preparation_reminders_target ON mgw_season_preparation_reminders (target_season_id, reminder_state)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_season_boundary_operations (
    ending_season_id TEXT NOT NULL PRIMARY KEY,
    target_season_id TEXT NOT NULL,
    boundary_at_utc TEXT NOT NULL,
    operation_state TEXT NOT NULL,
    block_reason TEXT NULL,
    started_at_utc TEXT NOT NULL,
    completed_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_boundary_operations_state ON mgw_season_boundary_operations (operation_state, boundary_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_season_boundary_operations_target ON mgw_season_boundary_operations (target_season_id)');
    }
};
