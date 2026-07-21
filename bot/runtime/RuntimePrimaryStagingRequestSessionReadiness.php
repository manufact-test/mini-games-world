<?php
declare(strict_types=1);

final class RuntimePrimaryStagingRequestSessionReadiness
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public function __construct(
        private StorageAdapterInterface $jsonStorage,
        private DatabaseConnectionInterface $database,
        private DatabasePrimaryStateStorageAdapter $dbPrimaryStorage,
        private RuntimePrimaryProjectionAuditorInterface $auditor,
        private RuntimePrimaryStagingRequestSessionConfig $session,
        private ?int $now = null
    ) {
        if ($this->jsonStorage->driver() !== 'json') {
            throw new RuntimeException('Request session readiness requires the JSON rollback driver.');
        }
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException('Request session readiness requires MySQL/MariaDB.');
        }
        if ($this->dbPrimaryStorage->driver() !== DatabasePrimaryStateStorageAdapter::DRIVER) {
            throw new RuntimeException('Request session readiness requires DB-primary storage.');
        }
    }

    public function assertReady(array $baseline): array
    {
        $baselineRevision = (int)($baseline['state_revision'] ?? 0);
        $baselineSha = $this->sha(
            (string)($baseline['state_sha256'] ?? ''),
            'baseline state fingerprint'
        );
        $jsonSha = $this->sha(
            (string)($baseline['json_sha256'] ?? ''),
            'baseline JSON fingerprint'
        );
        $inventoryFingerprint = $this->sha(
            (string)($baseline['inventory_fingerprint'] ?? ''),
            'baseline JSON inventory fingerprint'
        );
        if ($baselineRevision < 1) {
            throw new RuntimeException('Request session evidence baseline revision must be positive.');
        }

        $jsonBefore = RuntimePrimaryJsonEvidence::capture($this->jsonStorage);
        $this->assertJsonEvidence($jsonBefore, $jsonSha, $inventoryFingerprint);

        $stateBefore = $this->dbPrimaryStorage->status();
        $currentRevision = (int)($stateBefore['revision'] ?? 0);
        $currentSha = $this->sha(
            (string)($stateBefore['state_sha256'] ?? ''),
            'current DB-primary state fingerprint'
        );
        $this->session->assertEnabledForApi(
            $baselineRevision,
            $currentRevision,
            $this->timestamp()
        );

        $baselineEvent = $this->eventForRevision($baselineRevision);
        $this->assertCompletedEvent($baselineEvent, $baselineRevision, $baselineSha);
        $currentEvent = $this->eventForRevision($currentRevision);
        $this->assertCompletedEvent($currentEvent, $currentRevision, $currentSha);
        $queueBefore = $this->queueStatus($currentRevision);

        $snapshot = $this->dbPrimaryStorage->readOnly(static fn(array $data): array => $data);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Request session readiness could not read the current DB-primary snapshot.');
        }
        if (!hash_equals($currentSha, hash('sha256', $this->canonicalJson($snapshot)))) {
            throw new RuntimeException('Request session readiness snapshot fingerprint mismatch.');
        }
        $audit = $this->auditor->auditOnly($snapshot, $currentRevision, $currentSha);
        $this->assertAudit($audit, $currentRevision, $currentSha);

        $jsonAfter = RuntimePrimaryJsonEvidence::capture($this->jsonStorage);
        $this->assertJsonEvidence($jsonAfter, $jsonSha, $inventoryFingerprint);
        if (!hash_equals(
            (string)$jsonBefore['sha256'],
            (string)$jsonAfter['sha256']
        ) || !hash_equals(
            (string)$jsonBefore['inventory_fingerprint'],
            (string)$jsonAfter['inventory_fingerprint']
        )) {
            throw new RuntimeException('JSON rollback source changed during request session readiness audit.');
        }

        $stateAfter = $this->dbPrimaryStorage->status();
        if ((int)($stateAfter['revision'] ?? 0) !== $currentRevision
            || !hash_equals(
                $currentSha,
                strtolower(trim((string)($stateAfter['state_sha256'] ?? '')))
            )) {
            throw new RuntimeException('DB-primary state changed during request session readiness audit.');
        }
        $postCurrentEvent = $this->eventForRevision($currentRevision);
        $this->assertCompletedEvent($postCurrentEvent, $currentRevision, $currentSha);
        $queueAfter = $this->queueStatus($currentRevision);
        if ($queueAfter !== $queueBefore) {
            throw new RuntimeException('Projection queue changed during request session readiness audit.');
        }

        return [
            'ok' => true,
            'report_type' => 'mvp-14.8.6l-staging-request-session-readiness',
            'action' => 'staging_api_request_session_ready',
            'api_only' => true,
            'baseline_state_revision' => $baselineRevision,
            'baseline_state_sha256' => $baselineSha,
            'current_state_revision' => $currentRevision,
            'current_state_sha256' => $currentSha,
            'revision_delta' => $currentRevision - $baselineRevision,
            'remaining_session_revisions' => $this->session->remainingRevisions($currentRevision),
            'session_expires_at_utc' => $this->session->expiresAtUtc(),
            'queue' => $queueAfter,
            'projected_modules' => self::MODULES,
            'all_module_fingerprint' => strtolower(trim((string)(
                $audit['all_module_fingerprint'] ?? ''
            ))),
            'json_rollback_sha256' => $jsonSha,
            'json_inventory_fingerprint' => $inventoryFingerprint,
            'json_rollback_source_unchanged' => true,
            'read_only_audit' => true,
            'drift_check_passed' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM, $this->timestamp()),
        ];
    }

    private function assertJsonEvidence(
        array $evidence,
        string $jsonSha,
        string $inventoryFingerprint
    ): void {
        if (($evidence['production_changed'] ?? null) !== false
            || ($evidence['sensitive_identifiers_exposed'] ?? null) !== false
            || !hash_equals($jsonSha, strtolower(trim((string)($evidence['sha256'] ?? ''))))
            || !hash_equals(
                $inventoryFingerprint,
                strtolower(trim((string)($evidence['inventory_fingerprint'] ?? '')))
            )) {
            throw new RuntimeException('Current JSON rollback source does not match the request session baseline.');
        }
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
            throw new RuntimeException('Request session projection event is missing or ambiguous.');
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

    private function assertCompletedEvent(array $event, int $revision, string $sha): void
    {
        if ((int)($event['state_revision'] ?? 0) !== $revision
            || !hash_equals($sha, (string)($event['state_sha256'] ?? ''))
            || ($event['projection_version'] ?? '') !== RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION
            || ($event['status'] ?? '') !== 'completed'
            || (int)($event['attempt_count'] ?? 0) < 1
            || trim((string)($event['lease_token'] ?? '')) !== ''
            || trim((string)($event['lease_expires_at_utc'] ?? '')) !== ''
            || trim((string)($event['last_error'] ?? '')) !== '') {
            throw new RuntimeException('Request session projection event is not cleanly completed.');
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
                throw new RuntimeException('Request session queue contains a non-completed event.');
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
            throw new RuntimeException('Request session queue is not a contiguous completed revision chain.');
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

    private function assertAudit(array $audit, int $revision, string $sha): void
    {
        if (($audit['ok'] ?? false) !== true
            || ($audit['parity_ok'] ?? false) !== true
            || ($audit['read_only'] ?? false) !== true
            || (int)($audit['state_revision'] ?? 0) !== $revision
            || !hash_equals($sha, strtolower(trim((string)($audit['state_sha256'] ?? ''))))) {
            throw new RuntimeException('Request session all-module audit did not pass exact parity.');
        }
        $modules = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($audit['projected_modules'] ?? [])
        )));
        sort($modules, SORT_STRING);
        $required = self::MODULES;
        sort($required, SORT_STRING);
        if ($modules !== $required) {
            throw new RuntimeException('Request session all-module audit is missing required modules.');
        }
        $fingerprint = strtolower(trim((string)($audit['all_module_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Request session all-module audit fingerprint is invalid.');
        }
    }

    private function sha(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException('Request session ' . $label . ' is invalid.');
        }
        return $value;
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
