<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0048_create_rating_admin_review';
    }

    public function description(): string
    {
        return 'Create MVP-20.8 reviewed rating exclusions and auditable recalculation jobs.';
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
CREATE TABLE IF NOT EXISTS mgw_rating_review_exclusions (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    exclusion_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL,
    PRIMARY KEY (season_id, mgw_id),
    INDEX idx_mgw_rating_review_exclusions_state (season_id, exclusion_state, updated_at_utc),
    INDEX idx_mgw_rating_review_exclusions_user (mgw_id, exclusion_state, updated_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_review_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    review_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_rating_review_audit_season (season_id, audit_id),
    INDEX idx_mgw_rating_review_audit_user (mgw_id, audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_recalculation_jobs (
    job_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    job_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    reason_text VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    exclusions_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    error_text VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    started_at_utc DATETIME(6) NULL,
    completed_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_rating_recalc_jobs_state (job_state, created_at_utc),
    INDEX idx_mgw_rating_recalc_jobs_season (season_id, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_review_exclusions (
    season_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    exclusion_state TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    review_note TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT NULL,
    PRIMARY KEY (season_id, mgw_id)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_review_exclusions_state ON mgw_rating_review_exclusions (season_id, exclusion_state, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_review_exclusions_user ON mgw_rating_review_exclusions (mgw_id, exclusion_state, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_review_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    mgw_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    review_note TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_review_audit_season ON mgw_rating_review_audit (season_id, audit_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_review_audit_user ON mgw_rating_review_audit (mgw_id, audit_id)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_rating_recalculation_jobs (
    job_id TEXT NOT NULL PRIMARY KEY,
    job_type TEXT NOT NULL,
    season_id TEXT NOT NULL,
    job_state TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    reason_text TEXT NOT NULL,
    exclusions_json TEXT NOT NULL,
    result_json TEXT NULL,
    error_text TEXT NULL,
    created_at_utc TEXT NOT NULL,
    started_at_utc TEXT NULL,
    completed_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_recalc_jobs_state ON mgw_rating_recalculation_jobs (job_state, created_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_rating_recalc_jobs_season ON mgw_rating_recalculation_jobs (season_id, created_at_utc)');
    }
};
