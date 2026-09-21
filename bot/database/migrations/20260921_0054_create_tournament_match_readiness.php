<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260921_0054_create_tournament_match_readiness';
    }

    public function description(): string
    {
        return 'Add MVP-21.5 durable first-round pair readiness and tournament game attachment.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_round_matches (
    tournament_id TEXT NOT NULL,
    round_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    player_a_mgw_id TEXT NOT NULL,
    player_b_mgw_id TEXT NOT NULL,
    readiness_opened_at_utc TEXT NOT NULL,
    readiness_deadline_at_utc TEXT NOT NULL,
    player_a_ready_at_utc TEXT NULL,
    player_b_ready_at_utc TEXT NULL,
    launch_state TEXT NOT NULL,
    game_id TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, round_no, pair_no),
    UNIQUE (game_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_a_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_b_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_round_match_player_a ON mgw_tournament_round_matches (tournament_id, player_a_mgw_id)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_round_match_player_b ON mgw_tournament_round_matches (tournament_id, player_b_mgw_id)');
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_round_matches (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    round_no SMALLINT UNSIGNED NOT NULL,
    pair_no SMALLINT UNSIGNED NOT NULL,
    player_a_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_b_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    readiness_opened_at_utc DATETIME(6) NOT NULL,
    readiness_deadline_at_utc DATETIME(6) NOT NULL,
    player_a_ready_at_utc DATETIME(6) NULL,
    player_b_ready_at_utc DATETIME(6) NULL,
    launch_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, round_no, pair_no),
    UNIQUE KEY uq_mgw_tournament_round_match_game (game_id),
    INDEX idx_mgw_tournament_round_match_player_a (tournament_id, player_a_mgw_id),
    INDEX idx_mgw_tournament_round_match_player_b (tournament_id, player_b_mgw_id),
    CONSTRAINT fk_mgw_tournament_round_match_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_round_match_player_a FOREIGN KEY (player_a_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_round_match_player_b FOREIGN KEY (player_b_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_mgw_tournament_round_match_players CHECK (player_a_mgw_id <> player_b_mgw_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
