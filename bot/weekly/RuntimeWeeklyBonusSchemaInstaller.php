<?php
declare(strict_types=1);

final class RuntimeWeeklyBonusSchemaInstaller
{
    private const META_KEY = 'runtime_weekly_bonus_schema_v1';
    private const VERSION = 'runtime_weekly_bonus_schema_v1';

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
            throw new RuntimeException('Weekly bonus runtime schema verification failed.');
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
            'account_ref',
            'legacy_user_id',
            'state_json',
            'state_sha256',
            'status_json',
            'status_sha256',
            'checked_key',
            'last_key',
            'welcome_grant_done',
            'checked_games',
            'source_updated_at_utc',
            'synced_at_utc',
        ];

        $present = [];
        if ($this->database->driver() === 'mysql') {
            $rows = $this->database->fetchAll(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'mgw_runtime_weekly_bonus_state'"
            );
            foreach ($rows as $row) {
                $name = strtolower(trim((string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? '')));
                if ($name !== '') $present[$name] = true;
            }
        } else {
            $rows = $this->database->fetchAll('PRAGMA table_info(mgw_runtime_weekly_bonus_state)');
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
                'CREATE TABLE IF NOT EXISTS mgw_runtime_weekly_bonus_state (
                    account_ref VARCHAR(255) NOT NULL,
                    legacy_user_id VARCHAR(191) NOT NULL,
                    state_json LONGTEXT NOT NULL,
                    state_sha256 CHAR(64) NOT NULL,
                    status_json LONGTEXT NOT NULL,
                    status_sha256 CHAR(64) NOT NULL,
                    checked_key VARCHAR(64) NULL,
                    last_key VARCHAR(64) NULL,
                    welcome_grant_done TINYINT(1) NOT NULL DEFAULT 0,
                    checked_games INT NOT NULL DEFAULT 0,
                    source_updated_at_utc DATETIME(6) NULL,
                    synced_at_utc DATETIME(6) NOT NULL,
                    PRIMARY KEY (account_ref),
                    UNIQUE KEY uq_mgw_runtime_weekly_legacy_user (legacy_user_id),
                    KEY idx_mgw_runtime_weekly_checked_key (checked_key),
                    KEY idx_mgw_runtime_weekly_last_key (last_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            return;
        }

        $this->database->execute(
            'CREATE TABLE IF NOT EXISTS mgw_runtime_weekly_bonus_state (
                account_ref TEXT NOT NULL PRIMARY KEY,
                legacy_user_id TEXT NOT NULL,
                state_json TEXT NOT NULL,
                state_sha256 TEXT NOT NULL,
                status_json TEXT NOT NULL,
                status_sha256 TEXT NOT NULL,
                checked_key TEXT NULL,
                last_key TEXT NULL,
                welcome_grant_done INTEGER NOT NULL DEFAULT 0,
                checked_games INTEGER NOT NULL DEFAULT 0,
                source_updated_at_utc TEXT NULL,
                synced_at_utc TEXT NOT NULL
            )'
        );
        $this->database->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_mgw_runtime_weekly_legacy_user '
            . 'ON mgw_runtime_weekly_bonus_state (legacy_user_id)'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_weekly_checked_key '
            . 'ON mgw_runtime_weekly_bonus_state (checked_key)'
        );
        $this->database->execute(
            'CREATE INDEX IF NOT EXISTS idx_mgw_runtime_weekly_last_key '
            . 'ON mgw_runtime_weekly_bonus_state (last_key)'
        );
    }

    private function definition(): array
    {
        return [
            'version' => self::VERSION,
            'table' => 'mgw_runtime_weekly_bonus_state',
            'columns' => [
                'account_ref:string:primary',
                'legacy_user_id:string:unique',
                'state_json:json_text',
                'state_sha256:sha256',
                'status_json:json_text',
                'status_sha256:sha256',
                'checked_key:nullable_string',
                'last_key:nullable_string',
                'welcome_grant_done:boolean',
                'checked_games:integer',
                'source_updated_at_utc:nullable_timestamp',
                'synced_at_utc:timestamp',
            ],
            'unique' => ['account_ref', 'legacy_user_id'],
        ];
    }

    private function metadata(): ?array
    {
        $rows = $this->database->fetchAll(
            'SELECT meta_value FROM mgw_meta WHERE meta_key = :meta_key',
            ['meta_key' => self::META_KEY]
        );
        if ($rows === []) return null;
        if (count($rows) !== 1) throw new RuntimeException('Weekly bonus runtime schema metadata is duplicated.');

        try {
            $decoded = json_decode((string)$rows[0]['meta_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Weekly bonus runtime schema metadata is invalid.', 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('Weekly bonus runtime schema metadata root is invalid.');
        return $decoded;
    }

    private function writeMetadata(array $metadata): void
    {
        $value = LedgerIntegrity::canonicalJson($metadata);
        $updated = $this->database->execute(
            'UPDATE mgw_meta SET meta_value = :meta_value, updated_at_utc = :updated_at_utc WHERE meta_key = :meta_key',
            ['meta_value' => $value, 'updated_at_utc' => $this->now(), 'meta_key' => self::META_KEY]
        );
        if ($updated === 0) {
            $this->database->execute(
                'INSERT INTO mgw_meta (meta_key, meta_value, updated_at_utc) VALUES (:meta_key, :meta_value, :updated_at_utc)',
                ['meta_key' => self::META_KEY, 'meta_value' => $value, 'updated_at_utc' => $this->now()]
            );
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
