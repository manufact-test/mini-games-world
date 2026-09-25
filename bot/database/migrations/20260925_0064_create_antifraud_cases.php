<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260925_0064_create_antifraud_cases';
    }

    public function description(): string
    {
        return 'Create MVP-22.4 anti-fraud review cases, detected signals and audit trail.';
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
CREATE TABLE IF NOT EXISTS mgw_antifraud_cases (
    case_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    match_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    priority_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    summary VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    owner_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    created_by_admin_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    reviewed_at_utc DATETIME(6) NULL,
    closed_at_utc DATETIME(6) NULL,
    UNIQUE KEY uq_mgw_antifraud_case_match (match_id),
    INDEX idx_mgw_antifraud_cases_status (status_code, updated_at_utc),
    INDEX idx_mgw_antifraud_cases_owner (owner_ref, status_code, updated_at_utc),
    CONSTRAINT fk_mgw_antifraud_case_match FOREIGN KEY (match_id)
        REFERENCES mgw_matches (match_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_antifraud_case_signals (
    case_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signal_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    severity_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    details_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    detected_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (case_id, signal_code),
    INDEX idx_mgw_antifraud_signal_severity (severity_code, detected_at_utc),
    CONSTRAINT fk_mgw_antifraud_signal_case FOREIGN KEY (case_id)
        REFERENCES mgw_antifraud_cases (case_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_antifraud_case_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    case_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    previous_value VARCHAR(64) COLLATE utf8mb4_bin NULL,
    next_value VARCHAR(64) COLLATE utf8mb4_bin NULL,
    note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_antifraud_events_case (case_id, event_id),
    CONSTRAINT fk_mgw_antifraud_event_case FOREIGN KEY (case_id)
        REFERENCES mgw_antifraud_cases (case_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_antifraud_cases (
    case_id TEXT NOT NULL PRIMARY KEY,
    match_id TEXT NOT NULL,
    status_code TEXT NOT NULL,
    priority_code TEXT NOT NULL,
    decision_code TEXT NOT NULL,
    summary TEXT NOT NULL,
    owner_ref TEXT NULL,
    created_by_admin_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    reviewed_at_utc TEXT NULL,
    closed_at_utc TEXT NULL,
    UNIQUE (match_id),
    FOREIGN KEY (match_id) REFERENCES mgw_matches (match_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_antifraud_cases_status ON mgw_antifraud_cases (status_code, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_antifraud_cases_owner ON mgw_antifraud_cases (owner_ref, status_code, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_antifraud_case_signals (
    case_id TEXT NOT NULL,
    signal_code TEXT NOT NULL,
    severity_code TEXT NOT NULL,
    label TEXT NOT NULL,
    details_json TEXT NOT NULL,
    detected_at_utc TEXT NOT NULL,
    PRIMARY KEY (case_id, signal_code),
    FOREIGN KEY (case_id) REFERENCES mgw_antifraud_cases (case_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_antifraud_signal_severity ON mgw_antifraud_case_signals (severity_code, detected_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_antifraud_case_events (
    event_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    previous_value TEXT NULL,
    next_value TEXT NULL,
    note TEXT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (case_id) REFERENCES mgw_antifraud_cases (case_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_antifraud_events_case ON mgw_antifraud_case_events (case_id, event_id)');
    }
};
