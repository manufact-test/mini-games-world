<?php
declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionProjectorInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionWorker.php';

final class ProjectionWorkerTestConnection implements DatabaseConnectionInterface
{
    private array $events = [];

    public function __construct(array $events)
    {
        foreach ($events as $event) {
            $this->events[(int)$event['state_revision']] = $event;
        }
        ksort($this->events, SORT_NUMERIC);
    }

    public function driver(): string { return 'sqlite'; }
    public function pdo(): PDO { throw new RuntimeException('unused'); }
    public function event(int $revision): array { return $this->events[$revision] ?? []; }

    public function execute(string $sql, array $params = []): int
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        $revision = (int)($params['state_revision'] ?? 0);
        if (!isset($this->events[$revision])) return 0;
        $event = &$this->events[$revision];

        if (str_contains($sql, "set status = 'processing'")) {
            if ((string)$event['status'] !== (string)$params['expected_status']
                || (int)$event['attempt_count'] !== (int)$params['expected_attempt_count']) return 0;
            $event['status'] = 'processing';
            $event['attempt_count'] = (int)$params['attempt_count'];
            $event['lease_token'] = (string)$params['lease_token'];
            $event['lease_expires_at_utc'] = (string)$params['lease_expires_at_utc'];
            $event['last_error'] = '';
            $event['updated_at_utc'] = (string)$params['updated_at_utc'];
            return 1;
        }
        if (str_contains($sql, "set status = 'completed'")) {
            if ($event['status'] !== 'processing'
                || !hash_equals((string)$event['lease_token'], (string)$params['lease_token'])) return 0;
            $event['status'] = 'completed';
            $event['lease_token'] = '';
            $event['lease_expires_at_utc'] = '';
            $event['last_error'] = '';
            $event['updated_at_utc'] = (string)$params['updated_at_utc'];
            return 1;
        }
        if (str_contains($sql, "set status = 'failed'")) {
            if ($event['status'] !== 'processing'
                || !hash_equals((string)$event['lease_token'], (string)$params['lease_token'])) return 0;
            $event['status'] = 'failed';
            $event['lease_token'] = '';
            $event['lease_expires_at_utc'] = '';
            $event['last_error'] = (string)$params['last_error'];
            $event['available_at_utc'] = (string)$params['available_at_utc'];
            $event['updated_at_utc'] = (string)$params['updated_at_utc'];
            return 1;
        }
        throw new RuntimeException('Unexpected worker test SQL execute: ' . $sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($sql, "where status <> 'completed'")) {
            foreach ($this->events as $event) {
                if ($event['status'] !== 'completed') return [$event];
            }
            return [];
        }
        if (str_contains($sql, 'select attempt_count from')) {
            $revision = (int)$params['state_revision'];
            return isset($this->events[$revision])
                ? [['attempt_count' => $this->events[$revision]['attempt_count']]]
                : [];
        }
        throw new RuntimeException('Unexpected worker test SQL fetch: ' . $sql);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->fetchAll($sql, $params);
        if ($rows === []) return null;
        $firstRow = $rows[0] ?? null;
        if (!is_array($firstRow) || $firstRow === []) return null;
        $value = reset($firstRow);
        return $value === false ? null : $value;
    }

    public function transaction(callable $callback): mixed
    {
        $before = $this->events;
        try {
            return $callback($this);
        } catch (Throwable $error) {
            $this->events = $before;
            throw $error;
        }
    }
}

final class ProjectionWorkerTestProjector implements RuntimePrimaryProjectionProjectorInterface
{
    public array $calls = [];

    public function __construct(private string $mode = 'success') {}

    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls[] = [$snapshot, $stateRevision, $stateSha256];
        if ($this->mode === 'throw') throw new RuntimeException('forced projector failure');

        $modules = [
            'accounts', 'realtime', 'invites', 'notifications', 'economy',
            'history', 'shop', 'payments', 'weekly_bonus',
        ];
        if ($this->mode === 'missing_module') array_pop($modules);
        return [
            'ok' => true,
            'parity_ok' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => $modules,
        ];
    }
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$canonical = static function (array $value) use (&$canonical): string {
    $sort = static function (mixed $item) use (&$sort): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $sort($child);
        return $item;
    };
    return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};
$event = static function (int $revision, array $snapshot, string $status = 'pending', array $overrides = []) use ($canonical): array {
    $json = $canonical($snapshot);
    return array_replace([
        'state_revision' => $revision,
        'event_id' => hash('sha256', RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION . '|' . $revision . '|' . hash('sha256', $json)),
        'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
        'state_sha256' => hash('sha256', $json),
        'state_json' => $json,
        'status' => $status,
        'attempt_count' => 0,
        'lease_token' => '',
        'lease_expires_at_utc' => '',
        'last_error' => '',
        'available_at_utc' => '2026-07-20T10:00:00+00:00',
        'created_at_utc' => '2026-07-20T10:00:00+00:00',
        'updated_at_utc' => '2026-07-20T10:00:00+00:00',
    ], $overrides);
};

