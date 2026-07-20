<?php
declare(strict_types=1);

final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function install(): array
    {
        $driver = $this->database->driver();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Runtime projection outbox supports only MySQL/MariaDB and SQLite.');
        }

        $this->database->execute($driver === 'mysql' ? $this->mysqlSql() : $this->sqliteSql());
        if ($driver === 'sqlite') {
            $this->database->execute(
                'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_projection_pending
                 ON ' . self::TABLE . ' (status, available_at_utc, state_revision)'
            );
        }

        $columns = $this->columns($driver);
        $required = [
            'state_revision', 'event_id', 'projection_version', 'state_sha256', 'state_json',
            'status', 'attempt_count', 'lease_token', 'lease_expires_at_utc', 'last_error',
            'available_at_utc', 'created_at_utc', 'updated_at_utc',
        ];
        sort($required, SORT_STRING);
        if (array_keys($columns) !== $required) {
            throw new RuntimeException('Runtime projection outbox schema columns are invalid.');
        }
        foreach ($columns as $name => $column) {
            if (($column['not_null'] ?? false) !== true) {
                throw new RuntimeException('Runtime projection outbox column must be NOT NULL: ' . $name . '.');
            }
        }
        if (($columns['state_revision']['primary'] ?? false) !== true) {
            throw new RuntimeException('Runtime projection outbox state_revision must be the primary key.');
        }

        $engine = $driver === 'mysql' ? $this->mysqlEngine() : 'sqlite';
        return [
            'ok' => true,
            'table' => self::TABLE,
            'driver' => $driver,
            'engine' => $engine,
            'schema_fingerprint' => hash('sha256', json_encode([
                'driver' => $driver,
                'engine' => $engine,
                'columns' => $columns,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }

    private function columns(string $driver): array
    {
        $result = [];
        $rows = $driver === 'mysql'
            ? $this->database->fetchAll('SHOW COLUMNS FROM ' . self::TABLE)
            : $this->database->fetchAll('PRAGMA table_info(' . self::TABLE . ')');

        foreach ($rows as $row) {
            $name = strtolower(trim((string)($driver === 'mysql' ? ($row['Field'] ?? '') : ($row['name'] ?? ''))));
            if ($name === '') continue;
            $result[$name] = [
                'type' => strtolower(trim((string)($driver === 'mysql' ? ($row['Type'] ?? '') : ($row['type'] ?? '')))),
                'not_null' => $driver === 'mysql'
                    ? strtoupper(trim((string)($row['Null'] ?? ''))) === 'NO'
                    : (int)($row['notnull'] ?? 0) === 1,
                'primary' => $driver === 'mysql'
                    ? strtoupper(trim((string)($row['Key'] ?? ''))) === 'PRI'
                    : (int)($row['pk'] ?? 0) === 1,
            ];
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function mysqlEngine(): string
    {
        $rows = $this->database->fetchAll("SHOW TABLE STATUS LIKE '" . self::TABLE . "'");
        $engine = strtolower(trim((string)($rows[0]['Engine'] ?? '')));
        if ($engine !== 'innodb') {
            throw new RuntimeException('Runtime projection outbox table must use InnoDB.');
        }
        return $engine;
    }

    private function mysqlSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            state_revision BIGINT UNSIGNED NOT NULL,
            event_id CHAR(64) NOT NULL,
            projection_version VARCHAR(64) NOT NULL,
            state_sha256 CHAR(64) NOT NULL,
            state_json LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL,
            attempt_count INT UNSIGNED NOT NULL,
            lease_token VARCHAR(64) NOT NULL,
            lease_expires_at_utc VARCHAR(40) NOT NULL,
            last_error VARCHAR(500) NOT NULL,
            available_at_utc VARCHAR(40) NOT NULL,
            created_at_utc VARCHAR(40) NOT NULL,
            updated_at_utc VARCHAR(40) NOT NULL,
            PRIMARY KEY (state_revision),
            UNIQUE KEY uq_mgw_runtime_projection_event (event_id),
            KEY idx_mgw_runtime_projection_pending (status, available_at_utc, state_revision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function sqliteSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            state_revision INTEGER NOT NULL PRIMARY KEY CHECK (state_revision >= 1),
            event_id TEXT NOT NULL UNIQUE,
            projection_version TEXT NOT NULL,
            state_sha256 TEXT NOT NULL,
            state_json TEXT NOT NULL,
            status TEXT NOT NULL,
            attempt_count INTEGER NOT NULL CHECK (attempt_count >= 0),
            lease_token TEXT NOT NULL,
            lease_expires_at_utc TEXT NOT NULL,
            last_error TEXT NOT NULL,
            available_at_utc TEXT NOT NULL,
            created_at_utc TEXT NOT NULL,
            updated_at_utc TEXT NOT NULL
        )';
    }
}
