<?php
declare(strict_types=1);

final class StagingPrimaryRehearsalOperation
{
    public function __construct(
        private array $config,
        private RuntimePrimaryRehearsalBackendInterface $backend,
        private int $maxEvents = 20
    ) {
        $environment = strtolower(trim((string)($this->config['environment'] ?? '')));
        if (!in_array($environment, ['local', 'staging'], true)) {
            throw new RuntimeException('DB-primary rehearsal operation is forbidden outside local/staging.');
        }
        if ($this->maxEvents < 1 || $this->maxEvents > 100) {
            throw new InvalidArgumentException('DB-primary rehearsal max events must be between 1 and 100.');
        }
    }

    public function status(): array
    {
        $status = $this->backend->status();
        return $this->report('status', [
            'status' => $status,
            'read_only' => true,
        ], !empty($status['ok']));
    }

    public function install(): array
    {
        $schemas = $this->backend->installSchemas();
        return $this->report('schemas_installed', [
            'schemas' => $schemas,
            'read_only' => false,
        ], !empty($schemas['ok']));
    }

    public function seed(): array
    {
        $snapshot = $this->backend->synchronizeCurrentSnapshot();
        return $this->report('snapshot_synchronized', [
            'snapshot' => $snapshot,
            'read_only' => false,
        ], !empty($snapshot['ok']));
    }

    public function runOnce(): array
    {
        $worker = $this->backend->runWorkerOnce();
        return $this->report((string)($worker['action'] ?? 'worker_tick'), [
            'worker' => $worker,
            'read_only' => false,
        ], !empty($worker['ok']));
    }

    public function rehearse(): array
    {
        $schemas = $this->backend->installSchemas();
        if (empty($schemas['ok'])) {
            throw new RuntimeException('DB-primary rehearsal schema preparation failed.');
        }
        $snapshot = $this->backend->synchronizeCurrentSnapshot();
        if (empty($snapshot['ok'])) {
            throw new RuntimeException('DB-primary rehearsal snapshot synchronization failed.');
        }
        $targetRevision = (int)($snapshot['state_revision'] ?? 0);
        $targetSha = strtolower(trim((string)($snapshot['state_sha256'] ?? '')));
        if ($targetRevision < 1 || preg_match('/^[a-f0-9]{64}$/', $targetSha) !== 1) {
            throw new RuntimeException('DB-primary rehearsal target revision or fingerprint is invalid.');
        }

        $ticks = [];
        $completed = false;
        for ($tick = 1; $tick <= $this->maxEvents; $tick++) {
            $event = $this->backend->eventStatus($targetRevision);
            if (($event['present'] ?? false) !== true) {
                throw new RuntimeException('DB-primary rehearsal target event disappeared.');
            }
            if (!hash_equals($targetSha, strtolower(trim((string)($event['state_sha256'] ?? ''))))) {
                throw new RuntimeException('DB-primary rehearsal target event fingerprint changed.');
            }
            if ((string)($event['status'] ?? '') === 'completed') {
                $completed = true;
                break;
            }

            $worker = $this->backend->runWorkerOnce();
            $ticks[] = $worker;
            if (empty($worker['ok'])) break;
            $action = (string)($worker['action'] ?? '');
            if (in_array($action, ['projection_busy', 'projection_delayed'], true)) break;
            if ($action === 'projection_noop') {
                throw new RuntimeException('Projection worker reported an empty queue before the target completed.');
            }
        }

        $targetEvent = $this->backend->eventStatus($targetRevision);
        if (($targetEvent['present'] ?? false) !== true) {
            throw new RuntimeException('DB-primary rehearsal target event disappeared after worker execution.');
        }
        if (!hash_equals($targetSha, strtolower(trim((string)($targetEvent['state_sha256'] ?? ''))))) {
            throw new RuntimeException('DB-primary rehearsal target event fingerprint changed after worker execution.');
        }
        if ((string)($targetEvent['status'] ?? '') === 'completed') $completed = true;
        $status = $this->backend->status();

        return $this->report(
            $completed ? 'rehearsal_completed' : 'rehearsal_incomplete',
            [
                'schemas' => $schemas,
                'snapshot' => $snapshot,
                'target_event' => $targetEvent,
                'worker_ticks' => $ticks,
                'worker_tick_count' => count($ticks),
                'max_events' => $this->maxEvents,
                'parity_completed' => $completed,
                'status' => $status,
                'read_only' => false,
                'next_step' => $completed
                    ? 'Review the non-sensitive parity report. Do not connect application entrypoints yet.'
                    : 'Resolve the worker blocker and rerun the staging rehearsal. Do not connect application entrypoints.',
            ],
            $completed && !empty($status['ok'])
        );
    }

    private function report(string $action, array $details, bool $ok = true): array
    {
        return [
            'ok' => $ok,
            'report_type' => 'mvp-14.8.6e-staging-db-primary-rehearsal',
            'action' => $action,
            'environment' => strtolower(trim((string)($this->config['environment'] ?? ''))),
            'application_entrypoints_changed' => false,
            'cron_changed' => false,
            'production_changed' => false,
            'sensitive_identifiers_exposed' => false,
            'generated_at_utc' => gmdate(DATE_ATOM),
        ] + $details;
    }
}
