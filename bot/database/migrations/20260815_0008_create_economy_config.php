<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/economy/EconomyConfigSimulator.php';
require_once dirname(__DIR__, 2) . '/economy/EconomyConfigDefinition.php';

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260815_0008_create_economy_config';
    }

    public function description(): string
    {
        return 'Create one versioned DB-primary economy configuration with append-only audit history.';
    }

    public function transactional(): bool
    {
        return false;
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        if ($database->driver() === 'sqlite') {
            $this->upSqlite($database);
        } else {
            $this->upMysql($database);
        }

        $versionCount = (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_economy_config_versions');
        $stateCount = (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_economy_config_state WHERE singleton_id = 1');

        if ($versionCount === 0) {
            if ($stateCount !== 0) {
                throw new RuntimeException('Economy config state exists without version history.');
            }

            $defaults = EconomyConfigDefinition::normalize(EconomyConfigDefinition::defaults());
            $json = EconomyConfigDefinition::canonicalJson($defaults);
            $sha = hash('sha256', $json);
            $createdAt = gmdate('Y-m-d H:i:s');

            $database->execute(
                'INSERT INTO mgw_economy_config_versions '
                . '(version, schema_version, config_json, config_sha256, previous_version, change_type, source_version, actor_ref, reason, created_at_utc) '
                . 'VALUES (:version, :schema_version, :config_json, :config_sha256, NULL, :change_type, NULL, :actor_ref, :reason, :created_at_utc)',
                [
                    'version' => 1,
                    'schema_version' => EconomyConfigDefinition::SCHEMA_VERSION,
                    'config_json' => $json,
                    'config_sha256' => $sha,
                    'change_type' => 'seed',
                    'actor_ref' => 'system:migration',
                    'reason' => 'MVP-15.8 canonical roadmap defaults.',
                    'created_at_utc' => $createdAt,
                ]
            );
            $database->execute(
                'INSERT INTO mgw_economy_config_state (singleton_id, current_version, updated_at_utc) '
                . 'VALUES (1, 1, :updated_at_utc)',
                ['updated_at_utc' => $createdAt]
            );
            return;
        }

        if ($stateCount !== 1) {
            throw new RuntimeException('Existing economy config history has no canonical current-version pointer.');
        }

        $currentVersion = (int)$database->fetchValue(
            'SELECT current_version FROM mgw_economy_config_state WHERE singleton_id = 1'
        );
        $currentExists = (int)$database->fetchValue(
            'SELECT COUNT(*) FROM mgw_economy_config_versions WHERE version = :version',
            ['version' => $currentVersion]
        );
        if ($currentExists !== 1) {
            throw new RuntimeException('Economy config current-version pointer is invalid.');
        }
    }

    private function upMysql(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_economy_config_versions (
    version BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version INT UNSIGNED NOT NULL,
    config_json LONGTEXT NOT NULL,
    config_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    previous_version BIGINT UNSIGNED NULL,
    change_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_version BIGINT UNSIGNED NULL,
    actor_ref VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_at_utc DATETIME(6) NOT NULL,
    INDEX idx_mgw_economy_config_created (created_at_utc, version),
    INDEX idx_mgw_economy_config_previous (previous_version),
    CONSTRAINT chk_mgw_economy_config_change CHECK (change_type IN ('seed','update','rollback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_economy_config_state (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    current_version BIGINT UNSIGNED NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL,
    CONSTRAINT chk_mgw_economy_config_singleton CHECK (singleton_id = 1),
    CONSTRAINT fk_mgw_economy_config_current FOREIGN KEY (current_version)
        REFERENCES mgw_economy_config_versions (version) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function upSqlite(DatabaseConnectionInterface $database): void
    {
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_economy_config_versions (
    version INTEGER NOT NULL PRIMARY KEY,
    schema_version INTEGER NOT NULL,
    config_json TEXT NOT NULL,
    config_sha256 TEXT NOT NULL,
    previous_version INTEGER NULL,
    change_type TEXT NOT NULL CHECK (change_type IN ('seed','update','rollback')),
    source_version INTEGER NULL,
    actor_ref TEXT NOT NULL,
    reason TEXT NOT NULL,
    created_at_utc TEXT NOT NULL
)
SQL);
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_economy_config_created '
            . 'ON mgw_economy_config_versions (created_at_utc, version)'
        );
        $database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_economy_config_previous '
            . 'ON mgw_economy_config_versions (previous_version)'
        );
        $database->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_economy_config_state (
    singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1),
    current_version INTEGER NOT NULL,
    updated_at_utc TEXT NOT NULL,
    FOREIGN KEY (current_version) REFERENCES mgw_economy_config_versions (version) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL);
    }
};