$now = strtotime('2026-07-20T10:05:00+00:00');
$snapshot1 = ['users' => ['100' => ['balance' => 50]], 'system' => ['sequence' => 1]];
$snapshot2 = ['users' => ['100' => ['balance' => 75]], 'system' => ['sequence' => 2]];

$db = new ProjectionWorkerTestConnection([$event(1, $snapshot1), $event(2, $snapshot2)]);
$projector = new ProjectionWorkerTestProjector();
$worker = new RuntimePrimaryProjectionWorker($db, $projector, 120, $now);
$first = $worker->runOnce();
$assert(($first['action'] ?? '') === 'projection_completed', 'Oldest event must complete');
$assert(($first['state_revision'] ?? 0) === 1, 'Worker must process revision one first');
$assert(($db->event(1)['status'] ?? '') === 'completed', 'Revision one must persist completed');
$assert(($db->event(2)['status'] ?? '') === 'pending', 'Revision two must remain pending');
$assert(count($projector->calls) === 1, 'Projector must run once');

$second = $worker->runOnce();
$assert(($second['state_revision'] ?? 0) === 2, 'Second tick must process revision two');
$assert(($db->event(2)['status'] ?? '') === 'completed', 'Revision two must persist completed');
$assert(($worker->runOnce()['reason'] ?? '') === 'queue_empty', 'Empty queue must be a safe no-op');

$busyDb = new ProjectionWorkerTestConnection([
    $event(1, $snapshot1, 'processing', [
        'attempt_count' => 1,
        'lease_token' => str_repeat('a', 48),
        'lease_expires_at_utc' => '2026-07-20T10:06:00+00:00',
    ]),
    $event(2, $snapshot2),
]);
$busyProjector = new ProjectionWorkerTestProjector();
$busy = (new RuntimePrimaryProjectionWorker($busyDb, $busyProjector, 120, $now))->runOnce();
$assert(($busy['action'] ?? '') === 'projection_busy', 'Live lease must block the oldest revision');
$assert(count($busyProjector->calls) === 0, 'Worker must not skip a leased oldest revision');
$assert(($busyDb->event(2)['status'] ?? '') === 'pending', 'Later revision must remain pending');

$expiredDb = new ProjectionWorkerTestConnection([
    $event(1, $snapshot1, 'processing', [
        'attempt_count' => 1,
        'lease_token' => str_repeat('b', 48),
        'lease_expires_at_utc' => '2026-07-20T10:04:00+00:00',
    ]),
]);
$expired = (new RuntimePrimaryProjectionWorker(
    $expiredDb,
    new ProjectionWorkerTestProjector(),
    120,
    $now
))->runOnce();
$assert(($expired['action'] ?? '') === 'projection_completed', 'Expired lease must be reclaimable');
$assert(($expiredDb->event(1)['attempt_count'] ?? 0) === 2, 'Reclaimed event must increment attempts');

$failedDb = new ProjectionWorkerTestConnection([$event(1, $snapshot1), $event(2, $snapshot2)]);
$failedWorker = new RuntimePrimaryProjectionWorker(
    $failedDb,
    new ProjectionWorkerTestProjector('throw'),
    120,
    $now
);
$failed = $failedWorker->runOnce();
$assert(($failed['action'] ?? '') === 'projection_failed', 'Projector exception must fail the event');
$assert(($failedDb->event(1)['status'] ?? '') === 'failed', 'Failed event must persist failed status');
$assert(($failedDb->event(2)['status'] ?? '') === 'pending', 'Failure must not skip to later revision');
$delayed = $failedWorker->runOnce();
$assert(($delayed['action'] ?? '') === 'projection_delayed', 'Failed oldest revision must respect retry backoff');

$parityDb = new ProjectionWorkerTestConnection([$event(1, $snapshot1)]);
$parity = (new RuntimePrimaryProjectionWorker(
    $parityDb,
    new ProjectionWorkerTestProjector('missing_module'),
    120,
    $now
))->runOnce();
$assert(($parity['action'] ?? '') === 'projection_failed', 'Incomplete module projection must fail');
$assert(str_contains((string)($parity['error_message'] ?? ''), 'missing required runtime modules'), 'Failure must explain missing modules');

fwrite(STDOUT, "RuntimePrimaryProjectionWorkerTest passed: {$assertions} assertions.\n");
