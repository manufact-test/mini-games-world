<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require_once $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryAtomicStorageAdapter.php';

final class ProductionAtomicTestDatabase implements DatabaseConnectionInterface
{
    public array $state;
    public array $events;
    private array $snapshots = [];

    public function __construct()
    {
        $snapshot = ['users' => []];
        $json = self::canonicalJson($snapshot);
        $sha = hash('sha256', $json);
        $now = '2026-07-23T14:00:00+00:00';
        $this->state = [
            'singleton_id' => 1,
            'revision' => 1,
            'state_json' => $json,
            'state_sha256' => $sha,
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ];
        $this->events = [
            1 => [
                'state_revision' => 1,
                'event_id' => hash('sha256', RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION . '|1|' . $sha),
                'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
                'state_sha256' => $sha,
                'state_json' => $json,
                'status' => 'completed',
                'attempt_count' => 1,
                'lease_token' => '',
                'lease_expires_at_utc' => '',
                'last_error' => '',
                'available_at_utc' => $now,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
            ],
        ];
    }

    public function driver(): string { return 'mysql'; }

    public function execute(string $sql, array $parameters = []): int
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with(
            $normalized,
            'update ' . RuntimePrimaryStateSchemaInstaller::TABLE . ' set revision = :next_revision'
        )) {
            if ((int)$this->state['revision'] !== (int)$parameters['expected_revision']) return 0;
            $this->state['revision'] = (int)$parameters['next_revision'];
            $this->state['state_json'] = (string)$parameters['state_json'];
            $this->state['state_sha256'] = (string)$parameters['state_sha256'];
            $this->state['updated_at_utc'] = (string)$parameters['updated_at_utc'];
            return 1;
        }
        if (str_starts_with(
            $normalized,
            'insert into ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        )) {
            $revision = (int)$parameters['state_revision'];
            if (isset($this->events[$revision])) return 0;
            $this->events[$revision] = [
                'state_revision' => $revision,
                'event_id' => (string)$parameters['event_id'],
                'projection_version' => (string)$parameters['projection_version'],
                'state_sha256' => (string)$parameters['state_sha256'],
                'state_json' => (string)$parameters['state_json'],
                'status' => (string)$parameters['status'],
                'attempt_count' => 0,
                'lease_token' => (string)$parameters['lease_token'],
                'lease_expires_at_utc' => (string)$parameters['lease_expires_at_utc'],
                'last_error' => (string)$parameters['last_error'],
                'available_at_utc' => (string)$parameters['available_at_utc'],
                'created_at_utc' => (string)$parameters['created_at_utc'],
                'updated_at_utc' => (string)$parameters['updated_at_utc'],
            ];
            return 1;
        }
        throw new RuntimeException('Unexpected production atomic test execute SQL: ' . $normalized);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'where singleton_id = 1')) {
            return [$this->state];
        }
        if (str_contains($normalized, 'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)
            && str_contains($normalized, 'where state_revision = :state_revision')) {
            $revision = (int)$parameters['state_revision'];
            return isset($this->events[$revision]) ? [$this->events[$revision]] : [];
        }
        if (str_contains($normalized, 'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)
            && str_contains($normalized, 'group by status')) {
            $groups = [];
            foreach ($this->events as $event) {
                $status = (string)$event['status'];
                $groups[$status][] = (int)$event['state_revision'];
            }
            ksort($groups, SORT_STRING);
            $rows = [];
            foreach ($groups as $status => $revisions) {
                sort($revisions, SORT_NUMERIC);
                $rows[] = [
                    'status' => $status,
                    'event_count' => count($revisions),
                    'min_revision' => min($revisions),
                    'max_revision' => max($revisions),
                ];
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected production atomic test fetch SQL: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        $this->snapshots[] = unserialize(serialize([$this->state, $this->events]));
        try {
            $result = $callback($this);
            array_pop($this->snapshots);
            return $result;
        } catch (Throwable $error) {
            [$this->state, $this->events] = array_pop($this->snapshots);
            throw $error;
        }
    }

    public function completeNewestEvent(): array
    {
        $revision = max(array_keys($this->events));
        $this->events[$revision]['status'] = 'completed';
        $this->events[$revision]['attempt_count']++;
        $this->events[$revision]['lease_token'] = '';
        $this->events[$revision]['lease_expires_at_utc'] = '';
        $this->events[$revision]['last_error'] = '';
        return $this->events[$revision];
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

final class ProductionAtomicTestWorker implements RuntimePrimaryProjectionWorkerInterface
{
    public int $calls = 0;
    public function __construct(
        private ProductionAtomicTestDatabase $database,
        private bool $fail = false
    ) {}
    public function runOnce(): array
    {
        $this->calls++;
        $revision = (int)$this->database->state['revision'];
        if ($this->fail) {
            return [
                'ok' => false,
                'action' => 'projection_failed',
                'claimed' => true,
                'state_revision' => $revision,
                'parity_ok' => false,
            ];
        }
        $event = $this->database->completeNewestEvent();
        return [
            'ok' => true,
            'action' => 'projection_completed',
            'claimed' => true,
            'state_revision' => $revision,
            'state_sha256' => (string)$event['state_sha256'],
            'attempt_count' => (int)$event['attempt_count'],
            'parity_ok' => true,
        ];
    }
}

final class ProductionAtomicTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];
    public int $calls = 0;
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        return [
            'ok' => true,
            'parity_ok' => true,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => self::MODULES,
            'all_module_fingerprint' => hash('sha256', 'audit|' . $stateRevision . '|' . $stateSha256),
        ];
    }
}

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

$database = new ProductionAtomicTestDatabase();
$worker = new ProductionAtomicTestWorker($database);
$auditor = new ProductionAtomicTestAuditor();
$storage = new ProductionPrimaryAtomicStorageAdapter(
    $database,
    new DatabasePrimaryStateStorageAdapter(
        $database,
        new RuntimePrimaryProjectionOutboxWriter()
    ),
    $worker,
    $auditor
);
$result = $storage->transaction(static function (array &$data): string {
    $data['users']['user_1'] = ['id' => 'user_1', 'balance_match' => 50];
    return 'committed';
});
$assertTrue($result === 'committed', 'Atomic transaction must preserve callback result');
$assertTrue((int)$database->state['revision'] === 2, 'Atomic success must commit exactly one state revision');
$assertTrue(count($database->events) === 2, 'Atomic success must commit exactly one outbox event');
$assertTrue(($database->events[2]['status'] ?? '') === 'completed', 'Atomic success must complete projection before commit');
$assertTrue($worker->calls === 1, 'Atomic success must execute exactly one worker tick');
$assertTrue($auditor->calls === 2, 'Atomic success must audit locked baseline and final state');
$report = $storage->lastTransactionReport();
$assertTrue(($report['ok'] ?? false) === true, 'Atomic success report must pass');
$assertTrue(($report['baseline_locked'] ?? false) === true, 'Atomic success must prove baseline lock');
$assertTrue(($report['worker_tick_count'] ?? 0) === 1, 'Atomic success must report one worker tick');
$assertTrue(($report['rollback_requires_fresh_db_export'] ?? false) === true, 'Atomic success must expose rollback export requirement');

$database = new ProductionAtomicTestDatabase();
$worker = new ProductionAtomicTestWorker($database, true);
$auditor = new ProductionAtomicTestAuditor();
$storage = new ProductionPrimaryAtomicStorageAdapter(
    $database,
    new DatabasePrimaryStateStorageAdapter(
        $database,
        new RuntimePrimaryProjectionOutboxWriter()
    ),
    $worker,
    $auditor
);
$beforeState = $database->state;
$beforeEvents = $database->events;
$assertThrows(
    static fn() => $storage->transaction(static function (array &$data): void {
        $data['users']['user_2'] = ['id' => 'user_2'];
    }),
    'did not complete'
);
$assertTrue($database->state === $beforeState, 'Projection failure must roll back state revision and payload');
$assertTrue($database->events === $beforeEvents, 'Projection failure must roll back the pending outbox event');
$assertTrue($worker->calls === 1, 'Projection failure must attempt exactly one worker tick');

$database = new ProductionAtomicTestDatabase();
$worker = new ProductionAtomicTestWorker($database);
$auditor = new ProductionAtomicTestAuditor();
$storage = new ProductionPrimaryAtomicStorageAdapter(
    $database,
    new DatabasePrimaryStateStorageAdapter(
        $database,
        new RuntimePrimaryProjectionOutboxWriter()
    ),
    $worker,
    $auditor
);
$value = $storage->transaction(static fn(array &$data): int => count($data['users'] ?? []));
$assertTrue($value === 0, 'No-change transaction must return callback result');
$assertTrue((int)$database->state['revision'] === 1, 'No-change transaction must not increment revision');
$assertTrue(count($database->events) === 1, 'No-change transaction must not create outbox event');
$assertTrue($worker->calls === 0, 'No-change transaction must not run worker');
$assertTrue(($storage->lastTransactionReport()['worker_tick_count'] ?? -1) === 0, 'No-change report must expose zero worker ticks');

fwrite(STDOUT, "ProductionPrimaryAtomicStorageAdapterTest passed: {$assertions} assertions.\n");
