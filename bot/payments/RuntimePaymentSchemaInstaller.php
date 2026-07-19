<?php
declare(strict_types=1);

final class RuntimePaymentSchemaInstaller
{
    private const META_KEY = 'runtime_payment_schema_v1';
    private const VERSION = 'runtime_payment_schema_v1';

    public function __construct(private DatabaseConnectionInterface $database) {}

    public function install(): array
    {
        $definition = $this->definition();
        $checksum = hash('sha256', LedgerIntegrity::canonicalJson($definition));
        $existing = $this->metadata();
        if ($existing !== null
            && (string)($existing['status'] ?? '') === 'completed'
            && hash_equals((string)($existing['checksum'] ?? ''), $checksum)) {
            $verification = $this->verify();
            if (!empty($verification['ok'])) {
                return [
                    'ok' => true,
                    'action' => 'install_noop',
                    'idempotent' => true,
                    'version' => self::VERSION,
                    'checksum' => $checksum,
                    'verification' => $verification,
                    'production_changed' => false,
                    'sensitive_identifiers_exposed' => false,
                ];
            }
        }

        $this->createTable();
        $verification = $this->verify();
        if (empty($verification['ok'])) {
            throw new RuntimeException('Payment runtime schema verification failed.');
        }

        $metadata = [
            'status' => 'completed',
            'version' => self::VERSION,
            'checksum' => $checksum,
            'installed_at_utc' => $this->now(),
            'driver' => $this->database->driver(),
        ];
        $this->writeMetadata($metadata);

        return [
            'ok' => true,
            'action' => $existing === null ? 'installed' : 'repaired',
            'idempotent' => false,
            'version' => self::VERSION,
            'checksum' => $checksum,
            'verification' => $verification,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    public function verify(): array
    {
        $columns = [
            'payment_ref',
            'account_ref',
            'legacy_user_id',
            'source_position',
            'provider',
            'status_raw',
            'room_raw',
            'coin_amount',
            'amount_rub',
            'currency',
            'balance_applied',
            'payload_json',
            'payload_sha256',
            'source_created_at_utc',
            'source_updated_at_utc',
            'source_decided_at_utc',
            'synced_at_utc',
        ];

        $present = [];
        if ($this->database->driver() === 'mysql') {
            $rows = $this->database->fetchAll(
                "SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'mgw_runtime_payments'"
            );
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? '')));
                if ($name !== '') $present[$name] = true;
            }
        } else {
            $rows = $this->database->fetchAll('PRAGMA table_info(mgw_runtime_payments)');
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['name'] ?? '')));
                if ($name !== '') $present[$name] = true;
            }
        }

        $missing = array_values(array_filter(
            $columns,
            static fn(string $column): bool => !isset($present[$column])
        ));
        return [
            'ok' => $missing === [],
            'required_column_count' => count($columns),
            'present_column_count' => count(array_intersect($columns, array_keys($present))),
            'missing_columns' => $missing,
        ];
    }

    private function createTable(): void
    {
        if ($this->database->driver() === 'mysql') {
            $this->database->execute(
                'CREATE TABLE IF NOT EXISTS mgw_runtime_payments (
                    payment_ref VARCHAR(191) NOT NULL,
                    account_ref VARCHAR(255) NOT NULL,
                    legacy_user_id VARCHAR(191) NULL,
                    source_position BIGINT NOT NULL,
                    provider VARCHAR(64) NULL,
                    status_raw VARCHAR(64) NOT NULL,
                    room_raw VARCHAR(32) NOT NULL,
                    coin_amount BIGINT NOT NULL DEFAULT 0,
                    amount_rub BIGINT NOT NULL DEFAULT 0,
                    currency VARCHAR(8) NOT NULL,
                    balance_applied TINYINT(1) NOT NULL DEFAULT 0,
                    payload_json LONGTEXT NOT NULL,
                    payload_sha256 CHAR(64) NOT NULL,
                    source_created_at_utc DATETIME(6) NULL,
                    source_updated_at_utc DATETIME(6) NULL,
                    source_decided_at_utc DATETIME(6) NULL,
                    synced_at_utc DATETIME(6) NOT NULL,
                    PRIMARY KEY (payment_ref),
                    KEY idx_mgw_runtime_payment_account (account_ref),
                    KEY idx_mgw_runtime_payment_legacy_user (legacy_user_id),
                    KEY idx_mgw_runtime_payment_status (status_raw),
                    KEY idx_mgw_runtime_payment_position (source_position)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            return;
        }

        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS mgw_runtime_payments (
                payment_ref TEXT NOT NULL PRIMARY KEY,
                account_ref TEXT NOT NULL,
                legacy_user_id TEXT NULL,
                source_position INTEGER NOT NULL,
                provider TEXT NULL,
                status_raw TEXT NOT NULL,
                room_raw TEXT NOT NULL,
                coin_amount INTEGER NOT NULL DEFAULT 0,
                amount_rub INTEGER NOT NULL DEFAULT 0,
                currency TEXT NOT NULL,
                balance_applied INTEGER NOT NULL DEFAULT 0,
                payload_json TEXT NOT NULL,
                payload_sha256 TEXT NOT NULL,
                source_created_at_utc TEXT NULL,
                source_updated_at_utc TEXT NULL,
                source_decided_at_utc TEXT NULL,
                synced_at_utc TEXT NOT NULL
            )'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_payment_account ON mgw_runtime_payments (account_ref)'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_payment_legacy_user ON mgw_runtime_payments (legacy_user_id)'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_payment_status ON mgw_runtime_payments (status_raw)'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_payment_position ON mgw_runtime_payments (source_position)'
        );
    }

    private function definition(): array
    {
        return [
            'version' => self::VERSION,
            'table' => 'mgw_runtime_payments',
            'columns' => [
                'payment_ref:string:primary',
                'account_ref:string',
                'legacy_user_id:nullable_string',
                'source_position:integer',
                'provider:nullable_string',
                'status_raw:string',
                'room_raw:string',
                'coin_amount:integer',
                'amount_rub:integer',
                'currency:string',
                'balance_applied:boolean',
                'payload_json:json_text',
                'payload_sha256:sha256',
                'source_created_at_utc:nullable_timestamp',
                'source_updated_at_utc:nullable_timestamp',
                'source_decided_at_utc:nullable_timestamp',
                'synced_at_utc:timestamp',
            ],
            'unique' => ['payment_ref'],
        ];
    }

    private function metadata(): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => self::META_KEY]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1) throw new RuntimeException('Payment runtime schema metadata is duplicated.');
        try {
            $decoded = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Payment runtime schema metadata is invalid.', 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('Payment runtime schema metadata root is invalid.');
        return $decoded;
    }

    private function writeMetadata(array $metadata): void
    {
        $value = LedgerIntegrity::canonicalJson($metadata);
        $updated = $this->database->execute(
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc '
            . 'WHERE meta_key = :meta_key',
            ['meta_value' => $value, 'updated_at_utc' => $this->now(), 'meta_key' => self::META_KEY]
        );
        if ($updated === 0) {
            $this->database->execute(
                'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc) '
                . 'VALUES (:meta_key, :meta_value, :updated_at_utc)',
                ['meta_key' => self::META_KEY, 'meta_value' => $value, 'updated_at_utc' => $this->now()]
            );
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
