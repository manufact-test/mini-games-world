<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260926_0066_create_admin_operations';
    }

    public function description(): string
    {
        return 'Create MVP-22.7 Admin task, future plan, release log and audit owners without duplicating season reminders.';
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
CREATE TABLE IF NOT EXISTS mgw_admin_tasks (
    task_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    series_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    title VARCHAR(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    category VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recurrence_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    due_at_utc DATETIME(6) NULL,
    owner_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    task_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    completed_at_utc DATETIME(6) NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_admin_tasks_source (source_type, source_ref),
    INDEX idx_mgw_admin_tasks_status_due (task_status, due_at_utc),
    INDEX idx_mgw_admin_tasks_owner_status (owner_ref, task_status, due_at_utc),
    INDEX idx_mgw_admin_tasks_series (series_id, due_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_future_plans (
    plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    title VARCHAR(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    category VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_period VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    owner_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_admin_future_plans_status (plan_status, category, updated_at_utc),
    INDEX idx_mgw_admin_future_plans_owner (owner_ref, plan_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_release_log (
    release_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    version_label VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    environment VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    release_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    released_at_utc DATETIME(6) NOT NULL,
    summary_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    known_issues_text TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    rollback_link VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_admin_release_env_version (environment, version_label),
    INDEX idx_mgw_admin_release_released (released_at_utc, environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_operations_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    before_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    after_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_admin_operations_audit_entity (entity_type, entity_id, audit_id),
    INDEX idx_mgw_admin_operations_audit_created (created_at_utc, audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_tasks (
    task_id TEXT NOT NULL PRIMARY KEY,
    series_id TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NOT NULL,
    title TEXT NOT NULL,
    category TEXT NOT NULL,
    recurrence_code TEXT NOT NULL,
    due_at_utc TEXT NULL,
    owner_ref TEXT NULL,
    task_status TEXT NOT NULL,
    result_text TEXT NULL,
    created_by_ref TEXT NOT NULL,
    completed_at_utc TEXT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    UNIQUE (source_type, source_ref)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_tasks_status_due ON mgw_admin_tasks (task_status, due_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_tasks_owner_status ON mgw_admin_tasks (owner_ref, task_status, due_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_tasks_series ON mgw_admin_tasks (series_id, due_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_future_plans (
    plan_id TEXT NOT NULL PRIMARY KEY,
    title TEXT NOT NULL,
    category TEXT NOT NULL,
    plan_status TEXT NOT NULL,
    target_period TEXT NULL,
    owner_ref TEXT NULL,
    notes TEXT NULL,
    created_by_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_future_plans_status ON mgw_admin_future_plans (plan_status, category, updated_at_utc)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_future_plans_owner ON mgw_admin_future_plans (owner_ref, plan_status)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_release_log (
    release_id TEXT NOT NULL PRIMARY KEY,
    version_label TEXT NOT NULL,
    environment TEXT NOT NULL,
    release_sha TEXT NOT NULL,
    released_at_utc TEXT NOT NULL,
    summary_text TEXT NOT NULL,
    known_issues_text TEXT NULL,
    rollback_link TEXT NULL,
    created_by_ref TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL,
    UNIQUE (environment, version_label)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_release_released ON mgw_admin_release_log (released_at_utc, environment)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_admin_operations_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_operations_audit_entity ON mgw_admin_operations_audit (entity_type, entity_id, audit_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_admin_operations_audit_created ON mgw_admin_operations_audit (created_at_utc, audit_id)');
    }
};
