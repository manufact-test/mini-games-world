<?php
declare(strict_types=1);

final class ProductionPrimaryAtomicStorageAdapter implements StorageAdapterInterface
{
    public const DRIVER = 'database';
    public const CONTRACT_VERSION = 'v2-production-atomic-worker-parity-reuse';

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
                    'worker_tick_count' => 0,
                    'projected_modules' => self::MODULES,
                    'baseline_locked' => true,
                    'baseline_projection_chain_verified' => true,
                    'baseline_full_module_audit_executed' => false,
                    'final_full_module_audit_executed' => false,
                    'worker_parity_proof_reused' => false,
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
            if (($tick['ok'] ?? false) !== true
                || ($tick['action'] ?? '') !== 'projection_completed'
                || ($tick['claimed'] ?? false) !== true
                || (int)($tick['state_revision'] ?? 0) !== $afterRevision
                || !hash_equals($afterSha, strtolower(trim((string)($tick['state_sha256'] ?? ''))))
                || ($tick['parity_ok'] ?? false) !== true) {
                throw new RuntimeException(
                    'Production atomic projection did not complete the exact state revision.'
                );
            }

            $workerFingerprint = strtolower(trim((string)(
                $tick['all_module_fingerprint'] ?? ''
            )));
            if (preg_match('/\A[a-f0-9]{64}\z/', $workerFingerprint) !== 1) {
                throw new RuntimeException(
                    'Production atomic worker parity fingerprint is invalid.'
                );
            }

            $workerModules = array_values(array_unique(array_map(
                static fn(mixed $value): string => strtolower(trim((string)$value)),
                (array)($tick['projected_modules'] ?? [])
            )));
            sort($workerModules, SORT_STRING);
            $expectedModules = self::MODULES;
            sort($expectedModules, SORT_STRING);
            if ($workerModules !== $expectedModules) {
                throw new RuntimeException(
                    'Production atomic worker parity proof is missing required modules.'
                );
            }

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
                'worker_tick_count' => 1,
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
                'baseline_projection_chain_verified' => true,
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
        return $this->captureIdentity($snapshot, $status, 'baseline');
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

    private function discardCleanupTimestampOnlyChange(array &$after, array $before): bool
    {
        $beforeHasTimestamp = isset($before['system'])
            && is_array($before['system'])
            && array_key_exists('game_cleanup_at', $before['system']);
        $afterHasTimestamp = isset($after['system'])
            && is_array($after['system'])
            && array_key_exists('game_cleanup_at', $after['system']);
        $beforeTimestamp = $beforeHasTimestamp
            ? $before['system']['game_cleanup_at']
            : null;
        $afterTimestamp = $afterHasTimestamp
            ? $after['system']['game_cleanup_at']
            : null;

        if ($beforeHasTimestamp === $afterHasTimestamp
            && $beforeTimestamp === $afterTimestamp) {
            return false;
        }

        $beforeComparable = $before;
        $afterComparable = $after;
        $this->removeCleanupTimestamp($beforeComparable);
        $this->removeCleanupTimestamp($afterComparable);

        if (!hash_equals(
            $this->canonicalJson($beforeComparable),
            $this->canonicalJson($afterComparable)
        )) {
            return false;
        }

        if ($beforeHasTimestamp) {
            if (!isset($after['system']) || !is_array($after['system'])) {
                $after['system'] = [];
            }
            $after['system']['game_cleanup_at'] = $beforeTimestamp;
        } else {
            $this->removeCleanupTimestamp($after);
        }

        return true;
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

        if ($completedCount !== $revision
            || $completedMin !== 1
            || $completedMax !== $revision) {
            throw new RuntimeException(
                'Production atomic projection queue is not a contiguous completed revision chain.'
            );
        }

        return [
            'completed_event_count' => $completedCount,
            'min_revision' => $completedMin,
            'max_revision' => $completedMax,
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
