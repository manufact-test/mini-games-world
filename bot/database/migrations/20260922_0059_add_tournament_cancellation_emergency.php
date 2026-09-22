<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260922_0059_add_tournament_cancellation_emergency';
    }

    public function description(): string
    {
        return 'Add MVP-21.8 tournament cancellation, emergency stop, refund audit and result annulment.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN cancellation_kind TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN cancellation_reason TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN cancelled_by_ref TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN cancelled_at_utc TEXT NULL');

            $database->execute('ALTER TABLE mgw_tournament_round_matches ADD COLUMN annulled_at_utc TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournament_round_matches ADD COLUMN annulment_reason TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournament_match_attempts ADD COLUMN annulled_at_utc TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournament_match_attempts ADD COLUMN annulment_reason TEXT NULL');

            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_cancellation_events (
    event_key TEXT NOT NULL PRIMARY KEY,
    tournament_id TEXT NOT NULL UNIQUE,
    cancellation_kind TEXT NOT NULL,
    pre_cancel_state TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    reason TEXT NOT NULL,
    confirmation_mode TEXT NOT NULL,
    participant_count INTEGER NOT NULL,
    refunded_count INTEGER NOT NULL,
    refund_amount INTEGER NOT NULL,
    released_reservation_count INTEGER NOT NULL,
    consumed_refund_count INTEGER NOT NULL,
    annulled_match_count INTEGER NOT NULL,
    annulled_attempt_count INTEGER NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_tournament_cancel_created
                 ON mgw_tournament_cancellation_events (created_at_utc)'
            );
            return;
        }

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournaments
    ADD COLUMN cancellation_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN cancellation_reason TEXT NULL,
    ADD COLUMN cancelled_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    ADD COLUMN cancelled_at_utc DATETIME(6) NULL
SQL);

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournament_round_matches
    ADD COLUMN annulled_at_utc DATETIME(6) NULL,
    ADD COLUMN annulment_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
SQL);

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournament_match_attempts
    ADD COLUMN annulled_at_utc DATETIME(6) NULL,
    ADD COLUMN annulment_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_cancellation_events (
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cancellation_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pre_cancel_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    reason TEXT NOT NULL,
    confirmation_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    participant_count INT UNSIGNED NOT NULL,
    refunded_count INT UNSIGNED NOT NULL,
    refund_amount BIGINT UNSIGNED NOT NULL,
    released_reservation_count INT UNSIGNED NOT NULL,
    consumed_refund_count INT UNSIGNED NOT NULL,
    annulled_match_count INT UNSIGNED NOT NULL,
    annulled_attempt_count INT UNSIGNED NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_tournament_cancel_tournament (tournament_id),
    INDEX idx_mgw_tournament_cancel_created (created_at_utc),
    CONSTRAINT fk_mgw_tournament_cancel_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
