<?php
declare(strict_types=1);

return new class implements DatabaseMigrationInterface {
    public function version(): string
    {
        return '20260716_0001_create_mgw_meta';
    }

    public function description(): string
    {
        return 'Create the database metadata table used by later schema versions.';
    }

    public function up(DatabaseConnectionInterface $database): void
    {
        $sql = $database->driver() === 'sqlite'
            ? <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_meta (
    meta_key TEXT NOT NULL PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at_utc TEXT NOT NULL
)
SQL
            : <<<'SQL'
CREATE TABLE IF NOT EXISTS mgw_meta (
    meta_key VARCHAR(191) NOT NULL PRIMARY KEY,
    meta_value TEXT NOT NULL,
    updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        $database->execute($sql);
    }
};
