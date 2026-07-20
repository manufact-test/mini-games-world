<?php
declare(strict_types=1);

interface RuntimePrimaryRehearsalBackendInterface
{
    public function installSchemas(): array;
    public function synchronizeCurrentSnapshot(): array;
    public function runWorkerOnce(): array;
    public function status(): array;
    public function eventStatus(int $stateRevision): array;
}

final class StagingPrimaryRehearsalTestBackend implements RuntimePrimaryRehearsalBackendInterface
{
    public int $installCalls = 0;
    public int $snapshotCalls = 0;
    public int $workerCalls = 0;
    public int $statusCalls = 0;
    public array $workerResults = [];
    public array $eventStatuses = [];
    public array $statusReport = ['ok' => true];
    public int $targetRevision = 2;
    public string $targetSha;

    public function __construct()
    {
        $this->targetSha = str_repeat('a', 64);
    }

    public function installSchemas(): array
    {
        $this->installCalls++;
        return ['ok' => true, 'state_schema' => ['ok' => true], 'outbox_schema' => ['ok' => true]];
    }

    public function synchronizeCurrentSnapshot(): array
    {
        $this->snapshotCalls++;
        return [
            'ok' => true,
            'action' => 'snapshot_revision_created',
            'state_revision' => $this->targetRevision,
            'state_sha256' => $this->targetSha,
        ];
    }

    public function runWorkerOnce(): array
    {
        $this->workerCalls++;
        $result = array_shift($this->workerResults);
        if (!is_array($result)) {
            return [
                'ok' => true,
                'action' => 'projection_completed',
                'claimed' => true,
                'state_revision' => $this->targetRevision,
                'state_sha256' => $this->targetSha,
                'parity_ok' => true,
            ];
        }
        return $result;
    }

    public function status(): array
    {
        $this->statusCalls++;
        return $this->statusReport;
    }

    public function eventStatus(int $stateRevision): array
    {
        $status = array_shift($this->eventStatuses);
        if (!is_array($status)) {
            $status = [
                'present' => true,
                'state_revision' => $stateRevision,
                'state_sha256' => $this->targetSha,
                'status' => $this->workerCalls > 0 ? 'completed' : 'pending',
            ];
        }
        return $status;
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/StagingPrimaryRehearsalOperation.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$assertThrows(
    static fn() => new StagingPrimaryRehearsalOperation(
        ['environment' => 'production'],
        new StagingPrimaryRehearsalTestBackend()
    ),
    'forbidden outside local/staging'
);
$assertThrows(
    static fn() => new StagingPrimaryRehearsalOperation(
        ['environment' => 'staging'],
        new StagingPrimaryRehearsalTestBackend(),
        0
    ),
    'between 1 and 100'
);

$statusBackend = new StagingPrimaryRehearsalTestBackend();
$status = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    $statusBackend
))->status();
$assertTrue(($status['ok'] ?? false) === true, 'Status report must succeed');
$assertTrue(($status['read_only'] ?? false) === true, 'Status report must be read-only');
$assertTrue($statusBackend->statusCalls === 1, 'Status must call backend status once');
$assertTrue($statusBackend->installCalls === 0 && $statusBackend->workerCalls === 0, 'Status must not mutate schemas or worker state');

$installBackend = new StagingPrimaryRehearsalTestBackend();
$install = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'local'],
    $installBackend
))->install();
$assertTrue(($install['action'] ?? '') === 'schemas_installed', 'Install must report schema installation');
$assertTrue($installBackend->installCalls === 1, 'Install must call backend once');

$seedBackend = new StagingPrimaryRehearsalTestBackend();
$seed = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    $seedBackend
))->seed();
$assertTrue(($seed['action'] ?? '') === 'snapshot_synchronized', 'Seed must report snapshot synchronization');
$assertTrue($seedBackend->snapshotCalls === 1, 'Seed must synchronize one snapshot');

$runBackend = new StagingPrimaryRehearsalTestBackend();
$runBackend->workerResults[] = [
    'ok' => false,
    'action' => 'projection_failed',
    'claimed' => true,
    'state_revision' => 1,
];
$run = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    $runBackend
))->runOnce();
$assertTrue(($run['ok'] ?? true) === false, 'Failed worker tick must fail run-once report');
$assertTrue(($run['action'] ?? '') === 'projection_failed', 'Run-once must preserve worker action');

