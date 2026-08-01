<?php
declare(strict_types=1);

final class ProductionPrimaryAtomicStorageAdapter implements StorageAdapterInterface
{
    public const DRIVER = 'database';
    public const CONTRACT_VERSION = 'v3-production-atomic-retained-outbox-tail';

    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    private array $lastTransactionReport = [];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private DatabasePrimaryStateStorageAdapter $stateStorage,
        private RuntimePrimaryProjectionWorkerInterface $worker,
        private RuntimePrimaryProjectionAuditorInterface $auditor
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Production atomic DB-primary storage requires MySQL/MariaDB.');
        }
        if ($this->stateStorage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Production atomic storage requires DB-primary state storage.');
        }
    }

    public function driver(): string
    {
        return self::DRIVER;
    }

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction(function (
            DatabaseConnectionInterface $database
        ) use ($callback): mixed {
            $baseline = [];
            $housekeepingOnlyChangeDiscarded = false;
            $result = $this->stateStorage->transaction(
                function (array &$data) use (
                    $callback,
                    &$baseline,
                    &$housekeepingOnlyChangeDiscarded
                ): mixed {
                    $beforeCallback = $data;
                    $baseline = $this->captureLockedBaseline($data);
                    $result = $callback($data);
                    $housekeepingOnlyChangeDiscarded = $this->discardCleanupTimestampOnlyChange(
                        $data,
                        $beforeCallback
                    );
                    return $result;
                }
            );
            if ($baseline === []) {
                throw new RuntimeException('Production atomic baseline was not captured under lock.');
            }

            $afterWrite = $this->stateStorage->status();
            $beforeRevision = (int)$baseline['state_revision'];
            $beforeSha = (string)$baseline['state_sha256'];
            $afterRevision = (int)($afterWrite['revision'] ?? 0);
            $afterSha = strtolower(trim((string)($afterWrite['state_sha256'] ?? '')));
            $recoveryWorkerTicks = max(0, (int)($baseline['recovery_worker_tick_count'] ?? 0));
            $baselineTailVerified = ($baseline['queue']['retained_tail_verified'] ?? false) === true;
            $baselineAnchorRebuilt = ($baseline['projection_anchor_rebuilt'] ?? false) === true;
            $baselineHistoryReset = ($baseline['projection_history_reset_detected'] ?? false) === true;

            if ($afterRevision === $beforeRevision) {
                if (!hash_equals($beforeSha, $afterSha)) {
                    throw new RuntimeException(
                        'Production atomic transaction changed the state fingerprint without a revision.'
                    );
                }
                $this->lastTransactionReport = [
                    'ok' => true,
                    'action' => 'production_atomic_state_unchanged',
                    'contract_version' => self::CONTRACT_VERSION,
                    'state_revision' => $beforeRevision,
                    'state_sha256' => $beforeSha,
                    'worker_tick_count' => $recoveryWorkerTicks,
                    'projected_modules' => self::MODULES,
                    'all_module_fingerprint' => (string)($baseline['recovery_worker_fingerprint'] ?? ''),
                    'baseline_locked' => true,
                    'baseline_projection_chain_verified' => $baselineTailVerified,
                    'baseline_projection_retained_tail_verified' => $baselineTailVerified,
                    'baseline_projection_history_reset_detected' => $baselineHistoryReset,
                    'baseline_projection_anchor_rebuilt' => $baselineAnchorRebuilt,
                    'baseline_full_module_audit_executed' => false,
                    'final_full_module_audit_executed' => false,
                    'worker_parity_proof_reused' => $recoveryWorkerTicks > 0,
                    'housekeeping_only_change_discarded' => $housekeepingOnlyChangeDiscarded,
                    'atomic_commit_pending' => true,
                    'json_rollback_source_changed' => false,
                    'production_changed' => false,
                    'sensitive_identifiers_exposed' => false,
                ];
                return $result;
            }

            if ($afterRevision !== $beforeRevision + 1
                || preg_match('/\A[a-f0-9]{64}\z/', $afterSha) !== 1) {
                throw new RuntimeException(
                    'Production atomic transaction must advance exactly one valid state revision.'
                );
            }

            $tick = $this->worker->runOnce();
            $workerFingerprint = $this->assertCompletedProjectionTick(
                $tick,
                $afterRevision,
                $afterSha,
                'Production atomic projection'
            );

            $final = $this->captureFinalIdentity();
            if ((int)$final['state_revision'] !== $afterRevision
                || !hash_equals($afterSha, (string)$final['state_sha256'])) {
                throw new RuntimeException(
                    'Production atomic state changed during projection completion.'
                );
            }

            $this->lastTransactionReport = [
                'ok' => true,
                'action' => 'production_atomic_state_projected',
                'contract_version' => self::CONTRACT_VERSION,
                'baseline_state_revision' => $beforeRevision,
                'state_revision' => $afterRevision,
                'state_sha256' => $afterSha,
                'worker_tick_count' => $recoveryWorkerTicks + 1,
                'worker_attempt_count' => max(1, (int)($tick['attempt_count'] ?? 0)),
                'projected_modules' => self::MODULES,
                'mutated_modules' => array_values(array_map(
                    'strval',
                    (array)($tick['mutated_modules'] ?? [])
                )),
                'unchanged_modules' => array_values(array_map(
                    'strval',
                    (array)($tick['unchanged_modules'] ?? [])
                )),
                'all_module_fingerprint' => $workerFingerprint,
                'baseline_locked' => true,
                'baseline_projection_chain_verified' => $baselineTailVerified,
                'baseline_projection_retained_tail_verified' => $baselineTailVerified,
                'baseline_projection_history_reset_detected' => $baselineHistoryReset,
                'baseline_projection_anchor_rebuilt' => $baselineAnchorRebuilt,
                'baseline_full_module_audit_executed' => false,
                'final_full_module_audit_executed' => false,
                'worker_parity_proof_reused' => true,
                'housekeeping_only_change_discarded' => false,
                'atomic_commit_pending' => true,
                'json_rollback_source_changed' => false,
                'rollback_requires_fresh_db_export' => true,
                'production_changed' => false,
                'sensitive_identifiers_exposed' => false,
            ];

            return $result;
        });
    }

    public function readOnly(callable $callback): mixed
    {
        return $this->stateStorage->readOnly($callback);
    }

    public function status(): array
    {
        $status = $this->stateStorage->status();
        return $status + [
            'atomic_contract_version' => self::CONTRACT_VERSION,
            'projection_mode' => 'same_outer_transaction',
            'rollback_requires_fresh_db_export' => true,
        ];
    }

    public function lastTransactionReport(): array
    {
        return $this->lastTransactionReport;
    }

    private function captureLockedBaseline(array $snapshot): array
    {
        $status = $this->stateStorage->status();
        $baseline = $this->captureIdentity($snapshot, $status, 'baseline');
        if (($baseline['queue']['history_reset'] ?? false) !== true) {
            return $baseline + [
                'projection_history_reset_detected' => false,
                'projection_anchor_rebuilt' => false,
                'recovery_worker_tick_count' => 0,
                'recovery_worker_fingerprint' => '',
            ];
        }

        $revision = (int)$baseline['state_revision'];
        $stateSha = (string)$baseline['state_sha256'];
        $stateJson = $this->canonicalJson($snapshot);
        $created = (new RuntimePrimaryProjectionOutboxWriter())->ensurePending(
            $this->database,
            $revision,
            $stateJson,
            $stateSha
        );
        if (($created['created'] ?? false) !== true
            || ($created['status'] ?? '') !== 'pending'
            || (int)($created['state_revision'] ?? 0) !== $revision) {
            throw new RuntimeException(
                'Production atomic projection anchor could not be rebuilt after history reset.'
            );
        }

        $tick = $this->worker->runOnce();
        $fingerprint = $this->assertCompletedProjectionTick(
            $tick,
            $revision,
            $stateSha,
            'Production atomic projection anchor recovery'
        );

        $recovered = $this->captureIdentity($snapshot, $status, 'recovered baseline');
        if (($recovered['queue']['retained_tail_verified'] ?? false) !== true
            || ($recovered['queue']['history_reset'] ?? true) !== false) {
            throw new RuntimeException(
                'Production atomic projection anchor recovery did not establish a retained tail.'
            );
        }

        return $recovered + [
            'projection_history_reset_detected' => true,
            'projection_anchor_rebuilt' => true,
            'recovery_worker_tick_count' => 1,
            'recovery_worker_fingerprint' => $fingerprint,
        ];
    }

    private function captureFinalIdentity(): array
    {
        $status = $this->stateStorage->status();
        $snapshot = $this->stateStorage->readOnly(
            static fn(array $data): array => $data
        );
        if (!is_array($snapshot)) {
            throw new RuntimeException(
                'Production atomic final snapshot is unavailable.'
            );
        }
        return $this->captureIdentity($snapshot, $status, 'final');
    }

    private function captureIdentity(array $snapshot, array $status, string $stage): array
    {
        $revision = (int)($status['revision'] ?? 0);
        $stateSha = strtolower(trim((string)($status['state_sha256'] ?? '')));
        if ($revision < 1 || preg_match('/\A[a-f0-9]{64}\z/', $stateSha) !== 1) {
            throw new RuntimeException('Production atomic ' . $stage . ' state identity is invalid.');
        }
        if (!hash_equals($stateSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Production atomic ' . $stage . ' snapshot fingerprint mismatch.');
        }

        return [
            'state_revision' => $revision,
            'state_sha256' => $stateSha,
            'queue' => $this->queueStatus($revision),
        ];
    }

    private function assertCompletedProjectionTick(
        array $tick,
        int $revision,
        string $stateSha,
        string $label
    ): string {
        if (($tick['ok'] ?? false) !== true
            || ($tick['action'] ?? '') !== 'projection_completed'
            || ($tick['claimed'] ?? false) !== true
            || (int)($tick['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, strtolower(trim((string)($tick['state_sha256'] ?? ''))))
            || ($tick['parity_ok'] ?? false) !== true) {
            throw new RuntimeException($label . ' did not complete the exact state revision.');
        }

        $fingerprint = strtolower(trim((string)($tick['all_module_fingerprint'] ?? '')));
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException($label . ' parity fingerprint is invalid.');
        }

        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($tick['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $expectedModules = self::MODULES;
        sort($expectedModules, SORT_STRING);
        if ($modules !== $expectedModules) {
            throw new RuntimeException($label . ' parity proof is missing required modules.');
        }

        return $fingerprint;
    }

    /**
     * Polling may advance only the global cleanup timestamp and/or queue
     * heartbeat timestamps. Those fields are advisory and must not create a
     * DB-primary revision or nine-module projection when no gameplay, queue
     * membership, account, session or economy state changed.
     */
    private function discardCleanupTimestampOnlyChange(array &$after, array $before): bool
    {
        $beforeComparable = $before;
        $afterComparable = $after;
        $volatileChanged = false;

        $beforeCleanup = $this->cleanupTimestamp($beforeComparable);
        $afterCleanup = $this->cleanupTimestamp($afterComparable);
        if ($beforeCleanup !== $afterCleanup) {
            $volatileChanged = true;
        }
        $this->removeCleanupTimestamp($beforeComparable);
        $this->removeCleanupTimestamp($afterComparable);

        $beforeQueue = is_array($beforeComparable['queue'] ?? null)
            ? array_values($beforeComparable['queue'])
            : [];
        $afterQueue = is_array($afterComparable['queue'] ?? null)
            ? array_values($afterComparable['queue'])
            : [];

        foreach ($beforeQueue as $index => &$item) {
            if (!is_array($item)) continue;
            $afterItem = $afterQueue[$index] ?? null;
            if (is_array($afterItem)
                && ($item['updated_at'] ?? null) !== ($afterItem['updated_at'] ?? null)) {
                $volatileChanged = true;
            }
            unset($item['updated_at']);
        }
        unset($item);
        foreach ($afterQueue as &$item) {
            if (is_array($item)) unset($item['updated_at']);
        }
        unset($item);

        if ($beforeQueue !== [] || array_key_exists('queue', $beforeComparable)) {
            $beforeComparable['queue'] = $beforeQueue;
        }
        if ($afterQueue !== [] || array_key_exists('queue', $afterComparable)) {
            $afterComparable['queue'] = $afterQueue;
        }

        if (!$volatileChanged || !hash_equals(
            $this->canonicalJson($beforeComparable),
            $this->canonicalJson($afterComparable)
        )) {
            return false;
        }

        $this->restoreCleanupTimestamp($after, $before);
        $this->restoreQueueHeartbeats($after, $before);
        return true;
    }

    private function cleanupTimestamp(array $snapshot): mixed
    {
        return isset($snapshot['system'])
            && is_array($snapshot['system'])
            && array_key_exists('game_cleanup_at', $snapshot['system'])
                ? $snapshot['system']['game_cleanup_at']
                : null;
    }

    private function restoreCleanupTimestamp(array &$after, array $before): void
    {
        $hasBefore = isset($before['system'])
            && is_array($before['system'])
            && array_key_exists('game_cleanup_at', $before['system']);
        if ($hasBefore) {
            if (!isset($after['system']) || !is_array($after['system'])) {
                $after['system'] = [];
            }
            $after['system']['game_cleanup_at'] = $before['system']['game_cleanup_at'];
            return;
        }
        $this->removeCleanupTimestamp($after);
    }

    private function restoreQueueHeartbeats(array &$after, array $before): void
    {
        if (!isset($after['queue']) || !is_array($after['queue'])) return;
        $beforeQueue = is_array($before['queue'] ?? null)
            ? array_values($before['queue'])
            : [];
        $after['queue'] = array_values($after['queue']);

        foreach ($after['queue'] as $index => &$item) {
            if (!is_array($item)) continue;
            $beforeItem = $beforeQueue[$index] ?? null;
            if (is_array($beforeItem) && array_key_exists('updated_at', $beforeItem)) {
                $item['updated_at'] = $beforeItem['updated_at'];
            } else {
                unset($item['updated_at']);
            }
        }
        unset($item);
    }

    private function removeCleanupTimestamp(array &$snapshot): void
    {
        if (!isset($snapshot['system']) || !is_array($snapshot['system'])) {
            return;
        }
        unset($snapshot['system']['game_cleanup_at']);
        if ($snapshot['system'] === []) {
            unset($snapshot['system']);
        }
    }

    private function queueStatus(int $revision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT status, COUNT(*) AS event_count,
                    MIN(state_revision) AS min_revision,
                    MAX(state_revision) AS max_revision
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             GROUP BY status ORDER BY status'
        );

        $completedCount = 0;
        $completedMin = 0;
        $completedMax = 0;
        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $count = max(0, (int)($row['event_count'] ?? 0));
            if ($status !== 'completed' && $count > 0) {
                throw new RuntimeException(
                    'Production atomic projection queue contains a non-completed event.'
                );
            }
            if ($status === 'completed') {
                $completedCount = $count;
                $completedMin = max(0, (int)($row['min_revision'] ?? 0));
                $completedMax = max(0, (int)($row['max_revision'] ?? 0));
            }
        }

        $historyReset = $completedCount === 0;
        if (!$historyReset) {
            $expectedCount = $completedMax - $completedMin + 1;
            $maximumRetainedRows = RuntimePrimaryProjectionOutboxWriter::COMPLETED_RETENTION_ROWS + 1;
            if ($completedMin < 1
                || $completedMax !== $revision
                || $completedCount !== $expectedCount
                || $completedCount > $maximumRetainedRows) {
                throw new RuntimeException(
                    'Production atomic projection queue is not a contiguous retained completed tail.'
                );
            }
        }

        return [
            'completed_event_count' => $completedCount,
            'min_revision' => $completedMin,
            'max_revision' => $completedMax,
            'retained_tail_verified' => !$historyReset,
            'history_reset' => $historyReset,
            'retention_limit' => RuntimePrimaryProjectionOutboxWriter::COMPLETED_RETENTION_ROWS,
            'pending_event_count' => 0,
            'processing_event_count' => 0,
            'failed_event_count' => 0,
        ];
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
