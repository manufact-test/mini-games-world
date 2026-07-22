<?php
declare(strict_types=1);

final class RuntimePrimaryStagingMutatingSmokeRollbackGuard
{
    private const REQUIRED_MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private DatabasePrimaryStateStorageAdapter $storage,
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionAuditorInterface $auditor
    ) {
        if ($this->storage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER
            || $this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging mutating smoke rollback guard requires DB-primary MySQL storage.');
        }
    }

    public function capture(): array
    {
        $status = $this->storage->status();
        $revision = (int)($status['revision'] ?? 0);
        $stateSha = (string)($status['state_sha256'] ?? '');
        if (($status['ok'] ?? false) !== true
            || ($status['driver'] ?? '') !== DatabasePrimaryStateStorageAdapter::DRIVER
            || $revision < 1
            || !$this->validSha($stateSha)
            || ($status['projection_outbox_enabled'] ?? false) !== true) {
            throw new RuntimeException('Staging mutating smoke rollback baseline status is invalid.');
        }
        $snapshot = $this->storage->readOnly(static fn(array $state): array => $state);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Staging mutating smoke rollback baseline snapshot is unavailable.');
        }
        $snapshotSha = hash('sha256', $this->canonicalJson($snapshot));
        if (!hash_equals($stateSha, $snapshotSha)) {
            throw new RuntimeException('Staging mutating smoke rollback baseline snapshot fingerprint is invalid.');
        }

        $rows = $this->database->fetchAll(
            'SELECT state_revision, projection_version, state_sha256, status, attempt_count,
                    lease_token, lease_expires_at_utc, last_error, available_at_utc,
                    created_at_utc, updated_at_utc
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision <= :state_revision
             ORDER BY state_revision ASC',
            ['state_revision' => $revision]
        );
        if (count($rows) !== $revision) {
            throw new RuntimeException('Staging mutating smoke rollback baseline outbox chain is incomplete.');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Staging mutating smoke rollback baseline outbox row is invalid.');
            }
            $rowRevision = (int)($row['state_revision'] ?? 0);
            $rowSha = (string)($row['state_sha256'] ?? '');
            if ($rowRevision !== $index + 1
                || !$this->validSha($rowSha)
                || ($row['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
                || ($row['status'] ?? '') !== 'completed'
                || (int)($row['attempt_count'] ?? 0) < 1
                || (string)($row['lease_token'] ?? '') !== ''
                || (string)($row['lease_expires_at_utc'] ?? '') !== ''
                || (string)($row['last_error'] ?? '') !== '') {
                throw new RuntimeException('Staging mutating smoke rollback baseline outbox event is not cleanly completed.');
            }
            if ($rowRevision === $revision && !hash_equals($stateSha, $rowSha)) {
                throw new RuntimeException('Staging mutating smoke rollback baseline current event fingerprint mismatch.');
            }
            $normalized[] = [
                'state_revision' => $rowRevision,
                'projection_version' => (string)$row['projection_version'],
                'state_sha256' => $rowSha,
                'status' => (string)$row['status'],
                'attempt_count' => (int)$row['attempt_count'],
                'lease_token' => (string)$row['lease_token'],
                'lease_expires_at_utc' => (string)$row['lease_expires_at_utc'],
                'last_error' => (string)$row['last_error'],
                'available_at_utc' => (string)($row['available_at_utc'] ?? ''),
                'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
                'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
            ];
        }

        $audit = $this->auditor->auditOnly($snapshot, $revision, $stateSha);
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, (string)($audit['state_sha256'] ?? ''))
            || !$this->validSha((string)($audit['all_module_fingerprint'] ?? ''))) {
            throw new RuntimeException('Staging mutating smoke rollback baseline all-module audit is invalid.');
        }
        $modules = array_values(array_unique(array_map('strval', (array)($audit['projected_modules'] ?? []))));
        sort($modules, SORT_STRING);
        $required = self::REQUIRED_MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Staging mutating smoke rollback baseline is missing projected modules.');
        }

        return [
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'snapshot_sha256' => $snapshotSha,
            'outbox_event_count' => count($normalized),
            'outbox_fingerprint' => hash('sha256', $this->canonicalJson($normalized)),
            'all_module_fingerprint' => (string)$audit['all_module_fingerprint'],
        ];
    }

    public function assertRestored(array $baseline): array
    {
        $expectedKeys = [
            'all_module_fingerprint', 'outbox_event_count', 'outbox_fingerprint',
            'snapshot_sha256', 'state_revision', 'state_sha256',
        ];
        $keys = array_keys($baseline);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            throw new RuntimeException('Staging mutating smoke rollback baseline schema is invalid.');
        }
        $after = $this->capture();
        foreach ($expectedKeys as $field) {
            if ($after[$field] !== $baseline[$field]) {
                throw new RuntimeException('Staging mutating smoke rollback restoration failed: ' . $field . '.');
            }
        }
        return [
            'ok' => true,
            'action' => 'staging_mutating_smoke_rollback_verified',
            'state_revision_restored' => true,
            'state_sha256_restored' => true,
            'snapshot_restored' => true,
            'outbox_restored' => true,
            'all_module_projection_restored' => true,
            'committed_state_write_count' => 0,
            'committed_outbox_event_count' => 0,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function validSha(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
