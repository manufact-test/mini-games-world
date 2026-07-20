<?php
declare(strict_types=1);

final class RuntimePrimaryStateSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_state';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function install(): array
    {
        $driver = $this->database->driver();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Runtime primary state supports only MySQL/MariaDB and SQLite.');
        }

        $this->database->execute($driver === 'mysql' ? $this->mysqlSql() : $this->sqliteSql());
        $columns = $this->columns();
        $missing = array_values(array_diff($this->requiredColumns(), $columns));
        if ($missing !== []) {
            throw new RuntimeException(
                'Runtime primary state schema is missing columns: ' . implode(', ', $missing) . '.'
            );
        }

        return [
            'ok' => true,
            'table' => self::TABLE,
            'driver' => $driver,
            'columns' => $columns,
            'schema_fingerprint' => hash('sha256', implode('|', $columns)),
        ];
    }

    private function columns(): array
    {
        if ($this->database->driver() === 'mysql') {
            $rows = $this->database->fetchAll('SHOW COLUMNS FROM ' . self::TABLE);
            $columns = array_map(
                static fn(array $row): string => strtolower(trim((string)($row['Field'] ?? ''))),
                $rows
            );
        } else {
            $rows = $this->database->fetchAll('PRAGMA table_info(' . self::TABLE . ')');
            $columns = array_map(
                static fn(array $row): string => strtolower(trim((string)($row['name'] ?? ''))),
                $rows
            );
        }
        $columns = array_values(array_filter($columns, static fn(string $column): bool => $column !== ''));
        sort($columns, SORT_STRING);
        return $columns;
    }

    private function requiredColumns(): array
    {
        $columns = [
            'singleton_id',
            'revision',
            'state_json',
            'state_sha256',
            'created_at_utc',
            'updated_at_utc',
        ];
        sort($columns, SORT_STRING);
        return $columns;
    }

    private function mysqlSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            singleton_id TINYINT UNSIGNED NOT NULL,
            revision BIGINT UNSIGNED NOT NULL,
            state_json LONGTEXT NOT NULL,
            state_sha256 CHAR(64) NOT NULL,
            created_at_utc VARCHAR(40) NOT NULL,
            updated_at_utc VARCHAR(40) NOT NULL,
            PRIMARY KEY (singleton_id),
            CONSTRAINT chk_mgw_runtime_primary_singleton CHECK (singleton_id = 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function sqliteSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            singleton_id INTEGER NOT NULL PRIMARY KEY CHECK (singleton_id = 1),
            revision INTEGER NOT NULL CHECK (revision >= 1),
            state_json TEXT NOT NULL,
            state_sha256 TEXT NOT NULL,
            created_at_utc TEXT NOT NULL,
            updated_at_utc TEXT NOT NULL
        )';
    }
}
