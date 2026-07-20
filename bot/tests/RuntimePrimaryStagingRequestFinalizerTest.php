<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function fetchAll(string $sql, array $params = []): array;
}
interface RuntimePrimaryProjectionWorkerInterface
{
    public function runOnce(): array;
}
interface RuntimePrimaryProjectionAuditorInterface
{
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array;
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}
final class RuntimePrimaryProjectionOutboxWriter
{
    public const PROJECTION_VERSION = 'v1-normalized-all-modules';
}

final class RuntimePrimaryStagingRequestFinalizerTestDatabase implements DatabaseConnectionInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $events = [];

    public function __construct(array $events)
    {
        foreach ($events as $event) {
            $this->events[(int)$event['state_revision']] = $event;
        }
        ksort($this->events, SORT_NUMERIC);
    }

    public function driver(): string { return 'mysql'; }

    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'where state_revision = :state_revision')) {
            $revision = (int)($params['state_revision'] ?? 0);
            return isset($this->events[$revision]) ? [$this->events[$revision]] : [];
        }
        if (str_contains($normalized, 'group by status')) {
            $groups = [];
            foreach ($this->events as $event) {
                $status = (string)$event['status'];
                $groups[$status][] = (int)$event['state_revision'];
            }
            ksort($groups, SORT_STRING);
            $rows = [];
            foreach ($groups as $status => $revisions) {
                $rows[] = [
                    'status' => $status,
                    'event_count' => count($revisions),
                    'min_revision' => min($revisions),
                    'max_revision' => max($revisions),
                ];
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected finalizer test SQL: ' . $normalized);
    }
}

final class DatabasePrimaryStateStorageAdapter
{
    public const DRIVER = 'database';
    private int $revision;
    private array $snapshot;
    private string $sha;

    public function __construct(int $revision, array $snapshot)
    {
        $this->setState($revision, $snapshot);
    }

    public function driver(): string { return self::DRIVER; }
    public function status(): array
    {
        return [
            'ok' => true,
            'driver' => self::DRIVER,
            'revision' => $this->revision,
            'state_sha256' => $this->sha,
        ];
    }
    public function readOnly(callable $callback): mixed
    {
        $copy = $this->snapshot;
        return $callback($copy);
    }
    public function setState(int $revision, array $snapshot): void
    {
        $this->revision = $revision;
        $this->snapshot = $snapshot;
        $this->sha = hash('sha256', self::canonicalJson($snapshot));
    }
    public function sha(): string { return $this->sha; }
    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

final class RuntimePrimaryStagingRequestFinalizerTestWorker implements RuntimePrimaryProjectionWorkerInterface
{
    public int $calls = 0;

    public function __construct(
        private RuntimePrimaryStagingRequestFinalizerTestDatabase $database,
        private string $mode = 'complete'
    ) {}

    public function runOnce(): array
    {
        $this->calls++;
        foreach ($this->database->events as $revision => &$event) {
            if ((string)$event['status'] === 'completed') continue;
            if ($this->mode === 'busy') {
                return [
                    'ok' => true,
                    'action' => 'projection_busy',
                    'claimed' => false,
                    'state_revision' => $revision,
                ];
            }
            if ($this->mode === 'failed') {
                $event['status'] = 'failed';
                $event['attempt_count'] = (int)$event['attempt_count'] + 1;
                $event['last_error'] = 'forced projection failure';
                return [
                    'ok' => false,
                    'action' => 'projection_failed',
                    'claimed' => true,
                    'state_revision' => $revision,
                    'attempt_count' => $event['attempt_count'],
                ];
            }
            $event['status'] = 'completed';
            $event['attempt_count'] = (int)$event['attempt_count'] + 1;
            $event['lease_token'] = '';
            $event['lease_expires_at_utc'] = '';
            $event['last_error'] = '';
            return [
                'ok' => true,
                'action' => 'projection_completed',
                'claimed' => true,
                'state_revision' => $revision,
                'state_sha256' => (string)$event['state_sha256'],
                'attempt_count' => $event['attempt_count'],
                'parity_ok' => true,
            ];
        }
        unset($event);
        return ['ok' => true, 'action' => 'projection_noop', 'claimed' => false];
    }
}

final class RuntimePrimaryStagingRequestFinalizerTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public int $calls = 0;

    public function __construct(
        private bool $valid = true,
        private ?DatabasePrimaryStateStorageAdapter $storageToMutate = null
    ) {}

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        if ($this->storageToMutate !== null) {
            $this->storageToMutate->setState($stateRevision + 1, ['drift' => true]);
        }
        return [
            'ok' => $this->valid,
            'parity_ok' => $this->valid,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => str_repeat('f', 64),
        ];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestFinalizer.php';

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

$now = 1_800_000_000;
$session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig([
    'staging_db_primary_request_session' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 1,
        'max_revision_delta' => 4,
        'max_worker_ticks' => 4,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ],
]);
$resolution = [
    'resolved' => true,
    'projection_outbox_enabled' => true,
    'read_only_readiness_audit' => true,
    'drift_check_passed' => true,
    'baseline_state_revision' => 1,
    'state_revision' => 1,
];
$event = static function (int $revision, string $sha, string $status, int $attempts = 0): array {
    return [
        'state_revision' => $revision,
        'state_sha256' => $sha,
        'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
        'status' => $status,
        'attempt_count' => $attempts,
        'lease_token' => '',
        'lease_expires_at_utc' => '',
        'last_error' => '',
    ];
};

