<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260922_0058_add_tournament_technical_outcomes';
    }

    public function description(): string
    {
        return 'Add MVP-21.7 nullable bracket slots and durable tournament technical-outcome audit.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->rebuildSqliteRoundMatches($database);
            $this->createSqliteAudit($database);
            return;
        }

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournament_round_matches
    MODIFY player_a_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    MODIFY player_b_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_technical_outcomes (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    round_no SMALLINT UNSIGNED NOT NULL,
    pair_no SMALLINT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL,
    game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    outcome_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    player_a_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    player_b_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    winner_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    loser_mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json JSON NULL,
    occurred_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_tournament_technical_round (tournament_id, round_no, pair_no, attempt_no),
    INDEX idx_mgw_tournament_technical_code (tournament_id, outcome_code),
    CONSTRAINT fk_mgw_tournament_technical_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_technical_player_a FOREIGN KEY (player_a_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_technical_player_b FOREIGN KEY (player_b_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_technical_winner FOREIGN KEY (winner_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_technical_loser FOREIGN KEY (loser_mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function rebuildSqliteRoundMatches(DatabaseConnectionInterface $database): void
    {
        $database->execute('PRAGMA foreign_keys = OFF');
        try {
            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_round_matches_mvp217 (
    tournament_id TEXT NOT NULL,
    round_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    player_a_mgw_id TEXT NULL,
    player_b_mgw_id TEXT NULL,
    readiness_opened_at_utc TEXT NOT NULL,
    readiness_deadline_at_utc TEXT NOT NULL,
    player_a_ready_at_utc TEXT NULL,
    player_b_ready_at_utc TEXT NULL,
    launch_state TEXT NOT NULL,
    game_id TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    attempt_no INTEGER NOT NULL DEFAULT 1,
    wait_kind TEXT NOT NULL DEFAULT 'initial_ready',
    match_kind TEXT NOT NULL DEFAULT 'elimination',
    winner_mgw_id TEXT NULL,
    loser_mgw_id TEXT NULL,
    result_reason TEXT NULL,
    completed_at_utc TEXT NULL,
    PRIMARY KEY (tournament_id, round_no, pair_no),
    UNIQUE (game_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_a_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_b_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute(<<<'SQL'
INSERT INTO mgw_tournament_round_matches_mvp217 (
    tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
    readiness_opened_at_utc,readiness_deadline_at_utc,
    player_a_ready_at_utc,player_b_ready_at_utc,
    launch_state,game_id,created_at_utc,updated_at_utc,
    attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
)
SELECT
    tournament_id,round_no,pair_no,player_a_mgw_id,player_b_mgw_id,
    readiness_opened_at_utc,readiness_deadline_at_utc,
    player_a_ready_at_utc,player_b_ready_at_utc,
    launch_state,game_id,created_at_utc,updated_at_utc,
    attempt_no,wait_kind,match_kind,winner_mgw_id,loser_mgw_id,result_reason,completed_at_utc
FROM mgw_tournament_round_matches
SQL);
            $database->execute('DROP TABLE mgw_tournament_round_matches');
            $database->execute('ALTER TABLE mgw_tournament_round_matches_mvp217 RENAME TO mgw_tournament_round_matches');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_round_match_player_a ON mgw_tournament_round_matches (tournament_id, player_a_mgw_id)');
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_round_match_player_b ON mgw_tournament_round_matches (tournament_id, player_b_mgw_id)');
        } finally {
            $database->execute('PRAGMA foreign_keys = ON');
        }
    }

    private function createSqliteAudit(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_technical_outcomes (
    event_key TEXT NOT NULL PRIMARY KEY,
    tournament_id TEXT NOT NULL,
    round_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    attempt_no INTEGER NOT NULL,
    game_id TEXT NULL,
    outcome_code TEXT NOT NULL,
    player_a_mgw_id TEXT NULL,
    player_b_mgw_id TEXT NULL,
    winner_mgw_id TEXT NULL,
    loser_mgw_id TEXT NULL,
    metadata_json TEXT NULL,
    occurred_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_a_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (player_b_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (winner_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (loser_mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_technical_round ON mgw_tournament_technical_outcomes (tournament_id, round_no, pair_no, attempt_no)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_technical_code ON mgw_tournament_technical_outcomes (tournament_id, outcome_code)');
    }
};
