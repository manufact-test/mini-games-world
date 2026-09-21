<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260921_0053_create_tournament_hall_bracket';
    }

    public function description(): string
    {
        return 'Add MVP-21.4 participant Tournament Hall presence and immutable start bracket.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN bracket_effective_at_utc TEXT NULL');
            $database->execute('ALTER TABLE mgw_tournaments ADD COLUMN bracket_generated_at_utc TEXT NULL');
            $database->execute("ALTER TABLE mgw_tournaments ADD COLUMN bracket_version TEXT NULL");

            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_hall_entries (
    tournament_id TEXT NOT NULL,
    registration_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    entered_at_utc TEXT NOT NULL,
    last_presence_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    UNIQUE (registration_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (registration_id) REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_hall_presence ON mgw_tournament_hall_entries (tournament_id, last_presence_at_utc)');

            $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_bracket_seeds (
    tournament_id TEXT NOT NULL,
    seed_no INTEGER NOT NULL,
    pair_no INTEGER NOT NULL,
    registration_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    present_at_start INTEGER NOT NULL DEFAULT 0,
    technical_loss_at_start INTEGER NOT NULL DEFAULT 0,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, seed_no),
    UNIQUE (tournament_id, mgw_id),
    UNIQUE (registration_id),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (registration_id) REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
            $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_bracket_pair ON mgw_tournament_bracket_seeds (tournament_id, pair_no, seed_no)');
            return;
        }

        $database->execute(<<<'SQL'
ALTER TABLE mgw_tournaments
    ADD COLUMN bracket_effective_at_utc DATETIME(6) NULL AFTER scheduled_at_utc,
    ADD COLUMN bracket_generated_at_utc DATETIME(6) NULL AFTER bracket_effective_at_utc,
    ADD COLUMN bracket_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bracket_generated_at_utc
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_hall_entries (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entered_at_utc DATETIME(6) NOT NULL,
    last_presence_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    UNIQUE KEY uq_mgw_tournament_hall_registration (registration_id),
    INDEX idx_mgw_tournament_hall_presence (tournament_id, last_presence_at_utc),
    CONSTRAINT fk_mgw_tournament_hall_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_hall_registration FOREIGN KEY (registration_id)
        REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_hall_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_bracket_seeds (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    seed_no SMALLINT UNSIGNED NOT NULL,
    pair_no SMALLINT UNSIGNED NOT NULL,
    registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    present_at_start TINYINT(1) NOT NULL DEFAULT 0,
    technical_loss_at_start TINYINT(1) NOT NULL DEFAULT 0,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, seed_no),
    UNIQUE KEY uq_mgw_tournament_bracket_user (tournament_id, mgw_id),
    UNIQUE KEY uq_mgw_tournament_bracket_registration (registration_id),
    INDEX idx_mgw_tournament_bracket_pair (tournament_id, pair_no, seed_no),
    CONSTRAINT fk_mgw_tournament_bracket_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_bracket_registration FOREIGN KEY (registration_id)
        REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_bracket_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_mgw_tournament_bracket_presence CHECK (present_at_start IN (0,1)),
    CONSTRAINT chk_mgw_tournament_bracket_technical CHECK (technical_loss_at_start IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
};
