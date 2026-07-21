<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiReadOnlySmoke
{
    public function __construct(
        private DatabasePrimaryStateStorageAdapter $storage,
        private DatabaseConnectionInterface $database,
        private array $successHooks,
        private array $dataFilters
    ) {}

    public function run(): array
    {
        $context = RuntimePrimaryEntrypointStorageContext::safeReport();
        if (($context['installed'] ?? false) !== true
            || ($context['entrypoint'] ?? '') !== 'api'
            || ($context['storage_driver'] ?? '') !== 'database'
            || ($context['request_finalizer_registered'] ?? false) !== true
            || ($context['dynamic_session_readiness'] ?? false) !== true
            || ($context['legacy_json_bridges_suppressed'] ?? false) !== true
            || ($context['webhook_allowed'] ?? true) !== false
            || ($context['production_changed'] ?? true) !== false
            || RuntimePrimaryEntrypointStorageContext::storage() !== $this->storage) {
            throw new RuntimeException('Read-only API smoke requires the exact guarded request context.');
        }
        if ($this->successHooks === []
            || !($this->successHooks[0] ?? null) instanceof RuntimePrimaryStagingApiRequestFinalizationHook) {
            throw new RuntimeException('Read-only API smoke requires the finalizer as the first success hook.');
        }
        foreach ($this->successHooks as $hook) {
            if (!is_callable($hook)) {
                throw new RuntimeException('Read-only API smoke success hook registry is invalid.');
            }
        }
        foreach ($this->dataFilters as $filter) {
            if (!is_callable($filter)) {
                throw new RuntimeException('Read-only API smoke data filter registry is invalid.');
            }
        }

        $before = $this->capture();
        $probe = $this->storage->readOnly(function (array $state): array {
            return [
                'state_sha256' => $this->canonicalSha($state),
                'top_level_keys' => $this->topLevelKeys($state),
                'top_level_count' => count($state),
            ];
        });
        if (!is_array($probe)
            || !hash_equals((string)$before['state_sha256'], (string)($probe['state_sha256'] ?? ''))) {
            throw new RuntimeException('Read-only API smoke snapshot does not match DB-primary status.');
        }

        foreach ($this->successHooks as $hook) {
            $hook();
        }
        $filtered = ['smoke' => 'read_only', 'sentinel' => 1];
        foreach ($this->dataFilters as $filter) {
            $next = $filter($filtered);
            if (!is_array($next)) {
                throw new RuntimeException('Read-only API smoke data filter returned a non-array value.');
            }
            $filtered = $next;
        }
        if ($filtered !== ['smoke' => 'read_only', 'sentinel' => 1]) {
            throw new RuntimeException('Read-only API smoke data filters changed the sentinel payload.');
        }

        $finalization = $GLOBALS['mgw_api_db_primary_finalization_report'] ?? null;
        if (!is_array($finalization)
            || ($finalization['attempted'] ?? false) !== true
            || ($finalization['completed'] ?? false) !== true
            || ($finalization['projection_event_status'] ?? '') !== 'completed'
            || (int)($finalization['worker_tick_count'] ?? -1) !== 0
            || ($finalization['read_only_audit'] ?? false) !== true
            || ($finalization['legacy_json_bridges_suppressed'] ?? false) !== true
            || ($finalization['api_only'] ?? false) !== true
            || ($finalization['webhook_allowed'] ?? true) !== false
            || ($finalization['production_changed'] ?? true) !== false) {
            throw new RuntimeException('Read-only API smoke finalization contract is incomplete or not read-only.');
        }

        $after = $this->capture();
        if ($before !== $after) {
            throw new RuntimeException('Read-only API smoke changed DB-primary state or outbox.');
        }

        return [
            'ok' => true,
            'action' => 'staging_api_read_only_smoke_passed',
            'state_revision' => (int)$after['state_revision'],
            'state_sha256' => (string)$after['state_sha256'],
            'outbox_event_count' => (int)$after['outbox_event_count'],
            'outbox_fingerprint' => (string)$after['outbox_fingerprint'],
            'top_level_count' => (int)($probe['top_level_count'] ?? 0),
            'top_level_keys_fingerprint' => hash(
                'sha256',
                json_encode(
                    $probe['top_level_keys'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ),
            'worker_tick_count' => 0,
            'state_unchanged' => true,
            'snapshot_unchanged' => true,
            'outbox_unchanged' => true,
            'data_filters_unchanged' => true,
            'request_finalizer_completed' => true,
            'private_config_changed' => false,
            'http_route_added' => false,
            'api_only' => true,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function capture(): array
    {
        $status = $this->storage->status();
        $revision = (int)($status['revision'] ?? 0);
        $stateSha = strtolower(trim((string)($status['state_sha256'] ?? '')));
        if (($status['ok'] ?? false) !== true
            || ($status['driver'] ?? '') !== 'database'
            || $revision < 1
            || preg_match('/^[a-f0-9]{64}$/', $stateSha) !== 1
            || ($status['projection_outbox_enabled'] ?? false) !== true) {
            throw new RuntimeException('Read-only API smoke DB-primary status is invalid.');
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
            throw new RuntimeException('Read-only API smoke outbox revision chain is incomplete.');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Read-only API smoke outbox row is invalid.');
            }
            $expectedRevision = $index + 1;
            $rowRevision = (int)($row['state_revision'] ?? 0);
            $rowSha = strtolower(trim((string)($row['state_sha256'] ?? '')));
            if ($rowRevision !== $expectedRevision
                || ($row['status'] ?? '') !== 'completed'
                || preg_match('/^[a-f0-9]{64}$/', $rowSha) !== 1
                || (int)($row['attempt_count'] ?? -1) < 0
                || trim((string)($row['projection_version'] ?? '')) === '') {
                throw new RuntimeException('Read-only API smoke outbox completion chain is invalid.');
            }
            if ($rowRevision === $revision && !hash_equals($stateSha, $rowSha)) {
                throw new RuntimeException('Read-only API smoke current outbox fingerprint does not match state.');
            }
            $normalized[] = [
                'state_revision' => $rowRevision,
                'projection_version' => (string)$row['projection_version'],
                'state_sha256' => $rowSha,
                'status' => (string)$row['status'],
                'attempt_count' => (int)$row['attempt_count'],
                'lease_token' => (string)($row['lease_token'] ?? ''),
                'lease_expires_at_utc' => (string)($row['lease_expires_at_utc'] ?? ''),
                'last_error' => (string)($row['last_error'] ?? ''),
                'available_at_utc' => (string)($row['available_at_utc'] ?? ''),
                'created_at_utc' => (string)($row['created_at_utc'] ?? ''),
                'updated_at_utc' => (string)($row['updated_at_utc'] ?? ''),
            ];
        }

        return [
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'outbox_event_count' => count($normalized),
            'outbox_fingerprint' => hash('sha256', $this->canonicalJson($normalized)),
        ];
    }

    private function canonicalSha(array $state): string
    {
        return hash('sha256', $this->canonicalJson($state));
    }

    private function topLevelKeys(array $state): array
    {
        $keys = array_map('strval', array_keys($state));
        sort($keys, SORT_STRING);
        return $keys;
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
