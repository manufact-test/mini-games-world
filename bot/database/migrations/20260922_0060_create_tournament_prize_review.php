<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260922_0060_create_tournament_prize_review';
    }

    public function description(): string
    {
        return 'Create MVP-21.10 serious-signal prize review state and durable audit.';
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
CREATE TABLE IF NOT EXISTS mgw_tournament_prize_reviews (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signal_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    related_game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    signal_note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    signal_actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    signaled_at_utc DATETIME(6) NOT NULL,
    resolution_note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    resolved_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    resolved_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    INDEX idx_mgw_tournament_prize_review_state (review_state, updated_at_utc),
    INDEX idx_mgw_tournament_prize_review_game (tournament_id, related_game_id),
    CONSTRAINT fk_mgw_tournament_prize_review_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_prize_review_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_prize_review_audit (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    next_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signal_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    related_game_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_tournament_prize_review_audit_tournament (tournament_id, created_at_utc),
    INDEX idx_mgw_tournament_prize_review_audit_user (mgw_id, created_at_utc),
    CONSTRAINT fk_mgw_tournament_prize_review_audit_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_prize_review_audit_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_prize_reviews (
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    review_state TEXT NOT NULL,
    signal_code TEXT NOT NULL,
    related_game_id TEXT NULL,
    signal_note TEXT NOT NULL,
    signal_actor_ref TEXT NOT NULL,
    signaled_at_utc TEXT NOT NULL,
    resolution_note TEXT NULL,
    resolved_by_ref TEXT NULL,
    resolved_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_prize_review_state ON mgw_tournament_prize_reviews (review_state, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_prize_review_game ON mgw_tournament_prize_reviews (tournament_id, related_game_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_prize_review_audit (
    event_key TEXT NOT NULL PRIMARY KEY,
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    previous_state TEXT NULL,
    next_state TEXT NOT NULL,
    signal_code TEXT NOT NULL,
    related_game_id TEXT NULL,
    note TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_prize_review_audit_tournament ON mgw_tournament_prize_review_audit (tournament_id, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_prize_review_audit_user ON mgw_tournament_prize_review_audit (mgw_id, created_at_utc)');
    }
};
