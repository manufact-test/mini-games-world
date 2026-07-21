<?php
declare(strict_types=1);

final class RuntimePrimaryStagingSchemaInspector
{
    public function __construct(private DatabaseConnectionInterface $database)
    {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging activation schema inspection requires MySQL/MariaDB.');
        }
    }

    public function inspect(): array
    {
        return [
            'state' => $this->inspectState(),
            'outbox' => $this->inspectOutbox(),
            'read_only' => true,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function inspectState(): array
    {
        $columns = $this->columns(RuntimePrimaryStateSchemaInstaller::TABLE);
        $required = [
            'created_at_utc', 'revision', 'singleton_id',
            'state_json', 'state_sha256', 'updated_at_utc',
        ];
        $this->assertExactColumns($columns, $required, 'state');
        foreach ($columns as $name => $column) {
            if (($column['not_null'] ?? false) !== true) {
                throw new RuntimeException('Staging state schema column must be NOT NULL: ' . $name . '.');
            }
        }
        if (($columns['singleton_id']['primary'] ?? false) !== true) {
            throw new RuntimeException('Staging state schema singleton_id must be the primary key.');
        }
        foreach (array_diff(array_keys($columns), ['singleton_id']) as $name) {
            if (($columns[$name]['primary'] ?? false) === true) {
                throw new RuntimeException('Staging state schema has an unexpected primary-key column: ' . $name . '.');
            }
        }
        $this->assertPrefixType($columns, 'singleton_id', 'tinyint', true, 'state');
        $this->assertPrefixType($columns, 'revision', 'bigint', true, 'state');
        $this->assertExactType($columns, 'state_json', ['longtext', 'json'], 'state');
        $this->assertExactType($columns, 'state_sha256', ['char(64)'], 'state');
        $this->assertExactType($columns, 'created_at_utc', ['varchar(40)'], 'state');
        $this->assertExactType($columns, 'updated_at_utc', ['varchar(40)'], 'state');
        $engine = $this->engine(RuntimePrimaryStateSchemaInstaller::TABLE);
        $fingerprint = hash('sha256', $this->canonicalJson([
            'driver' => 'mysql',
            'engine' => $engine,
            'columns' => $columns,
        ]));
        return [
            'ok' => true,
            'table' => RuntimePrimaryStateSchemaInstaller::TABLE,
            'driver' => 'mysql',
            'engine' => $engine,
            'schema_fingerprint' => $fingerprint,
            'read_only' => true,
        ];
    }

    private function inspectOutbox(): array
    {
        $columns = $this->columns(RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE);
        $required = [
            'attempt_count', 'available_at_utc', 'created_at_utc', 'event_id',
            'last_error', 'lease_expires_at_utc', 'lease_token', 'projection_version',
            'state_json', 'state_revision', 'state_sha256', 'status', 'updated_at_utc',
        ];
        $this->assertExactColumns($columns, $required, 'outbox');
        foreach ($columns as $name => $column) {
            if (($column['not_null'] ?? false) !== true) {
                throw new RuntimeException('Staging outbox schema column must be NOT NULL: ' . $name . '.');
            }
        }
        if (($columns['state_revision']['primary'] ?? false) !== true) {
            throw new RuntimeException('Staging outbox schema state_revision must be the primary key.');
        }
        foreach (array_diff(array_keys($columns), ['state_revision']) as $name) {
            if (($columns[$name]['primary'] ?? false) === true) {
                throw new RuntimeException('Staging outbox schema has an unexpected primary-key column: ' . $name . '.');
            }
        }
        $this->assertPrefixType($columns, 'state_revision', 'bigint', true, 'outbox');
        $this->assertExactType($columns, 'event_id', ['char(64)'], 'outbox');
        $this->assertExactType($columns, 'projection_version', ['varchar(64)'], 'outbox');
        $this->assertExactType($columns, 'state_sha256', ['char(64)'], 'outbox');
        $this->assertExactType($columns, 'state_json', ['longtext', 'json'], 'outbox');
        $this->assertExactType($columns, 'status', ['varchar(16)'], 'outbox');
        $this->assertPrefixType($columns, 'attempt_count', 'int', true, 'outbox');
        $this->assertExactType($columns, 'lease_token', ['varchar(64)'], 'outbox');
        $this->assertExactType($columns, 'lease_expires_at_utc', ['varchar(40)'], 'outbox');
        $this->assertExactType($columns, 'last_error', ['varchar(500)'], 'outbox');
        $this->assertExactType($columns, 'available_at_utc', ['varchar(40)'], 'outbox');
        $this->assertExactType($columns, 'created_at_utc', ['varchar(40)'], 'outbox');
        $this->assertExactType($columns, 'updated_at_utc', ['varchar(40)'], 'outbox');
        $engine = $this->engine(RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE);
        $fingerprint = hash('sha256', json_encode([
            'driver' => 'mysql',
            'engine' => $engine,
            'columns' => $columns,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [
            'ok' => true,
            'table' => RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE,
            'driver' => 'mysql',
            'engine' => $engine,
            'schema_fingerprint' => $fingerprint,
            'read_only' => true,
        ];
    }

    private function columns(string $table): array
    {
        $result = [];
        foreach ($this->database->fetchAll('SHOW COLUMNS FROM ' . $table) as $row) {
            $name = strtolower(trim((string)($row['Field'] ?? '')));
            if ($name === '') continue;
            $result[$name] = [
                'type' => strtolower(trim((string)($row['Type'] ?? ''))),
                'not_null' => strtoupper(trim((string)($row['Null'] ?? ''))) === 'NO',
                'primary' => strtoupper(trim((string)($row['Key'] ?? ''))) === 'PRI',
            ];
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function engine(string $table): string
    {
        $rows = $this->database->fetchAll("SHOW TABLE STATUS LIKE '" . $table . "'");
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Staging schema table status is unavailable: ' . $table . '.');
        }
        $engine = strtolower(trim((string)($rows[0]['Engine'] ?? '')));
        if ($engine !== 'innodb') {
            throw new RuntimeException('Staging DB-primary table must use InnoDB: ' . $table . '.');
        }
        return $engine;
    }

    private function assertExactColumns(array $columns, array $required, string $label): void
    {
        sort($required, SORT_STRING);
        if (array_keys($columns) !== $required) {
            throw new RuntimeException('Staging ' . $label . ' schema columns do not match the exact contract.');
        }
    }

    private function assertPrefixType(
        array $columns,
        string $column,
        string $prefix,
        bool $unsigned,
        string $label
    ): void {
        $type = (string)($columns[$column]['type'] ?? '');
        if (!str_starts_with($type, $prefix) || ($unsigned && !str_contains($type, 'unsigned'))) {
            throw new RuntimeException('Staging ' . $label . ' schema type is invalid: ' . $column . '.');
        }
    }

    private function assertExactType(array $columns, string $column, array $allowed, string $label): void
    {
        $type = (string)($columns[$column]['type'] ?? '');
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Staging ' . $label . ' schema type is invalid: ' . $column . '.');
        }
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
