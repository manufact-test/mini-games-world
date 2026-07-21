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
        $details = $this->columnDetails($driver);
        $this->assertExactColumns($details);
        $this->assertColumnContract($driver, $details);
        $engine = $driver === 'mysql' ? $this->mysqlEngine() : 'sqlite';

        return [
            'ok' => true,
            'table' => self::TABLE,
            'driver' => $driver,
            'engine' => $engine,
            'columns' => array_keys($details),
            'schema_fingerprint' => hash('sha256', $this->canonicalJson([
                'driver' => $driver,
                'engine' => $engine,
                'columns' => $details,
            ])),
        ];
    }

    private function columnDetails(string $driver): array
    {
        $details = [];
        if ($driver === 'mysql') {
            $rows = $this->database->fetchAll('SHOW COLUMNS FROM ' . self::TABLE);
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['Field'] ?? '')));
                if ($name === '') continue;
                $details[$name] = [
                    'type' => strtolower(trim((string)($row['Type'] ?? ''))),
                    'not_null' => strtoupper(trim((string)($row['Null'] ?? ''))) === 'NO',
                    'primary' => strtoupper(trim((string)($row['Key'] ?? ''))) === 'PRI',
                ];
            }
        } else {
            $rows = $this->database->fetchAll('PRAGMA table_info(' . self::TABLE . ')');
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['name'] ?? '')));
                if ($name === '') continue;
                $details[$name] = [
                    'type' => strtolower(trim((string)($row['type'] ?? ''))),
                    'not_null' => (int)($row['notnull'] ?? 0) === 1,
                    'primary' => (int)($row['pk'] ?? 0) === 1,
                ];
            }
        }
        ksort($details, SORT_STRING);
        return $details;
    }

    private function assertExactColumns(array $details): void
    {
        $actual = array_keys($details);
        $required = $this->requiredColumns();
        $missing = array_values(array_diff($required, $actual));
        $unexpected = array_values(array_diff($actual, $required));
        if ($missing !== [] || $unexpected !== []) {
            $parts = [];
            if ($missing !== []) $parts[] = 'missing: ' . implode(', ', $missing);
            if ($unexpected !== []) $parts[] = 'unexpected: ' . implode(', ', $unexpected);
            throw new RuntimeException(
                'Runtime primary state schema columns are invalid (' . implode('; ', $parts) . ').'
            );
        }
    }

    private function assertColumnContract(string $driver, array $details): void
    {
        foreach ($details as $name => $detail) {
            if (($detail['not_null'] ?? false) !== true) {
                throw new RuntimeException('Runtime primary state column must be NOT NULL: ' . $name . '.');
            }
        }
        if (($details['singleton_id']['primary'] ?? false) !== true) {
            throw new RuntimeException('Runtime primary state singleton_id must be the primary key.');
        }
        foreach (array_diff(array_keys($details), ['singleton_id']) as $name) {
            if (($details[$name]['primary'] ?? false) === true) {
                throw new RuntimeException('Runtime primary state has an unexpected primary-key column: ' . $name . '.');
            }
        }

        if ($driver === 'mysql') {
            $this->assertMysqlType($details, 'singleton_id', 'tinyint', true);
            $this->assertMysqlType($details, 'revision', 'bigint', true);
            $this->assertExactType($details, 'state_json', ['longtext', 'json']);
            $this->assertExactType($details, 'state_sha256', ['char(64)']);
            $this->assertExactType($details, 'created_at_utc', ['varchar(40)']);
            $this->assertExactType($details, 'updated_at_utc', ['varchar(40)']);
            return;
        }

        $this->assertExactType($details, 'singleton_id', ['integer']);
        $this->assertExactType($details, 'revision', ['integer']);
        $this->assertExactType($details, 'state_json', ['text']);
        $this->assertExactType($details, 'state_sha256', ['text']);
        $this->assertExactType($details, 'created_at_utc', ['text']);
        $this->assertExactType($details, 'updated_at_utc', ['text']);
    }

    private function assertMysqlType(array $details, string $column, string $prefix, bool $unsigned): void
    {
        $type = (string)($details[$column]['type'] ?? '');
        if (!str_starts_with($type, $prefix)
            || ($unsigned && !str_contains($type, 'unsigned'))) {
            throw new RuntimeException('Runtime primary state column type is invalid: ' . $column . '.');
        }
    }

    private function assertExactType(array $details, string $column, array $allowed): void
    {
        $type = (string)($details[$column]['type'] ?? '');
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Runtime primary state column type is invalid: ' . $column . '.');
        }
    }

    private function mysqlEngine(): string
    {
        $rows = $this->database->fetchAll(
            "SHOW TABLE STATUS LIKE '" . self::TABLE . "'"
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Runtime primary state table status is unavailable.');
        }
        $engine = strtolower(trim((string)($rows[0]['Engine'] ?? '')));
        if ($engine !== 'innodb') {
            throw new RuntimeException('Runtime primary state table must use InnoDB.');
        }
        return $engine;
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

    private function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
