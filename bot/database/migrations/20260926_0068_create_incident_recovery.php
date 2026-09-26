<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260926_0068_create_incident_recovery';
    }

    public function description(): string
    {
        return 'Create MVP-22.9 incident response, evidence, recovery status and two-admin action state without replacing existing runtime/session owners.';
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
CREATE TABLE IF NOT EXISTS mgw_incidents (
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    title VARCHAR(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    incident_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    summary_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    opened_by_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    opened_at_utc DATETIME(6) NOT NULL,
    resolved_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    resolved_at_utc DATETIME(6) NULL,
    security_mode_before_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    security_mode_enabled_at_utc DATETIME(6) NULL,
    security_mode_disabled_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_incidents_status (incident_status, updated_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_actions (
    action_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_by_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    reason_text VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    request_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    second_review_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    second_review_note VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    second_reviewed_at_utc DATETIME(6) NULL,
    result_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    completed_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_incident_actions_pending (incident_id, status_code, requested_at_utc),
    CONSTRAINT fk_mgw_incident_actions_incident FOREIGN KEY (incident_id)
        REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_key_checks (
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    note_text VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    updated_by_ref VARCHAR(191) COLLATE utf8mb4_bin NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (incident_id, key_code),
    CONSTRAINT fk_mgw_incident_key_checks_incident FOREIGN KEY (incident_id)
        REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_evidence (
    evidence_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label_text VARCHAR(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    reference_text VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    fingerprint_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    captured_by_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_incident_evidence_incident (incident_id, created_at_utc),
    CONSTRAINT fk_mgw_incident_evidence_incident FOREIGN KEY (incident_id)
        REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_restore_status (
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    status_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    backup_reference VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    rollback_reference VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    restore_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    notes_text VARCHAR(1200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    updated_by_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    CONSTRAINT fk_mgw_incident_restore_incident FOREIGN KEY (incident_id)
        REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    incident_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    reason_text VARCHAR(800) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    before_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    after_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_incident_audit_incident (incident_id, created_at_utc),
    CONSTRAINT fk_mgw_incident_audit_incident FOREIGN KEY (incident_id)
        REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incidents (
    incident_id TEXT NOT NULL PRIMARY KEY,
    title TEXT NOT NULL,
    incident_status TEXT NOT NULL,
    summary_text TEXT NULL,
    opened_by_ref TEXT NOT NULL,
    opened_at_utc TEXT NOT NULL,
    resolved_by_ref TEXT NULL,
    resolved_at_utc TEXT NULL,
    security_mode_before_json TEXT NULL,
    security_mode_enabled_at_utc TEXT NULL,
    security_mode_disabled_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_incidents_status ON mgw_incidents (incident_status, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_actions (
    action_id TEXT NOT NULL PRIMARY KEY,
    incident_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    status_code TEXT NOT NULL,
    requested_by_ref TEXT NOT NULL,
    reason_text TEXT NOT NULL,
    request_json TEXT NULL,
    requested_at_utc TEXT NOT NULL,
    second_review_by_ref TEXT NULL,
    second_review_note TEXT NULL,
    second_reviewed_at_utc TEXT NULL,
    result_json TEXT NULL,
    completed_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (incident_id) REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_incident_actions_pending ON mgw_incident_actions (incident_id, status_code, requested_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_key_checks (
    incident_id TEXT NOT NULL,
    key_code TEXT NOT NULL,
    status_code TEXT NOT NULL,
    note_text TEXT NULL,
    updated_by_ref TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (incident_id, key_code),
    FOREIGN KEY (incident_id) REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_evidence (
    evidence_id TEXT NOT NULL PRIMARY KEY,
    incident_id TEXT NOT NULL,
    evidence_type TEXT NOT NULL,
    label_text TEXT NOT NULL,
    reference_text TEXT NOT NULL,
    fingerprint_sha256 TEXT NULL,
    captured_by_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (incident_id) REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_incident_evidence_incident ON mgw_incident_evidence (incident_id, created_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_restore_status (
    incident_id TEXT NOT NULL PRIMARY KEY,
    status_code TEXT NOT NULL,
    backup_reference TEXT NULL,
    rollback_reference TEXT NULL,
    restore_sha TEXT NULL,
    notes_text TEXT NULL,
    updated_by_ref TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (incident_id) REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_incident_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    reason_text TEXT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    created_at_utc TEXT NOT NULL,
    FOREIGN KEY (incident_id) REFERENCES mgw_incidents (incident_id) ON DELETE CASCADE ON UPDATE RESTRICT
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_incident_audit_incident ON mgw_incident_audit (incident_id, created_at_utc)');
    }
};
