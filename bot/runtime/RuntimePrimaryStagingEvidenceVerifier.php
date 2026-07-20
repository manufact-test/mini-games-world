<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceVerifier
{
    public const MANIFEST_VERSION = 'v1-staging-db-primary-evidence';

    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    private const FORBIDDEN_KEYS = [
        'state_json', 'snapshot', 'snapshot_payload', 'users', 'user',
        'telegram_id', 'provider_subject', 'mgw_id', 'account_ref',
        'payment_id', 'email', 'phone', 'token', 'secret', 'password',
    ];

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence project root is unavailable.');
        }
    }

    public function verify(array $manifest): array
    {
        $blockers = [];
        $this->assertNoSensitivePayload($manifest, '$', $blockers);
        $this->assertExactKeys($manifest, [
            'manifest_version', 'environment', 'repository_commit', 'generated_at_utc',
            'php', 'database', 'schemas', 'source_snapshot',
            'first_rehearsal', 'repeated_rehearsal', 'concurrency', 'entrypoint_evidence',
        ], 'manifest', $blockers);

        if (($manifest['manifest_version'] ?? '') !== self::MANIFEST_VERSION) {
            $blockers[] = 'Manifest version is unsupported.';
        }
        if (strtolower(trim((string)($manifest['environment'] ?? ''))) !== 'staging') {
            $blockers[] = 'Evidence environment must be staging.';
        }
        if (preg_match('/^[a-f0-9]{40}$/', strtolower(trim((string)($manifest['repository_commit'] ?? '')))) !== 1) {
            $blockers[] = 'Repository commit must be a full 40-character Git SHA.';
        }
        if (!$this->validTimestamp($manifest['generated_at_utc'] ?? null)) {
            $blockers[] = 'Manifest generated_at_utc must contain an explicit UTC offset.';
        }

        $this->verifyPhp($this->section($manifest, 'php'), $blockers);
        $this->verifyDatabase($this->section($manifest, 'database'), $blockers);
        $this->verifySchemas($this->section($manifest, 'schemas'), $blockers);
        $snapshot = $this->verifySnapshot($this->section($manifest, 'source_snapshot'), $blockers);
        $first = $this->verifyRehearsal(
            $this->section($manifest, 'first_rehearsal'),
            'first_rehearsal',
            true,
            $blockers
        );
        $repeated = $this->verifyRehearsal(
            $this->section($manifest, 'repeated_rehearsal'),
            'repeated_rehearsal',
            false,
            $blockers
        );
        $this->verifyRehearsalRelationship($snapshot, $first, $repeated, $blockers);
        $this->verifyConcurrency($this->section($manifest, 'concurrency'), $blockers);
        $this->verifyEntrypoints($this->section($manifest, 'entrypoint_evidence'), $blockers);

        $blockers = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $blockers
        ), static fn(string $value): bool => $value !== '')));
        $fingerprint = hash('sha256', $this->canonicalJson($manifest));

        return [
            'ok' => $blockers === [],
            'report_type' => 'mvp-14.8.6f-staging-evidence-verification',
            'manifest_version' => self::MANIFEST_VERSION,
            'repository_commit' => strtolower(trim((string)($manifest['repository_commit'] ?? ''))),
            'evidence_fingerprint' => $fingerprint,
            'required_modules' => self::MODULES,
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ];
    }

    private function verifyPhp(array $php, array &$blockers): void
    {
        $this->assertExactKeys($php, ['version', 'version_id', 'sapi'], 'php', $blockers);
        $versionId = (int)($php['version_id'] ?? 0);
        if ($versionId < 80300 || $versionId >= 80400) {
            $blockers[] = 'Staging evidence must use PHP 8.3.x.';
        }
        $version = trim((string)($php['version'] ?? ''));
        if ($version === '' || !str_starts_with($version, '8.3.')) {
            $blockers[] = 'PHP version string must identify PHP 8.3.x.';
        }
        if (strtolower(trim((string)($php['sapi'] ?? ''))) !== 'cli') {
            $blockers[] = 'Staging evidence must be collected through PHP CLI.';
        }
    }

    private function verifyDatabase(array $database, array &$blockers): void
    {
        $this->assertExactKeys(
            $database,
            ['driver', 'server_version', 'state_engine', 'outbox_engine'],
            'database',
            $blockers
        );
        if (strtolower(trim((string)($database['driver'] ?? ''))) !== 'mysql') {
            $blockers[] = 'Staging evidence database driver must be mysql.';
        }
        if (trim((string)($database['server_version'] ?? '')) === '') {
            $blockers[] = 'Staging evidence must record the MySQL/MariaDB server version.';
        }
        foreach (['state_engine', 'outbox_engine'] as $field) {
            if (strtolower(trim((string)($database[$field] ?? ''))) !== 'innodb') {
                $blockers[] = 'Staging evidence ' . $field . ' must be InnoDB.';
            }
        }
    }

    private function verifySchemas(array $schemas, array &$blockers): void
    {
        $this->assertExactKeys($schemas, ['state', 'outbox'], 'schemas', $blockers);
        $expected = [
            'state' => RuntimePrimaryStateSchemaInstaller::TABLE,
            'outbox' => RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE,
        ];
        foreach ($expected as $name => $table) {
            $schema = is_array($schemas[$name] ?? null) ? $schemas[$name] : [];
            $this->assertExactKeys(
                $schema,
                ['table', 'schema_fingerprint'],
                'schemas.' . $name,
                $blockers
            );
            if ((string)($schema['table'] ?? '') !== $table) {
                $blockers[] = 'Schema table does not match the required contract: ' . $name . '.';
            }
            if (!$this->validSha($schema['schema_fingerprint'] ?? null)) {
                $blockers[] = 'Schema fingerprint must be SHA-256: ' . $name . '.';
            }
        }
    }

    private function verifySnapshot(array $snapshot, array &$blockers): array
    {
        $this->assertExactKeys($snapshot, [
            'before_sha256', 'after_first_sha256', 'after_second_sha256',
            'inventory_fingerprint',
        ], 'source_snapshot', $blockers);
        foreach (['before_sha256', 'after_first_sha256', 'after_second_sha256', 'inventory_fingerprint'] as $field) {
            if (!$this->validSha($snapshot[$field] ?? null)) {
                $blockers[] = 'Source snapshot field must be SHA-256: ' . $field . '.';
            }
        }
        $before = strtolower(trim((string)($snapshot['before_sha256'] ?? '')));
        $afterFirst = strtolower(trim((string)($snapshot['after_first_sha256'] ?? '')));
        $afterSecond = strtolower(trim((string)($snapshot['after_second_sha256'] ?? '')));
        if ($before === '' || !hash_equals($before, $afterFirst) || !hash_equals($before, $afterSecond)) {
            $blockers[] = 'JSON rollback source changed during staging rehearsal evidence collection.';
        }
        return [
            'sha256' => $before,
            'inventory_fingerprint' => strtolower(trim((string)($snapshot['inventory_fingerprint'] ?? ''))),
        ];
    }

    private function verifyRehearsal(
        array $report,
        string $label,
        bool $first,
        array &$blockers
    ): array {
        $this->assertExactKeys($report, [
            'ok', 'action', 'snapshot_action', 'target_revision', 'target_sha256',
            'target_event_status', 'target_event_completed', 'status_healthy',
            'parity_completed', 'worker_tick_count', 'projected_modules',
            'application_entrypoints_changed', 'cron_changed', 'production_changed',
            'sensitive_identifiers_exposed',
        ], $label, $blockers);

        if (($report['ok'] ?? false) !== true
            || (string)($report['action'] ?? '') !== 'rehearsal_completed'
            || ($report['target_event_completed'] ?? false) !== true
            || ($report['status_healthy'] ?? false) !== true
            || ($report['parity_completed'] ?? false) !== true
            || (string)($report['target_event_status'] ?? '') !== 'completed') {
            $blockers[] = $label . ' did not complete with healthy parity.';
        }
        if ((int)($report['target_revision'] ?? 0) < 1) {
            $blockers[] = $label . ' target revision must be positive.';
        }
        if (!$this->validSha($report['target_sha256'] ?? null)) {
            $blockers[] = $label . ' target fingerprint must be SHA-256.';
        }
        $snapshotAction = (string)($report['snapshot_action'] ?? '');
        if ($first && !in_array($snapshotAction, ['snapshot_initialized', 'snapshot_revision_created'], true)) {
            $blockers[] = 'First rehearsal must initialize or create a fresh snapshot revision.';
        }
        if (!$first && $snapshotAction !== 'snapshot_unchanged') {
            $blockers[] = 'Repeated rehearsal must prove the snapshot revision is unchanged.';
        }
        $tickCount = (int)($report['worker_tick_count'] ?? -1);
        if ($first && ($tickCount < 1 || $tickCount > 100)) {
            $blockers[] = 'First rehearsal must process at least one and at most 100 worker events.';
        }
        if (!$first && $tickCount !== 0) {
            $blockers[] = 'Repeated rehearsal must require zero worker ticks.';
        }

        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($report['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $required = self::MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            $blockers[] = $label . ' is missing required projected modules.';
        }
        foreach ([
            'application_entrypoints_changed', 'cron_changed',
            'production_changed', 'sensitive_identifiers_exposed',
        ] as $field) {
            if (($report[$field] ?? null) !== false) {
                $blockers[] = $label . ' violates the safety flag: ' . $field . '.';
            }
        }

        return [
            'revision' => (int)($report['target_revision'] ?? 0),
            'sha256' => strtolower(trim((string)($report['target_sha256'] ?? ''))),
        ];
    }

    private function verifyRehearsalRelationship(
        array $snapshot,
        array $first,
        array $repeated,
        array &$blockers
    ): void {
        if ($snapshot['sha256'] === ''
            || !hash_equals($snapshot['sha256'], $first['sha256'])
            || !hash_equals($snapshot['sha256'], $repeated['sha256'])) {
            $blockers[] = 'Rehearsal target fingerprints do not match the unchanged JSON source.';
        }
        if ($first['revision'] < 1 || $first['revision'] !== $repeated['revision']) {
            $blockers[] = 'Repeated rehearsal must target the same completed state revision.';
        }
    }

    private function verifyConcurrency(array $concurrency, array &$blockers): void
    {
        $this->assertExactKeys($concurrency, ['cli_lock', 'worker_lease'], 'concurrency', $blockers);
        $cli = is_array($concurrency['cli_lock'] ?? null) ? $concurrency['cli_lock'] : [];
        $this->assertExactKeys(
            $cli,
            ['ok', 'second_exit_code', 'second_result'],
            'concurrency.cli_lock',
            $blockers
        );
        if (($cli['ok'] ?? false) !== true
            || (int)($cli['second_exit_code'] ?? 0) !== 2
            || (string)($cli['second_result'] ?? '') !== 'rehearsal_lock_blocked') {
            $blockers[] = 'CLI rehearsal lock concurrency evidence is incomplete.';
        }

        $lease = is_array($concurrency['worker_lease'] ?? null) ? $concurrency['worker_lease'] : [];
        $this->assertExactKeys($lease, [
            'ok', 'state_revision', 'first_claimed', 'second_action',
            'second_state_revision', 'lease_seconds',
        ], 'concurrency.worker_lease', $blockers);
        $revision = (int)($lease['state_revision'] ?? 0);
        $leaseSeconds = (int)($lease['lease_seconds'] ?? 0);
        if (($lease['ok'] ?? false) !== true
            || $revision < 1
            || ($lease['first_claimed'] ?? false) !== true
            || (string)($lease['second_action'] ?? '') !== 'projection_busy'
            || (int)($lease['second_state_revision'] ?? 0) !== $revision
            || $leaseSeconds < 30
            || $leaseSeconds > 900) {
            $blockers[] = 'Worker lease concurrency evidence is incomplete.';
        }
    }

    private function verifyEntrypoints(array $evidence, array &$blockers): void
    {
        $current = RuntimePrimaryEntrypointEvidence::inspect($this->projectRoot);
        if (!hash_equals(
            hash('sha256', $this->canonicalJson($current)),
            hash('sha256', $this->canonicalJson($evidence))
        )) {
            $blockers[] = 'Entrypoint evidence does not match the current repository sources.';
        }
        if (($current['contract_version'] ?? '') !== 'v1-json-first-entrypoints') {
            $blockers[] = 'Entrypoint evidence contract version is unsupported.';
        }
        foreach (['api', 'webhook_handler'] as $name) {
            $entrypoint = is_array($current['entrypoints'][$name] ?? null)
                ? $current['entrypoints'][$name]
                : [];
            if (($entrypoint['direct_json_factory_present'] ?? false) !== true
                || ($entrypoint['db_primary_coordinator_present'] ?? true) !== false
                || !$this->validSha($entrypoint['source_sha256'] ?? null)) {
                $blockers[] = 'Application entrypoint is not in the required JSON-first rehearsal state: ' . $name . '.';
            }
        }
    }

    private function assertNoSensitivePayload(mixed $value, string $path, array &$blockers): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            $keyString = strtolower(trim((string)$key));
            $childPath = $path . '.' . (string)$key;
            if (in_array($keyString, self::FORBIDDEN_KEYS, true)) {
                $blockers[] = 'Evidence manifest contains a forbidden sensitive field: ' . $childPath . '.';
            }
            $this->assertNoSensitivePayload($child, $childPath, $blockers);
        }
    }

    private function assertExactKeys(
        array $value,
        array $required,
        string $label,
        array &$blockers
    ): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            $missing = array_values(array_diff($required, $actual));
            $unexpected = array_values(array_diff($actual, $required));
            if ($missing !== []) {
                $blockers[] = $label . ' is missing fields: ' . implode(', ', $missing) . '.';
            }
            if ($unexpected !== []) {
                $blockers[] = $label . ' contains unexpected fields: ' . implode(', ', $unexpected) . '.';
            }
        }
    }

    private function section(array $manifest, string $key): array
    {
        return is_array($manifest[$key] ?? null) ? $manifest[$key] : [];
    }

    private function validSha(mixed $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$value))) === 1;
    }

    private function validTimestamp(mixed $value): bool
    {
        $value = trim((string)$value);
        return $value !== ''
            && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1
            && strtotime($value) !== false;
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