$storage = new DatabasePrimaryStateStorageAdapter(1, ['users' => []]);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, $storage->sha(), 'completed', 1),
]);
$worker = new RuntimePrimaryStagingRequestFinalizerTestWorker($db);
$auditor = new RuntimePrimaryStagingRequestFinalizerTestAuditor();
$report = (new RuntimePrimaryStagingRequestFinalizer($db, $worker, $auditor, $session, $now))
    ->finalize($storage, $resolution);
$assertTrue(($report['action'] ?? '') === 'request_state_already_projected', 'Completed state must finalize without worker');
$assertTrue(($report['worker_tick_count'] ?? -1) === 0, 'Completed state must use zero worker ticks');
$assertTrue($worker->calls === 0 && $auditor->calls === 1, 'Completed state must still run one read-only audit');

$storage = new DatabasePrimaryStateStorageAdapter(2, ['users' => ['u1' => ['balance' => 10]]]);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, str_repeat('1', 64), 'completed', 1),
    $event(2, $storage->sha(), 'pending', 0),
]);
$worker = new RuntimePrimaryStagingRequestFinalizerTestWorker($db);
$auditor = new RuntimePrimaryStagingRequestFinalizerTestAuditor();
$report = (new RuntimePrimaryStagingRequestFinalizer($db, $worker, $auditor, $session, $now))
    ->finalize($storage, $resolution);
$assertTrue(($report['action'] ?? '') === 'request_state_projected_and_audited', 'Pending request state must be projected');
$assertTrue(($report['worker_tick_count'] ?? 0) === 1, 'One pending revision must use one worker tick');
$assertTrue(($db->events[2]['status'] ?? '') === 'completed', 'Pending revision must become completed');
$assertTrue(($report['remaining_session_revisions'] ?? -1) === 3, 'Finalizer must report remaining bounded revisions');

$storage = new DatabasePrimaryStateStorageAdapter(3, ['sequence' => 3]);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, str_repeat('1', 64), 'completed', 1),
    $event(2, str_repeat('2', 64), 'pending', 0),
    $event(3, $storage->sha(), 'pending', 0),
]);
$worker = new RuntimePrimaryStagingRequestFinalizerTestWorker($db);
$report = (new RuntimePrimaryStagingRequestFinalizer(
    $db,
    $worker,
    new RuntimePrimaryStagingRequestFinalizerTestAuditor(),
    $session,
    $now
))->finalize($storage, $resolution);
$assertTrue(($report['worker_tick_count'] ?? 0) === 2, 'Two pending revisions must be finalized in order');
$assertTrue(($db->events[2]['status'] ?? '') === 'completed' && ($db->events[3]['status'] ?? '') === 'completed', 'All pending revisions must complete');

foreach ([
    'busy' => 'projection_busy',
    'failed' => 'projection_failed',
] as $mode => $message) {
    $storage = new DatabasePrimaryStateStorageAdapter(2, ['mode' => $mode]);
    $db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
        $event(1, str_repeat('1', 64), 'completed', 1),
        $event(2, $storage->sha(), 'pending', 0),
    ]);
    $worker = new RuntimePrimaryStagingRequestFinalizerTestWorker($db, $mode);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingRequestFinalizer(
            $db,
            $worker,
            new RuntimePrimaryStagingRequestFinalizerTestAuditor(),
            $session,
            $now
        ))->finalize($storage, $resolution),
        $message
    );
    $assertTrue(
        in_array((string)$db->events[2]['status'], $mode === 'busy' ? ['pending'] : ['failed'], true),
        'Worker failure must leave an explicit recovery state'
    );
}

$storage = new DatabasePrimaryStateStorageAdapter(5, ['sequence' => 5]);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, str_repeat('1', 64), 'completed', 1),
    $event(2, str_repeat('2', 64), 'completed', 1),
    $event(3, str_repeat('3', 64), 'completed', 1),
    $event(4, str_repeat('4', 64), 'completed', 1),
    $event(5, $storage->sha(), 'completed', 1),
]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestFinalizer(
        $db,
        new RuntimePrimaryStagingRequestFinalizerTestWorker($db),
        new RuntimePrimaryStagingRequestFinalizerTestAuditor(),
        $session,
        $now
    ))->finalize($storage, $resolution),
    'exceeds the bounded request session'
);

$storage = new DatabasePrimaryStateStorageAdapter(1, ['audit' => 'bad']);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, $storage->sha(), 'completed', 1),
]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestFinalizer(
        $db,
        new RuntimePrimaryStagingRequestFinalizerTestWorker($db),
        new RuntimePrimaryStagingRequestFinalizerTestAuditor(false),
        $session,
        $now
    ))->finalize($storage, $resolution),
    'audit did not pass exact parity'
);

$storage = new DatabasePrimaryStateStorageAdapter(1, ['audit' => 'drift']);
$db = new RuntimePrimaryStagingRequestFinalizerTestDatabase([
    $event(1, $storage->sha(), 'completed', 1),
]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestFinalizer(
        $db,
        new RuntimePrimaryStagingRequestFinalizerTestWorker($db),
        new RuntimePrimaryStagingRequestFinalizerTestAuditor(true, $storage),
        $session,
        $now
    ))->finalize($storage, $resolution),
    'state changed during request finalization'
);

fwrite(STDOUT, "RuntimePrimaryStagingRequestFinalizerTest passed: {$assertions} assertions.\n");
