<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0049_create_official_tournaments';
    }

    public function description(): string
    {
        return 'Create MVP-21.1 official tournament drafts and ledger-backed registrations.';
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
CREATE TABLE IF NOT EXISTS mgw_tournaments (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    active_slot VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    title VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    game_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL,
    entry_fee_amount BIGINT UNSIGNED NOT NULL,
    entry_asset_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_snapshot_json JSON NOT NULL,
    tournament_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    opened_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    registration_opened_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_tournaments_active_slot (active_slot),
    INDEX idx_mgw_tournaments_state_created (tournament_state, created_at_utc),
    INDEX idx_mgw_tournaments_game_state (game_type, tournament_state),
    CONSTRAINT chk_mgw_tournaments_capacity CHECK (capacity IN (8,16,32,64,128)),
    CONSTRAINT chk_mgw_tournaments_entry_positive CHECK (entry_fee_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_registrations (
    registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    attempt_no INT UNSIGNED NOT NULL,
    registration_state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reservation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registered_at_utc DATETIME(6) NOT NULL,
    withdrawn_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_tournament_registration_attempt (tournament_id, mgw_id, attempt_no),
    UNIQUE KEY uq_mgw_tournament_registration_reservation (reservation_id),
    INDEX idx_mgw_tournament_registration_state (tournament_id, registration_state, registered_at_utc),
    INDEX idx_mgw_tournament_registration_user_state (mgw_id, registration_state, updated_at_utc),
    CONSTRAINT fk_mgw_tournament_registration_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_registration_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_registration_reservation FOREIGN KEY (reservation_id)
        REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournaments (
    tournament_id TEXT NOT NULL PRIMARY KEY,
    active_slot TEXT NULL UNIQUE,
    title TEXT NOT NULL,
    game_type TEXT NOT NULL,
    capacity INTEGER NOT NULL CHECK (capacity IN (8,16,32,64,128)),
    entry_fee_amount INTEGER NOT NULL CHECK (entry_fee_amount > 0),
    entry_asset_code TEXT NOT NULL,
    reward_snapshot_json TEXT NOT NULL,
    tournament_state TEXT NOT NULL,
    created_by_ref TEXT NOT NULL,
    opened_by_ref TEXT NULL,
    created_at_utc TEXT NOT NULL,
    registration_opened_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournaments_state_created ON mgw_tournaments (tournament_state, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournaments_game_state ON mgw_tournaments (game_type, tournament_state)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_registrations (
    registration_id TEXT NOT NULL PRIMARY KEY,
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    account_ref TEXT NOT NULL,
    attempt_no INTEGER NOT NULL,
    registration_state TEXT NOT NULL,
    reservation_id TEXT NOT NULL UNIQUE,
    registered_at_utc TEXT NOT NULL,
    withdrawn_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    UNIQUE (tournament_id, mgw_id, attempt_no),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (reservation_id) REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_registration_state ON mgw_tournament_registrations (tournament_id, registration_state, registered_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_registration_user_state ON mgw_tournament_registrations (mgw_id, registration_state, updated_at_utc)');
    }
};
