<?php
declare(strict_types=1);

final class RuntimePrimaryStagingRequestFinalizer
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private DatabaseConnectionInterface $database,
        private RuntimePrimaryProjectionWorkerInterface $worker,
        private RuntimePrimaryProjectionAuditorInterface $auditor,
        private RuntimePrimaryStagingRequestSessionConfig $session,
        private ?int $now = null
    ) {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Staging request finalizer requires MySQL/MariaDB.');
        }
    }

    public function finalize(
        DatabasePrimaryStateStorageAdapter $storage,
        array $resolutionReport
    ): array {
        if ($storage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Staging request finalizer requires DB-primary storage.');
        }
        if (($resolutionReport['resolved'] ?? false) !== true
            || ($resolutionReport['projection_outbox_enabled'] ?? false) !== true
            || ($resolutionReport['read_only_readiness_audit'] ?? false) !== true
            || ($resolutionReport['drift_check_passed'] ?? false) !== true) {
            throw new RuntimeException('Staging request finalizer is missing guarded resolution evidence.');
        }

        $baselineRevision = (int)(
            $resolutionReport['baseline_state_revision']
            ?? $this->session->baselineRevision()
        );
        $resolvedRevision = (int)($resolutionReport['state_revision'] ?? 0);
        if ($resolvedRevision < $baselineRevision) {
            throw new RuntimeException('Resolved staging revision is behind the request session baseline.');
        }

        $before = $storage->status();
        $currentRevision = (int)($before['revision'] ?? 0);
        $currentSha = strtolower(trim((string)($before['state_sha256'] ?? '')));
        if ($currentRevision < $resolvedRevision) {
            throw new RuntimeException('Current DB-primary revision moved behind the resolved request state.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $currentSha) !== 1) {
            throw new RuntimeException('Current DB-primary request state fingerprint is invalid.');
        }
        $this->session->assertEnabledForApi(
            $baselineRevision,
            $currentRevision,
            $this->timestamp()
        );

        $ticks = [];
        $event = $this->eventForRevision($currentRevision);
        while (($event['status'] ?? '') !== 'completed') {
            if (count($ticks) >= $this->session->maxWorkerTicks()) {
                throw new RuntimeException('Staging request finalizer exhausted its bounded worker ticks.');
            }
            $tick = $this->worker->runOnce();
            $ticks[] = $this->normalizeTick($tick);
            if (($tick['ok'] ?? false) !== true
                || ($tick['action'] ?? '') !== 'projection_completed'
                || ($tick['claimed'] ?? false) !== true) {
                throw new RuntimeException(
                    'Staging request projection did not complete: '
                    . $this->safeAction((string)($tick['action'] ?? 'projection_unknown'))
                    . '.'
                );
            }
            $tickRevision = (int)($tick['state_revision'] ?? 0);
            if ($tickRevision < 1 || $tickRevision > $currentRevision) {
                throw new RuntimeException('Staging request worker completed an unexpected revision.');
            }
            $event = $this->eventForRevision($currentRevision);
        }

        $this->assertCompletedEvent($event, $currentRevision, $currentSha);
        $queue = $this->queueStatus($currentRevision);
        $snapshot = $storage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Staging request finalizer could not read the current DB-primary snapshot.');
        }
        if (!hash_equals($currentSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Staging request finalizer snapshot fingerprint mismatch.');
        }

        $audit = $this->auditor->auditOnly($snapshot, $currentRevision, $currentSha);
        $this->assertAudit($audit, $currentRevision, $currentSha);

        $after = $storage->status();
        if ((int)($after['revision'] ?? 0) !== $currentRevision
            || !hash_equals(
                $currentSha,
                strtolower(trim((string)($after['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('DB-primary state changed during request finalization.');
        }
        $postEvent = $this->eventForRevision($currentRevision);
        $this->assertCompletedEvent($postEvent, $currentRevision, $currentSha);
        $postQueue = $this->queueStatus($currentRevision);
        if ($postQueue !== $queue) {
            throw new RuntimeException('Projection queue changed during request finalization audit.');
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.8.6l-staging-request-finalization',
            'action' => $ticks === []
                ? 'request_state_already_projected'
                : 'request_state_projected_and_audited',
            'api_only' => true,
            'baseline_state_revision' => $baselineRevision,
            'resolved_state_revision' => $resolvedRevision,
            'final_state_revision' => $currentRevision,
            'final_state_sha256' => $currentSha,
            'worker_tick_count' => count($ticks),
            'worker_ticks' => $ticks,
            'projection_event_status' => 'completed',
            'projection_attempt_count' => (int)($postEvent['attempt_count'] ?? 0),
            'queue' => $postQueue,
            'projected_modules' => self::MODULES,
            'all_module_fingerprint' => strtolower(trim((string)(
                $audit['all_module_fingerprint'] ?? ''
            ))),
            'read_only_audit' => true,
            'state_unchanged_during_audit' => true,
            'remaining_session_revisions' => $this->session->remainingRevisions($currentRevision),
            'session_expires_at_utc' => $this->session->expiresAtUtc(),
            'json_rollback_source_changed' => false,
            'legacy_json_bridges_suppressed' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM, $this->timestamp()),
        ];
    }

    private function eventForRevision(int $revision): array
    {
        $rows = $this->database->fetchAll(
            'SELECT state_revision, state_sha256, projection_version, status,
                    attempt_count, lease_token, lease_expires_at_utc, last_error
             FROM ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE . '
             WHERE state_revision = :state_revision',
            ['state_revision' => $revision]
        );
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException('Staging request projection event is missing or ambiguous.');
        }
        return [
            'state_revision' => (int)($rows[0]['state_revision'] ?? 0),
            'state_sha256' => strtolower(trim((string)($rows[0]['state_sha256'] ?? ''))),
            'projection_version' => (string)($rows[0]['projection_version'] ?? ''),
            'status' => strtolower(trim((string)($rows[0]['status'] ?? ''))),
            'attempt_count' => max(0, (int)($rows[0]['attempt_count'] ?? 0)),
            'lease_token' => (string)($rows[0]['lease_token'] ?? ''),
            'lease_expires_at_utc' => (string)($rows[0]['lease_expires_at_utc'] ?? ''),
            'last_error' => (string)($rows[0]['last_error'] ?? ''),
        ];
    }

    private function assertCompletedEvent(
        array $event,
        int $revision,
        string $stateSha
    ): void {
        if ((int)($event['state_revision'] ?? 0) !== $revision
            || !hash_equals($stateSha, (string)($event['state_sha256'] ?? ''))
            || ($event['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
            || ($event['status'] ?? '') !== 'completed'
            || (int)($event['attempt_count'] ?? 0) < 1
            || trim((string)($event['lease_token'] ?? '')) !== ''
            || trim((string)($event['lease_expires_at_utc'] ?? '')) !== ''
            || trim((string)($event['last_error'] ?? '')) !== '') {
            throw new RuntimeException('Staging request projection event is not cleanly completed.');
        }
    }

    private function queueStatus(int $currentRevision): array
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
                    'Staging request projection queue contains a non-completed event: '
                    . $this->safeAction($status)
                    . '.'
                );
            }
            if ($status === 'completed') {
                $completedCount = $count;
                $completedMin = max(0, (int)($row['min_revision'] ?? 0));
                $completedMax = max(0, (int)($row['max_revision'] ?? 0));
            }
        }
        if ($completedCount !== $currentRevision
            || $completedMin !== 1
            || $completedMax !== $currentRevision) {
            throw new RuntimeException('Staging request projection queue is not a contiguous completed revision chain.');
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

    private function assertAudit(array $audit, int $revision, string $stateSha): void
    {
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals(
                $stateSha,
                strtolower(trim((string)($audit['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('Staging request all-module audit did not pass exact parity.');
        }
        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($audit['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $required = self::MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Staging request all-module audit is missing required modules.');
        }
        $fingerprint = strtolower(trim((string)($audit['all_module_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Staging request all-module audit fingerprint is invalid.');
        }
    }

    private function normalizeTick(array $tick): array
    {
        return [
            'ok' => ($tick['ok'] ?? false) === true,
            'action' => $this->safeAction((string)($tick['action'] ?? '')),
            'claimed' => ($tick['claimed'] ?? false) === true,
            'state_revision' => max(0, (int)($tick['state_revision'] ?? 0)),
            'attempt_count' => max(0, (int)($tick['attempt_count'] ?? 0)),
            'parity_ok' => ($tick['parity_ok'] ?? false) === true,
        ];
    }

    private function safeAction(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_\-]{1,64}$/', $value) === 1
            ? $value
            : 'invalid_action';
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
