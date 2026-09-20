<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260920_0047_create_yearly_medals';
    }

    public function description(): string
    {
        return 'Create MVP-20.6 annual medal designs, quarter fragments and audited idempotent eligibility runs.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
            $this->seed2026($database);
            return;
        }

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_designs (
    calendar_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    medal_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    theme_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    design_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    design_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_mgw_yearly_medal_designs_medal (medal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_fragments (
    calendar_year SMALLINT UNSIGNED NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    medal_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fragment_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    unlocked_at_utc DATETIME(6) NOT NULL,
    revoked_at_utc DATETIME(6) NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (calendar_year, mgw_id, quarter),
    INDEX idx_mgw_yearly_medal_fragments_user (mgw_id, calendar_year, fragment_state),
    INDEX idx_mgw_yearly_medal_fragments_season (season_id, fragment_state, quarter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_runs (
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    calendar_year SMALLINT UNSIGNED NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    medal_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    eligibility_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    reconciled_at_utc DATETIME(6) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_yearly_medal_runs_year (calendar_year, quarter, updated_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    calendar_year SMALLINT UNSIGNED NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action_code VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_ref VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_yearly_medal_audit_scope (season_id, revision, audit_id),
    INDEX idx_mgw_yearly_medal_audit_user (mgw_id, calendar_year, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->seed2026($database);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_designs (
    calendar_year INTEGER NOT NULL PRIMARY KEY,
    medal_id TEXT NOT NULL UNIQUE,
    theme_key TEXT NOT NULL,
    design_state TEXT NOT NULL,
    design_revision INTEGER NOT NULL DEFAULT 1,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_fragments (
    calendar_year INTEGER NOT NULL,
    mgw_id TEXT NOT NULL,
    quarter INTEGER NOT NULL,
    season_id TEXT NOT NULL,
    medal_id TEXT NOT NULL,
    fragment_state TEXT NOT NULL,
    revision INTEGER NOT NULL,
    unlocked_at_utc TEXT NOT NULL,
    revoked_at_utc TEXT NULL,
    updated_at_utc TEXT NOT NULL,
    PRIMARY KEY (calendar_year, mgw_id, quarter)
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_yearly_medal_fragments_user ON mgw_yearly_medal_fragments (mgw_id, calendar_year, fragment_state)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_yearly_medal_fragments_season ON mgw_yearly_medal_fragments (season_id, fragment_state, quarter)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_runs (
    season_id TEXT NOT NULL PRIMARY KEY,
    calendar_year INTEGER NOT NULL,
    quarter INTEGER NOT NULL,
    medal_id TEXT NOT NULL,
    eligibility_fingerprint TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0,
    eligible_count INTEGER NOT NULL DEFAULT 0,
    last_reason TEXT NOT NULL,
    last_actor_ref TEXT NOT NULL,
    reconciled_at_utc TEXT NOT NULL,
    created_at_utc TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_yearly_medal_runs_year ON mgw_yearly_medal_runs (calendar_year, quarter, updated_at_utc)');

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_yearly_medal_audit (
    audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id TEXT NOT NULL,
    calendar_year INTEGER NOT NULL,
    quarter INTEGER NOT NULL,
    mgw_id TEXT NOT NULL,
    action_code TEXT NOT NULL,
    reason_code TEXT NOT NULL,
    actor_ref TEXT NOT NULL,
    revision INTEGER NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_yearly_medal_audit_scope ON mgw_yearly_medal_audit (season_id, revision, audit_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_mgw_yearly_medal_audit_user ON mgw_yearly_medal_audit (mgw_id, calendar_year, created_at_utc)');
    }

    private function seed2026(DatabaseConnectionInterface $database): void
    {
        $params = [
            'calendar_year' => 2026,
            'medal_id' => 'annual-2026-neon-orbit',
            'theme_key' => 'neon-orbit-2026',
            'design_state' => 'ready',
            'design_revision' => 1,
            'created_at_utc' => '2026-09-20 00:00:00.000000',
            'updated_at_utc' => '2026-09-20 00:00:00.000000',
        ];
        $sql = $database->driver() === 'sqlite'
            ? 'INSERT OR IGNORE INTO mgw_yearly_medal_designs (
                   calendar_year, medal_id, theme_key, design_state, design_revision,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :calendar_year, :medal_id, :theme_key, :design_state, :design_revision,
                   :created_at_utc, :updated_at_utc
               )'
            : 'INSERT IGNORE INTO mgw_yearly_medal_designs (
                   calendar_year, medal_id, theme_key, design_state, design_revision,
                   created_at_utc, updated_at_utc
               ) VALUES (
                   :calendar_year, :medal_id, :theme_key, :design_state, :design_revision,
                   :created_at_utc, :updated_at_utc
               )';
        $database->execute($sql, $params);
    }
};
