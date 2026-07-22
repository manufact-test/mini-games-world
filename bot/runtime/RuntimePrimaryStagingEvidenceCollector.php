<?php
declare(strict_types=1);

final class RuntimePrimaryStagingEvidenceCollector
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    private const PROJECTION_CONTRACT_VERSION = 'v1-normalized-all-modules';

    public function __construct(
        private string $projectRoot,
        private RuntimePrimaryStagingEvidenceSourceInterface $source
    ) {
        $this->projectRoot = rtrim(str_replace('\\', '/', trim($this->projectRoot)), '/');
        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            throw new InvalidArgumentException('Staging evidence collector project root is unavailable.');
        }
    }

    public function collect(): array
    {
        $before = $this->source->captureJsonEvidence();
        $first = $this->source->runRehearsal();
        $afterFirst = $this->source->captureJsonEvidence();
        $second = $this->source->runRehearsal();
        $afterSecond = $this->source->captureJsonEvidence();

        $this->assertJsonEvidenceStable($before, $afterFirst, $afterSecond);
        $firstProjection = $this->projectionEvidence($first);
        $secondProjection = $this->projectionEvidence($second);
        $database = $this->source->databaseEvidence();
        $databaseIdentity = strtolower(trim((string)($database['identity_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $databaseIdentity) !== 1) {
            throw new RuntimeException('Staging evidence source database identity fingerprint is invalid.');
        }
        $schemas = $this->schemaEvidence($first);

        $manifest = [
            'manifest_version' => RuntimePrimaryStagingEvidenceV2Verifier::MANIFEST_VERSION,
            'environment' => 'staging',
            'repository_commit' => $this->source->repositoryCommit(),
            'generated_at_utc' => gmdate(DATE_ATOM),
            'php' => $this->source->phpEvidence(),
            'database' => [
                'driver' => (string)($database['driver'] ?? ''),
                'server_version' => (string)($database['server_version'] ?? ''),
                'identity_fingerprint' => $databaseIdentity,
                'state_engine' => (string)($schemas['state_engine'] ?? ''),
                'outbox_engine' => (string)($schemas['outbox_engine'] ?? ''),
            ],
            'schemas' => [
                'state' => [
                    'table' => (string)($schemas['state_table'] ?? ''),
                    'schema_fingerprint' => (string)($schemas['state_fingerprint'] ?? ''),
                ],
                'outbox' => [
                    'table' => (string)($schemas['outbox_table'] ?? ''),
                    'schema_fingerprint' => (string)($schemas['outbox_fingerprint'] ?? ''),
                ],
            ],
            'source_snapshot' => [
                'before_sha256' => (string)($before['sha256'] ?? ''),
                'after_first_sha256' => (string)($afterFirst['sha256'] ?? ''),
                'after_second_sha256' => (string)($afterSecond['sha256'] ?? ''),
                'inventory_fingerprint' => (string)($before['inventory_fingerprint'] ?? ''),
            ],
            'first_rehearsal' => $this->rehearsalEvidence($first, $firstProjection),
            'repeated_rehearsal' => $this->rehearsalEvidence($second, $secondProjection),
            'concurrency' => $this->source->concurrencyEvidence(),
            'entrypoint_evidence' => $this->source->entrypointEvidence(),
        ];

        $verification = (new RuntimePrimaryStagingEvidenceV2Gate($this->projectRoot))->verify($manifest);
        if (($verification['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Collected staging evidence v2 did not pass verification: '
                . implode('; ', array_map('strval', (array)($verification['blockers'] ?? [])))
            );
        }

        return [
            'ok' => true,
            'manifest' => $manifest,
            'verification' => $verification,
            'manifest_fingerprint' => (string)($verification['evidence_fingerprint'] ?? ''),
            'repository_commit' => (string)($manifest['repository_commit'] ?? ''),
            'database_identity_fingerprint' => $databaseIdentity,
            'state_revision' => (int)($manifest['first_rehearsal']['target_revision'] ?? 0),
            'state_sha256' => (string)($manifest['first_rehearsal']['target_sha256'] ?? ''),
            'projected_modules' => array_values((array)$firstProjection['projected_modules']),
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }

    private function assertJsonEvidenceStable(array $before, array $afterFirst, array $afterSecond): void
    {
        foreach ([$before, $afterFirst, $afterSecond] as $index => $evidence) {
            if (($evidence['production_changed'] ?? null) !== false
                || ($evidence['sensitive_identifiers_exposed'] ?? null) !== false) {
                throw new RuntimeException('JSON evidence capture violated its safety contract at step ' . $index . '.');
            }
            foreach (['sha256', 'inventory_fingerprint'] as $field) {
                if (preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($evidence[$field] ?? '')))) !== 1) {
                    throw new RuntimeException('JSON evidence capture has an invalid ' . $field . '.');
                }
            }
        }

        $sha = strtolower(trim((string)$before['sha256']));
        $inventory = strtolower(trim((string)$before['inventory_fingerprint']));
        foreach ([$afterFirst, $afterSecond] as $evidence) {
            if (!hash_equals($sha, strtolower(trim((string)$evidence['sha256'])))
                || !hash_equals($inventory, strtolower(trim((string)$evidence['inventory_fingerprint'])))) {
                throw new RuntimeException('JSON rollback source or inventory changed during evidence collection.');
            }
        }
    }

    private function schemaEvidence(array $first): array
    {
        $schemas = is_array($first['schemas'] ?? null) ? $first['schemas'] : [];
        $state = is_array($schemas['state_schema'] ?? null) ? $schemas['state_schema'] : [];
        $outbox = is_array($schemas['outbox_schema'] ?? null) ? $schemas['outbox_schema'] : [];
        return [
            'state_table' => (string)($state['table'] ?? ''),
            'state_fingerprint' => strtolower(trim((string)($state['schema_fingerprint'] ?? ''))),
            'state_engine' => strtolower(trim((string)($state['engine'] ?? ''))),
            'outbox_table' => (string)($outbox['table'] ?? ''),
            'outbox_fingerprint' => strtolower(trim((string)($outbox['schema_fingerprint'] ?? ''))),
            'outbox_engine' => strtolower(trim((string)($outbox['engine'] ?? ''))),
        ];
    }

    private function projectionEvidence(array $report): array
    {
        $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : [];
        $event = is_array($report['target_event'] ?? null) ? $report['target_event'] : [];
        $targetRevision = (int)($snapshot['state_revision'] ?? 0);
        $targetSha = strtolower(trim((string)($snapshot['state_sha256'] ?? '')));
        $eventRevision = (int)($event['state_revision'] ?? 0);
        $eventSha = strtolower(trim((string)($event['state_sha256'] ?? '')));
        $projectionVersion = (string)($event['projection_version'] ?? '');
        $eventAttempted = (int)($event['attempt_count'] ?? 0) >= 1;
        $eventLeaseFree = (string)($event['lease_expires_at_utc'] ?? '') === '';
        $eventErrorFree = (string)($event['last_error'] ?? '') === '';

        if ($targetRevision < 1
            || preg_match('/^[a-f0-9]{64}$/', $targetSha) !== 1
            || $eventRevision !== $targetRevision
            || !hash_equals($targetSha, $eventSha)
            || (string)($event['status'] ?? '') !== 'completed'
            || $projectionVersion !== self::PROJECTION_CONTRACT_VERSION
            || !$eventAttempted
            || !$eventLeaseFree
            || !$eventErrorFree) {
            throw new RuntimeException(
                'Staging rehearsal target event did not prove the current completed all-module projection contract.'
            );
        }

        $workerTicks = (array)($report['worker_ticks'] ?? []);
        $workerTickCount = (int)($report['worker_tick_count'] ?? -1);
        if ($workerTickCount !== count($workerTicks)) {
            throw new RuntimeException('Staging rehearsal worker tick count does not match its tick reports.');
        }

        $targetTickProved = false;
        foreach ($workerTicks as $tick) {
            if (!is_array($tick)
                || ($tick['action'] ?? '') !== 'projection_completed'
                || ($tick['parity_ok'] ?? false) !== true
                || (int)($tick['state_revision'] ?? 0) !== $targetRevision
                || !hash_equals($targetSha, strtolower(trim((string)($tick['state_sha256'] ?? ''))))) {
                continue;
            }
            $modules = array_values(array_unique(array_map(
                static fn(mixed $module): string => strtolower(trim((string)$module)),
                (array)($tick['projected_modules'] ?? [])
            )));
            sort($modules, SORT_STRING);
            $required = self::MODULES;
            sort($required, SORT_STRING);
            if ($modules !== $required) {
                throw new RuntimeException('First staging rehearsal did not prove all nine projected modules.');
            }
            $targetTickProved = true;
        }

        $snapshotAction = (string)($snapshot['action'] ?? '');
        if ($targetTickProved && $workerTickCount >= 1) {
            $proof = 'worker_completed_current_run';
        } elseif ($workerTickCount === 0 && $snapshotAction === 'snapshot_unchanged') {
            $proof = 'completed_current_contract_reused';
        } else {
            throw new RuntimeException('Staging rehearsal did not provide an idempotent all-module projection proof.');
        }

        return [
            'projected_modules' => self::MODULES,
            'projection_proof' => $proof,
            'projection_contract_version' => $projectionVersion,
            'target_event_attempted' => $eventAttempted,
            'target_event_lease_free' => $eventLeaseFree,
            'target_event_error_free' => $eventErrorFree,
        ];
    }

    private function rehearsalEvidence(array $report, array $projection): array
    {
        foreach ([
            'application_entrypoints_changed',
            'cron_changed',
            'production_changed',
            'sensitive_identifiers_exposed',
        ] as $field) {
            if (!array_key_exists($field, $report) || $report[$field] !== false) {
                throw new RuntimeException('Staging rehearsal violated or omitted its safety flag: ' . $field . '.');
            }
        }

        $snapshot = is_array($report['snapshot'] ?? null) ? $report['snapshot'] : [];
        $event = is_array($report['target_event'] ?? null) ? $report['target_event'] : [];
        return [
            'ok' => ($report['ok'] ?? false) === true,
            'action' => (string)($report['action'] ?? ''),
            'snapshot_action' => (string)($snapshot['action'] ?? ''),
            'target_revision' => (int)($snapshot['state_revision'] ?? 0),
            'target_sha256' => strtolower(trim((string)($snapshot['state_sha256'] ?? ''))),
            'target_event_status' => strtolower(trim((string)($event['status'] ?? ''))),
            'target_event_completed' => ($report['target_event_completed'] ?? false) === true,
            'status_healthy' => ($report['status_healthy'] ?? false) === true,
            'parity_completed' => ($report['parity_completed'] ?? false) === true,
            'worker_tick_count' => max(0, (int)($report['worker_tick_count'] ?? 0)),
            'projected_modules' => array_values((array)($projection['projected_modules'] ?? [])),
            'projection_proof' => (string)($projection['projection_proof'] ?? ''),
            'projection_contract_version' => (string)($projection['projection_contract_version'] ?? ''),
            'target_event_attempted' => ($projection['target_event_attempted'] ?? false) === true,
            'target_event_lease_free' => ($projection['target_event_lease_free'] ?? false) === true,
            'target_event_error_free' => ($projection['target_event_error_free'] ?? false) === true,
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
        ];
    }
}
