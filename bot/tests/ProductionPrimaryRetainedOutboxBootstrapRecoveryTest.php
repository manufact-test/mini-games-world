<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/bot/storage/contracts/StorageAdapterInterface.php';
require_once $root . '/bot/database/DatabaseConnectionInterface.php';
require_once $root . '/bot/runtime/RuntimePrimaryProjectionWorkerInterface.php';
require_once $root . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $root . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $root . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $root . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require_once $root . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require_once $root . '/bot/runtime/ProductionPrimaryAtomicStorageAdapter.php';

final class RetainedOutboxRecoveryDatabase implements DatabaseConnectionInterface
{
    public array $state;
    public array $events = [];
    private array $snapshots = [];

    public function __construct(int $revision = 3805)
    {
        $snapshot = ['users' => []];
        $json = self::canonicalJson($snapshot);
        $now = '2026-08-01T18:58:06+00:00';
        $this->state = [
            'singleton_id' => 1,
            'revision' => $revision,
            'state_json' => $json,
            'state_sha256' => hash('sha256', $json),
            'created_at_utc' => $now,
            'updated_at_utc' => $now,
        ];
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function seedCompletedTail(int $firstRevision, int $lastRevision): void
    {
        for ($revision = $firstRevision; $revision <= $lastRevision; $revision++) {
            $json = self::canonicalJson(['revision' => $revision]);
            $sha = hash('sha256', $json);
            $this->events[$revision] = [
                'state_revision' => $revision,
                'event_id' => hash(
                    'sha256',
                    RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION . '|' . $revision . '|' . $sha
                ),
                'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
                'state_sha256' => $sha,
                'state_json' => $json,
                'status' => 'completed',
                'attempt_count' => 1,
                'lease_token' => '',
                'lease_expires_at_utc' => '',
                'last_error' => '',
                'available_at_utc' => '2026-08-01T18:58:06+00:00',
                'created_at_utc' => '2026-08-01T18:58:06+00:00',
                'updated_at_utc' => '2026-08-01T18:58:06+00:00',
            ];
        }
        ksort($this->events, SORT_NUMERIC);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $normalized = self::normalizeSql($sql);
        if (str_starts_with(
            $normalized,
            'update ' . RuntimePrimaryStateSchemaInstaller::TABLE . ' set revision = :next_revision'
        )) {
            if ((int)$this->state['revision'] !== (int)($parameters['expected_revision'] ?? 0)) return 0;
            $this->state['revision'] = (int)$parameters['next_revision'];
            $this->state['state_json'] = (string)$parameters['state_json'];
            $this->state['state_sha256'] = (string)$parameters['state_sha256'];
            $this->state['updated_at_utc'] = (string)$parameters['updated_at_utc'];
            return 1;
        }
        if (str_starts_with(
            $normalized,
            'delete from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        )) {
            $cutoff = (int)($parameters['cutoff_revision'] ?? 0);
            $deleted = 0;
            foreach ($this->events as $revision => $event) {
                if (($event['status'] ?? '') !== 'completed' || $revision > $cutoff) continue;
                unset($this->events[$revision]);
                $deleted++;
            }
            return $deleted;
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
            ksort($this->events, SORT_NUMERIC);
            return 1;
        }
        throw new RuntimeException('Unexpected execute SQL: ' . $normalized);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = self::normalizeSql($sql);
        if (str_contains($normalized, 'from ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'where singleton_id = 1')) {
            return [$this->state];
        }
        if (str_contains($normalized, 'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)
            && str_contains($normalized, 'where state_revision = :state_revision')) {
            $revision = (int)($parameters['state_revision'] ?? 0);
            return isset($this->events[$revision]) ? [$this->events[$revision]] : [];
        }
        if (str_contains($normalized, 'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)
            && str_contains($normalized, 'group by status')) {
            $groups = [];
            foreach ($this->events as $event) {
                $groups[(string)$event['status']][] = (int)$event['state_revision'];
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
        throw new RuntimeException('Unexpected fetch SQL: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        $normalized = self::normalizeSql($sql);
        if (str_starts_with(
            $normalized,
            'select state_revision from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        ) && str_contains($normalized, "where status = 'completed'")) {
            $completed = [];
            foreach ($this->events as $revision => $event) {
                if (($event['status'] ?? '') === 'completed') $completed[] = $revision;
            }
            rsort($completed, SORT_NUMERIC);
            return $completed[RuntimePrimaryProjectionOutboxWriter::COMPLETED_RETENTION_ROWS] ?? null;
        }
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

    public function completeOldestPending(): array
    {
        foreach ($this->events as $revision => &$event) {
            if (($event['status'] ?? '') !== 'pending') continue;
            $event['status'] = 'completed';
            $event['attempt_count'] = (int)$event['attempt_count'] + 1;
            $completed = $event;
            unset($event);
            return $completed;
        }
        unset($event);
        throw new RuntimeException('No pending projection event is available.');
    }

    private static function normalizeSql(string $sql): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
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

final class RetainedOutboxRecoveryWorker implements RuntimePrimaryProjectionWorkerInterface
{
    private const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public int $calls = 0;

    public function __construct(private RetainedOutboxRecoveryDatabase $database) {}

    public function runOnce(): array
    {
        $this->calls++;
        $event = $this->database->completeOldestPending();
        $revision = (int)$event['state_revision'];
        $sha = (string)$event['state_sha256'];
        return [
            'ok' => true,
            'action' => 'projection_completed',
            'claimed' => true,
            'state_revision' => $revision,
            'state_sha256' => $sha,
            'attempt_count' => (int)$event['attempt_count'],
            'projected_modules' => self::MODULES,
            'mutated_modules' => [],
            'unchanged_modules' => self::MODULES,
            'all_module_fingerprint' => hash('sha256', 'retained-tail|' . $revision . '|' . $sha),
            'parity_ok' => true,
        ];
    }
}

final class RetainedOutboxRecoveryAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public int $calls = 0;

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        throw new RuntimeException('Retained-tail recovery must use the canonical projection worker.');
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
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
$makeStorage = static function (
    RetainedOutboxRecoveryDatabase $database,
    RetainedOutboxRecoveryWorker $worker,
    RetainedOutboxRecoveryAuditor $auditor
): ProductionPrimaryAtomicStorageAdapter {
    return new ProductionPrimaryAtomicStorageAdapter(
        $database,
        new DatabasePrimaryStateStorageAdapter(
            $database,
            new RuntimePrimaryProjectionOutboxWriter()
        ),
        $worker,
        $auditor
    );
};

$database = new RetainedOutboxRecoveryDatabase();
$worker = new RetainedOutboxRecoveryWorker($database);
$auditor = new RetainedOutboxRecoveryAuditor();
$storage = $makeStorage($database, $worker, $auditor);
$result = $storage->transaction(static fn(array &$data): string => 'unchanged');
$report = $storage->lastTransactionReport();
$assert($result === 'unchanged', 'History-reset recovery must preserve callback results.');
$assert((int)$database->state['revision'] === 3805, 'No-change recovery must preserve the primary revision.');
$assert(($database->events[3805]['status'] ?? '') === 'completed', 'History-reset recovery must rebuild the current completed anchor.');
$assert($worker->calls === 1, 'History-reset recovery must run one canonical projection worker tick.');
$assert($auditor->calls === 0, 'History-reset recovery must not use a separate audit-only path.');
$assert(($report['baseline_projection_history_reset_detected'] ?? false) === true, 'Recovery report must expose the detected history reset.');
$assert(($report['baseline_projection_anchor_rebuilt'] ?? false) === true, 'Recovery report must expose the rebuilt anchor.');
$assert(($report['baseline_projection_retained_tail_verified'] ?? false) === true, 'Recovered baseline must end with a verified retained tail.');
$assert(($report['worker_tick_count'] ?? 0) === 1, 'No-change recovery report must count the recovery worker tick.');

$database = new RetainedOutboxRecoveryDatabase();
$worker = new RetainedOutboxRecoveryWorker($database);
$auditor = new RetainedOutboxRecoveryAuditor();
$storage = $makeStorage($database, $worker, $auditor);
$storage->transaction(static function (array &$data): void {
    $data['users']['100'] = ['id' => '100'];
});
$report = $storage->lastTransactionReport();
$assert((int)$database->state['revision'] === 3806, 'Changed recovery must advance exactly one primary revision.');
$assert(($database->events[3805]['status'] ?? '') === 'completed', 'Changed recovery must retain the rebuilt baseline anchor.');
$assert(($database->events[3806]['status'] ?? '') === 'completed', 'Changed recovery must complete the new revision projection.');
$assert($worker->calls === 2, 'Changed recovery must run one recovery tick and one new-revision tick.');
$assert(($report['worker_tick_count'] ?? 0) === 2, 'Changed recovery report must count both worker ticks.');

$database = new RetainedOutboxRecoveryDatabase();
$database->seedCompletedTail(3790, 3805);
$worker = new RetainedOutboxRecoveryWorker($database);
$auditor = new RetainedOutboxRecoveryAuditor();
$storage = $makeStorage($database, $worker, $auditor);
$storage->transaction(static fn(array &$data): int => count($data['users'] ?? []));
$report = $storage->lastTransactionReport();
$assert($worker->calls === 0, 'A valid retained tail must not be rebuilt.');
$assert(($report['baseline_projection_history_reset_detected'] ?? true) === false, 'Valid retained history must not be reported as reset.');
$assert(($report['baseline_projection_retained_tail_verified'] ?? false) === true, 'Valid retained suffix must be accepted.');

$database = new RetainedOutboxRecoveryDatabase();
$database->seedCompletedTail(3790, 3804);
$worker = new RetainedOutboxRecoveryWorker($database);
$auditor = new RetainedOutboxRecoveryAuditor();
$storage = $makeStorage($database, $worker, $auditor);
$assertThrows(
    static fn() => $storage->transaction(static fn(array &$data): null => null),
    'contiguous retained completed tail'
);

$database = new RetainedOutboxRecoveryDatabase(3805);
$database->seedCompletedTail(3803, 3803);
$database->seedCompletedTail(3805, 3805);
$worker = new RetainedOutboxRecoveryWorker($database);
$auditor = new RetainedOutboxRecoveryAuditor();
$storage = $makeStorage($database, $worker, $auditor);
$assertThrows(
    static fn() => $storage->transaction(static fn(array &$data): null => null),
    'contiguous retained completed tail'
);

fwrite(STDOUT, "ProductionPrimaryRetainedOutboxBootstrapRecoveryTest passed: {$assertions} assertions.\n");
