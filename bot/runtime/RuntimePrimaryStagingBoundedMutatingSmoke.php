<?php
declare(strict_types=1);

final class RuntimePrimaryStagingBoundedMutatingSmoke
{
    public const REPORT_TYPE = 'mvp-14.9-bounded-rollback-staging-mutating-smoke';
    public const ACTION = 'staging_mutating_smoke_rolled_back_and_verified';
    private const SENTINEL_KEY = '__mgw_staging_mutating_smoke';
    private const REQUIRED_MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private DatabaseConnectionInterface $mutationDatabase,
        private DatabaseConnectionInterface $verificationDatabase,
        private DatabasePrimaryStateStorageAdapter $mutationStorage,
        private DatabasePrimaryStateStorageAdapter $verificationStorage,
        private RuntimePrimaryProjectionWorkerInterface $worker,
        private RuntimePrimaryProjectionAuditorInterface $mutationAuditor,
        private RuntimePrimaryProjectionAuditorInterface $verificationAuditor,
        private ?int $now = null
    ) {
        if ($this->mutationDatabase->driver() !== 'mysql'
            || $this->verificationDatabase->driver() !== 'mysql') {
            throw new RuntimeException('Bounded staging mutating smoke requires two MySQL/MariaDB connections.');
        }
        if ($this->mutationStorage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER
            || $this->verificationStorage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Bounded staging mutating smoke requires DB-primary storage adapters.');
        }
    }

    public function run(
        string $approvalId,
        int $expectedBaselineRevision,
        string $expectedBaselineSha256,
        string $challengeSha256
    ): array {
        $this->assertSha($approvalId, 'Bounded staging mutating smoke approval ID');
        $this->assertSha($expectedBaselineSha256, 'Expected staging baseline fingerprint');
        $this->assertSha($challengeSha256, 'Bounded staging mutating smoke challenge fingerprint');
        if ($expectedBaselineRevision < 1) {
            throw new RuntimeException('Expected staging baseline revision must be positive.');
        }

        $baseline = $this->capture(
            $this->verificationStorage,
            $this->verificationDatabase,
            $this->verificationAuditor
        );
        if ($baseline['state_revision'] !== $expectedBaselineRevision
            || !hash_equals($baseline['state_sha256'], $expectedBaselineSha256)) {
            throw new RuntimeException('Current DB-primary state no longer matches the approved mutating baseline.');
        }
        if (array_key_exists(self::SENTINEL_KEY, $baseline['snapshot'])) {
            throw new RuntimeException('DB-primary baseline already contains the reserved mutating smoke sentinel.');
        }

        $inside = [];
        $rollbackSignalCaught = false;
        try {
            $this->mutationDatabase->transaction(function () use (
                $approvalId,
                $challengeSha256,
                $expectedBaselineRevision,
                $expectedBaselineSha256,
                &$inside
            ): void {
                $before = $this->mutationStorage->status();
                if (($before['ok'] ?? false) !== true
                    || (int)($before['revision'] ?? 0) !== $expectedBaselineRevision
                    || !hash_equals(
                        $expectedBaselineSha256,
                        (string)($before['state_sha256'] ?? '')
                    )) {
                    throw new RuntimeException('Mutation connection no longer matches the approved staging baseline.');
                }

                $this->mutationStorage->transaction(function (array &$state) use (
                    $approvalId,
                    $challengeSha256,
                    $expectedBaselineRevision
                ): void {
                    if (array_key_exists(self::SENTINEL_KEY, $state)) {
                        throw new RuntimeException('Reserved mutating smoke sentinel already exists.');
                    }
                    $state[self::SENTINEL_KEY] = [
                        'contract_version' => 'v1-single-write-transaction-rollback',
                        'approval_id_sha256' => hash('sha256', $approvalId),
                        'challenge_sha256' => $challengeSha256,
                        'baseline_revision' => $expectedBaselineRevision,
                    ];
                });

                $mutatedStatus = $this->mutationStorage->status();
                $mutationRevision = (int)($mutatedStatus['revision'] ?? 0);
                $mutationSha = (string)($mutatedStatus['state_sha256'] ?? '');
                if (($mutatedStatus['ok'] ?? false) !== true
                    || $mutationRevision !== $expectedBaselineRevision + 1
                    || !$this->validSha($mutationSha)
                    || hash_equals($expectedBaselineSha256, $mutationSha)) {
                    throw new RuntimeException('Bounded staging mutating smoke did not create exactly one temporary state revision.');
                }

                $tick = $this->worker->runOnce();
                $this->assertWorkerTick($tick, $mutationRevision, $mutationSha);

                $mutatedSnapshot = $this->mutationStorage->readOnly(
                    static fn(array $state): array => $state
                );
                if (!is_array($mutatedSnapshot)
                    || !is_array($mutatedSnapshot[self::SENTINEL_KEY] ?? null)) {
                    throw new RuntimeException('Temporary mutating smoke sentinel is missing from the transactional state.');
                }
                $audit = $this->mutationAuditor->auditOnly(
                    $mutatedSnapshot,
                    $mutationRevision,
                    $mutationSha
                );
                $this->assertAudit($audit, $mutationRevision, $mutationSha);
                $event = $this->eventForRevision($this->mutationDatabase, $mutationRevision);
                $this->assertCompletedEvent($event, $mutationRevision, $mutationSha);

                $inside = [
                    'mutation_state_revision' => $mutationRevision,
                    'mutation_state_sha256' => $mutationSha,
                    'worker_tick_count' => 1,
                    'worker_action' => (string)$tick['action'],
                    'worker_claimed' => true,
                    'projected_modules' => self::REQUIRED_MODULES,
                    'all_module_fingerprint' => (string)$audit['all_module_fingerprint'],
                    'projection_event_status' => 'completed',
                    'projection_attempt_count' => (int)$event['attempt_count'],
                    'temporary_state_write_count' => 1,
                    'temporary_projection_completed' => true,
                    'temporary_all_module_audit_passed' => true,
                ];

                throw new RuntimePrimaryStagingMutatingSmokeRollbackSignal($approvalId);
            });
        } catch (RuntimePrimaryStagingMutatingSmokeRollbackSignal $signal) {
            if (!hash_equals($approvalId, $signal->approvalId())) {
                throw new RuntimeException('Bounded mutating smoke rollback signal approval mismatch.', 0, $signal);
            }
            $rollbackSignalCaught = true;
        }

        if (!$rollbackSignalCaught || $inside === []) {
            throw new RuntimeException('Bounded staging mutating smoke did not prove mandatory rollback execution.');
        }

        $after = $this->capture(
            $this->verificationStorage,
            $this->verificationDatabase,
            $this->verificationAuditor
        );
        if (array_key_exists(self::SENTINEL_KEY, $after['snapshot'])) {
            throw new RuntimeException('Mutating smoke sentinel survived the mandatory database rollback.');
        }
        foreach (['state_revision', 'state_sha256', 'outbox_fingerprint', 'all_module_fingerprint'] as $field) {
            if ($baseline[$field] !== $after[$field]) {
                throw new RuntimeException('Mandatory rollback did not restore exact staging evidence: ' . $field . '.');
            }
        }
        if ($baseline['snapshot'] !== $after['snapshot']) {
            throw new RuntimeException('Mandatory rollback did not restore the exact DB-primary snapshot.');
        }

        return [
            'ok' => true,
            'report_type' => self::REPORT_TYPE,
            'action' => self::ACTION,
            'approval_id' => $approvalId,
            'baseline_state_revision' => $baseline['state_revision'],
            'baseline_state_sha256' => $baseline['state_sha256'],
            'baseline_outbox_fingerprint' => $baseline['outbox_fingerprint'],
            'baseline_all_module_fingerprint' => $baseline['all_module_fingerprint'],
            'mutation_state_revision' => $inside['mutation_state_revision'],
            'mutation_state_sha256' => $inside['mutation_state_sha256'],
            'worker_tick_count' => $inside['worker_tick_count'],
            'worker_action' => $inside['worker_action'],
            'worker_claimed' => $inside['worker_claimed'],
            'projected_modules' => $inside['projected_modules'],
            'all_module_fingerprint_during_mutation' => $inside['all_module_fingerprint'],
            'projection_event_status' => $inside['projection_event_status'],
            'projection_attempt_count' => $inside['projection_attempt_count'],
            'temporary_state_write_count' => $inside['temporary_state_write_count'],
            'temporary_projection_completed' => $inside['temporary_projection_completed'],
            'temporary_all_module_audit_passed' => $inside['temporary_all_module_audit_passed'],
            'rollback_signal_caught' => true,
            'state_revision_restored' => true,
            'state_sha256_restored' => true,
            'snapshot_restored' => true,
            'outbox_restored' => true,
            'all_module_projection_restored' => true,
            'committed_state_write_count' => 0,
            'committed_outbox_event_count' => 0,
            'persistent_config_changed' => false,
            'http_route_added' => false,
            'webhook_allowed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM, $this->timestamp()),
        ];
    }

    private function capture(
        DatabasePrimaryStateStorageAdapter $storage,
        DatabaseConnectionInterface $database,
        RuntimePrimaryProjectionAuditorInterface $auditor
    ): array {
        $status = $storage->status();
        $revision = (int)($status['revision'] ?? 0);
        $stateSha = (string)($status['state_sha256'] ?? '');
        if (($status['ok'] ?? false) !== true
            || ($status['driver'] ?? '') !== DatabasePrimaryStateStorageAdapter::DRIVER
            || $revision < 1
            || !$this->validSha($stateSha)
            || ($status['projection_outbox_enabled'] ?? false) !== true) {
            throw new RuntimeException('Bounded staging mutating smoke DB-primary status is invalid.');
        }
        $snapshot = $storage->readOnly(static fn(array $state): array => $state);
        if (!is_array($snapshot)
            || !hash_equals($stateSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Bounded staging mutating smoke snapshot fingerprint is invalid.');
        }
        $rows = $database->fetchAll(
            'SELECT state_revision, projection_version, state_sha256, status, attempt_count,
                    lease_token, lease_expires_at_utc, last_error, available_at_utc,
                    created_at_utc, updated_at_utc
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision <= :state_revision
             ORDER BY state_revision ASC',
            ['state_revision' => $revision]
        );
        if (count($rows) !== $revision) {
            throw new RuntimeException('Bounded staging mutating smoke outbox chain is incomplete.');
        }
        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Bounded staging mutating smoke outbox row is invalid.');
            }
            $rowRevision = (int)($row['state_revision'] ?? 0);
            $rowSha = (string)($row['state_sha256'] ?? '');
            if ($rowRevision !== $index + 1) {
                throw new RuntimeException('Bounded staging mutating smoke outbox revisions are not contiguous.');
            }
            $this->assertCompletedEvent($row, $rowRevision, $rowSha);
            if ($rowRevision === $revision && !hash_equals($stateSha, $rowSha)) {
                throw new RuntimeException('Current outbox event does not match the DB-primary state fingerprint.');
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
        $audit = $auditor->auditOnly($snapshot, $revision, $stateSha);
        $this->assertAudit($audit, $revision, $stateSha);

        return [
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'snapshot' => $snapshot,
            'outbox_fingerprint' => hash('sha256', $this->canonicalJson($normalized)),
            'all_module_fingerprint' => (string)$audit['all_module_fingerprint'],
        ];
    }

    private function eventForRevision(DatabaseConnectionInterface $database, int $revision): array
    {
        $rows = $database->fetchAll(
            'SELECT state_revision, projection_version, state_sha256, status, attempt_count,
                    lease_token, lease_expires_at_utc, last_error
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $revision]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Temporary mutating smoke projection event is missing or ambiguous.');
        }
        return $rows[0];
    }

    private function assertWorkerTick(array $tick, int $revision, string $stateSha): void
    {
        if (($tick['ok'] ?? false) !== true
            || ($tick['action'] ?? '') !== 'projection_completed'
            || ($tick['claimed'] ?? false) !== true
            || (int)($tick['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, (string)($tick['state_sha256'] ?? ''))
            || ($tick['parity_ok'] ?? false) !== true) {
            throw new RuntimeException('Bounded staging mutating smoke worker did not complete the temporary revision.');
        }
        $this->assertModules((array)($tick['projected_modules'] ?? []));
    }

    private function assertAudit(array $audit, int $revision, string $stateSha): void
    {
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, (string)($audit['state_sha256'] ?? ''))
            || !$this->validSha((string)($audit['all_module_fingerprint'] ?? ''))) {
            throw new RuntimeException('Bounded staging mutating smoke all-module audit is invalid.');
        }
        $this->assertModules((array)($audit['projected_modules'] ?? []));
    }

    private function assertModules(array $modules): void
    {
        $modules = array_values(array_unique(array_map('strval', $modules)));
        sort($modules, SORT_STRING);
        $required = self::REQUIRED_MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Bounded staging mutating smoke is missing required projected modules.');
        }
    }

    private function assertCompletedEvent(array $event, int $revision, string $stateSha): void
    {
        if ((int)($event['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, (string)($event['state_sha256'] ?? ''))
            || ($event['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
            || ($event['status'] ?? '') !== 'completed'
            || (int)($event['attempt_count'] ?? 0) < 1
            || (string)($event['lease_token'] ?? '') !== ''
            || (string)($event['lease_expires_at_utc'] ?? '') !== ''
            || (string)($event['last_error'] ?? '') !== '') {
            throw new RuntimeException('Bounded staging mutating smoke projection event is not cleanly completed.');
        }
    }

    private function assertSha(string $value, string $label): void
    {
        if (!$this->validSha($value)) {
            throw new RuntimeException($label . ' is invalid.');
        }
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
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

    private function timestamp(): int
    {
        return $this->now ?? time();
    }
}
