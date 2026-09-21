<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260921_0055_add_tournament_round_progression';
    }

    public function description(): string
    {
        return 'Add MVP-21.6 round progression, replay attempts and final/third-place metadata.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $text = $database->driver() === 'sqlite' ? 'TEXT' : 'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin';
        $date = $database->driver() === 'sqlite' ? 'TEXT' : 'DATETIME(6)';
        $small = $database->driver() === 'sqlite' ? 'INTEGER' : 'SMALLINT UNSIGNED';

        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN attempt_no {$small} NOT NULL DEFAULT 1");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN wait_kind {$text} NOT NULL DEFAULT 'initial_ready'");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN match_kind {$text} NOT NULL DEFAULT 'elimination'");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN winner_mgw_id VARCHAR(24) NULL");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN loser_mgw_id VARCHAR(24) NULL");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN result_reason {$text} NULL");
        $database->execute("ALTER TABLE mgw_tournament_round_matches ADD COLUMN completed_at_utc {$date} NULL");

        if ($database->driver() === 'sqlite') {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_match_attempts (
    tournament_id TEXT NOT NULL,
    round_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    attempt_no INTEGER NOT NULL,
    game_id TEXT NOT NULL,
    player_a_mgw_id TEXT NOT NULL,
    player_b_mgw_id TEXT NOT NULL,
    result_type TEXT NOT NULL,
    winner_mgw_id TEXT NULL,
    loser_mgw_id TEXT NULL,
    finish_reason TEXT NULL,
    finished_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, round_no, pair_no, attempt_no),
    UNIQUE (game_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_match_attempts_round ON mgw_tournament_match_attempts (tournament_id, round_no, pair_no)');
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_match_attempts (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    round_no SMALLINT UNSIGNED NOT NULL,
    pair_no SMALLINT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL,
    game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_a_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_b_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    winner_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    loser_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    finish_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    finished_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, round_no, pair_no, attempt_no),
    UNIQUE KEY uq_mgw_tournament_match_attempt_game (game_id),
    INDEX idx_mgw_tournament_match_attempts_round (tournament_id, round_no, pair_no),
    CONSTRAINT fk_mgw_tournament_match_attempt_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