$rehearsalBackend = new StagingPrimaryRehearsalTestBackend();
$rehearsalBackend->targetRevision = 3;
$rehearsalBackend->workerResults = [
    ['ok' => true, 'action' => 'projection_completed', 'claimed' => true, 'state_revision' => 1],
    ['ok' => true, 'action' => 'projection_completed', 'claimed' => true, 'state_revision' => 2],
    ['ok' => true, 'action' => 'projection_completed', 'claimed' => true, 'state_revision' => 3],
];
$rehearsalBackend->eventStatuses = [
    ['present' => true, 'state_revision' => 3, 'state_sha256' => $rehearsalBackend->targetSha, 'status' => 'pending'],
    ['present' => true, 'state_revision' => 3, 'state_sha256' => $rehearsalBackend->targetSha, 'status' => 'pending'],
    ['present' => true, 'state_revision' => 3, 'state_sha256' => $rehearsalBackend->targetSha, 'status' => 'pending'],
    ['present' => true, 'state_revision' => 3, 'state_sha256' => $rehearsalBackend->targetSha, 'status' => 'completed'],
];
$rehearsal = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    $rehearsalBackend,
    5
))->rehearse();
$assertTrue(($rehearsal['ok'] ?? false) === true, 'Rehearsal must complete after ordered worker ticks');
$assertTrue(($rehearsal['action'] ?? '') === 'rehearsal_completed', 'Completed rehearsal action must be explicit');
$assertTrue(($rehearsal['parity_completed'] ?? false) === true, 'Completed rehearsal must report parity');
$assertTrue(($rehearsal['worker_tick_count'] ?? 0) === 3, 'Rehearsal must process older revisions in order');
$assertTrue($rehearsalBackend->installCalls === 1 && $rehearsalBackend->snapshotCalls === 1, 'Rehearsal must install and synchronize once');
$assertTrue(($rehearsal['production_changed'] ?? true) === false, 'Rehearsal must never report production change');
$assertTrue(($rehearsal['application_entrypoints_changed'] ?? true) === false, 'Rehearsal must not change entrypoints');
$assertTrue(($rehearsal['cron_changed'] ?? true) === false, 'Rehearsal must not change Cron');

$busyBackend = new StagingPrimaryRehearsalTestBackend();
$busyBackend->eventStatuses = [
    ['present' => true, 'state_revision' => 2, 'state_sha256' => $busyBackend->targetSha, 'status' => 'pending'],
    ['present' => true, 'state_revision' => 2, 'state_sha256' => $busyBackend->targetSha, 'status' => 'processing'],
];
$busyBackend->workerResults[] = [
    'ok' => true,
    'action' => 'projection_busy',
    'claimed' => false,
    'state_revision' => 1,
];
$busy = (new StagingPrimaryRehearsalOperation(
    ['environment' => 'staging'],
    $busyBackend
))->rehearse();
$assertTrue(($busy['ok'] ?? true) === false, 'Busy rehearsal must remain incomplete');
$assertTrue(($busy['action'] ?? '') === 'rehearsal_incomplete', 'Busy rehearsal action must remain explicit');
$assertTrue(($busy['worker_tick_count'] ?? 0) === 1, 'Busy rehearsal must stop after one busy tick');

$missingBackend = new StagingPrimaryRehearsalTestBackend();
$missingBackend->eventStatuses[] = [
    'present' => false,
    'state_revision' => 2,
    'status' => 'missing',
];
$assertThrows(
    static fn() => (new StagingPrimaryRehearsalOperation(
        ['environment' => 'staging'],
        $missingBackend
    ))->rehearse(),
    'target event disappeared'
);

$driftBackend = new StagingPrimaryRehearsalTestBackend();
$driftBackend->eventStatuses[] = [
    'present' => true,
    'state_revision' => 2,
    'state_sha256' => str_repeat('b', 64),
    'status' => 'pending',
];
$assertThrows(
    static fn() => (new StagingPrimaryRehearsalOperation(
        ['environment' => 'staging'],
        $driftBackend
    ))->rehearse(),
    'fingerprint changed'
);

$noopBackend = new StagingPrimaryRehearsalTestBackend();
$noopBackend->eventStatuses[] = [
    'present' => true,
    'state_revision' => 2,
    'state_sha256' => $noopBackend->targetSha,
    'status' => 'pending',
];
$noopBackend->workerResults[] = [
    'ok' => true,
    'action' => 'projection_noop',
    'claimed' => false,
];
$assertThrows(
    static fn() => (new StagingPrimaryRehearsalOperation(
        ['environment' => 'staging'],
        $noopBackend
    ))->rehearse(),
    'empty queue before the target completed'
);

fwrite(STDOUT, "StagingPrimaryRehearsalOperationTest passed: {$assertions} assertions.\n");
