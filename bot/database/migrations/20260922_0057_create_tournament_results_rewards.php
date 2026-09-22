<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260922_0057_create_tournament_results_rewards';
    }

    public function description(): string
    {
        return 'Create MVP-21.9 durable tournament results, reward entitlements and Golden Ticket state.';
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
CREATE TABLE IF NOT EXISTS mgw_tournament_results (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    registration_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_ref VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    placement SMALLINT UNSIGNED NULL,
    result_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_snapshot_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_snapshot_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entry_amount BIGINT UNSIGNED NOT NULL,
    entry_return_amount BIGINT UNSIGNED NOT NULL,
    prize_amount BIGINT UNSIGNED NOT NULL,
    payout_amount BIGINT UNSIGNED NOT NULL,
    reservation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    settled_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    UNIQUE KEY uq_mgw_tournament_results_registration (registration_id),
    UNIQUE KEY uq_mgw_tournament_results_reservation (reservation_id),
    INDEX idx_mgw_tournament_results_user (mgw_id, settled_at_utc),
    INDEX idx_mgw_tournament_results_place (tournament_id, placement),
    CONSTRAINT fk_mgw_tournament_results_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_results_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_results_registration FOREIGN KEY (registration_id)
        REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_results_reservation FOREIGN KEY (reservation_id)
        REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_mgw_tournament_results_payout CHECK (payout_amount = entry_return_amount + prize_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_reward_entitlements (
    tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reward_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valid_from_at_utc DATETIME(6) NULL,
    valid_until_at_utc DATETIME(6) NULL,
    metadata_json JSON NULL,
    granted_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id, reward_code),
    INDEX idx_mgw_tournament_reward_user (mgw_id, reward_kind, valid_until_at_utc),
    CONSTRAINT fk_mgw_tournament_reward_tournament FOREIGN KEY (tournament_id)
        REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mgw_tournament_reward_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_golden_tickets (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    ticket_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    championship_count INT UNSIGNED NOT NULL,
    first_tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_tournament_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_awarded_at_utc DATETIME(6) NOT NULL,
    last_awarded_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_tournament_tickets_state (ticket_state, championship_count),
    CONSTRAINT fk_mgw_tournament_ticket_user FOREIGN KEY (mgw_id)
        REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_results (
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    registration_id TEXT NOT NULL UNIQUE,
    account_ref TEXT NOT NULL,
    placement INTEGER NULL,
    result_code TEXT NOT NULL,
    reward_snapshot_version TEXT NOT NULL,
    reward_snapshot_sha256 TEXT NOT NULL,
    entry_amount INTEGER NOT NULL,
    entry_return_amount INTEGER NOT NULL,
    prize_amount INTEGER NOT NULL,
    payout_amount INTEGER NOT NULL,
    reservation_id TEXT NOT NULL UNIQUE,
    settled_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id),
    CHECK (payout_amount = entry_return_amount + prize_amount),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (registration_id) REFERENCES mgw_tournament_registrations (registration_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (reservation_id) REFERENCES mgw_reservations (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_results_user ON mgw_tournament_results (mgw_id, settled_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_results_place ON mgw_tournament_results (tournament_id, placement)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_reward_entitlements (
    tournament_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    reward_code TEXT NOT NULL,
    reward_kind TEXT NOT NULL,
    valid_from_at_utc TEXT NULL,
    valid_until_at_utc TEXT NULL,
    metadata_json TEXT NULL,
    granted_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    PRIMARY KEY (tournament_id, mgw_id, reward_code),
    FOREIGN KEY (tournament_id) REFERENCES mgw_tournaments (tournament_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_reward_user ON mgw_tournament_reward_entitlements (mgw_id, reward_kind, valid_until_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_tournament_golden_tickets (
    mgw_id TEXT NOT NULL PRIMARY KEY,
    ticket_state TEXT NOT NULL,
    championship_count INTEGER NOT NULL,
    first_tournament_id TEXT NOT NULL,
    last_tournament_id TEXT NOT NULL,
    first_awarded_at_utc TEXT NOT NULL,
    last_awarded_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (mgw_id) REFERENCES mgw_users (mgw_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_tournament_tickets_state ON mgw_tournament_golden_tickets (ticket_state, championship_count)');
    }
};
