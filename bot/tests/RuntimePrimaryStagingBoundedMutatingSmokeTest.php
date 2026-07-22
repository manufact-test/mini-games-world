<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function execute(string $sql, array $parameters = []): int;
    public function fetchAll(string $sql, array $parameters = []): array;
    public function fetchValue(string $sql, array $parameters = []): mixed;
    public function transaction(callable $callback): mixed;
}
interface RuntimePrimaryProjectionWorkerInterface { public function runOnce(): array; }
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

final class MutatingSmokeTestFixture
{
    public const MODULES = [
        'accounts', 'realtime', 'economy', 'notifications', 'invites',
        'history', 'shop', 'payments', 'weekly_bonus',
    ];

    public static function canonicalJson(array $value): string
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

    public static function sha(array $state): string
    {
        return hash('sha256', self::canonicalJson($state));
    }

    public static function event(int $revision, string $sha, string $status = 'completed', int $attempt = 1): array
    {
        return [
            'state_revision' => $revision,
            'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
            'state_sha256' => $sha,
            'status' => $status,
            'attempt_count' => $attempt,
            'lease_token' => '',
            'lease_expires_at_utc' => '',
            'last_error' => '',
            'available_at_utc' => '2026-07-22T17:00:00+00:00',
            'created_at_utc' => '2026-07-22T17:00:00+00:00',
            'updated_at_utc' => '2026-07-22T17:00:00+00:00',
        ];
    }
}

final class MutatingSmokeTestStore
{
    public function __construct(public array $state, public int $revision, public array $outbox) {}
}

final class MutatingSmokeTestDatabase implements DatabaseConnectionInterface
{
    public function __construct(public MutatingSmokeTestStore $store) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int { return 0; }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $revision = (int)($parameters['state_revision'] ?? 0);
        if (str_contains($sql, 'WHERE state_revision <= :state_revision')) {
            return array_values(array_filter(
                $this->store->outbox,
                static fn(array $row): bool => (int)$row['state_revision'] <= $revision
            ));
        }
        if (str_contains($sql, 'WHERE state_revision = :state_revision')) {
            return array_values(array_filter(
                $this->store->outbox,
                static fn(array $row): bool => (int)$row['state_revision'] === $revision
            ));
        }
        return [];
    }

    public function transaction(callable $callback): mixed
    {
        $backup = [
            'state' => $this->store->state,
            'revision' => $this->store->revision,
            'outbox' => $this->store->outbox,
        ];
        try {
            return $callback($this);
        } catch (Throwable $error) {
            $this->store->state = $backup['state'];
            $this->store->revision = $backup['revision'];
            $this->store->outbox = $backup['outbox'];
            throw $error;
        }
    }
}

final class DatabasePrimaryStateStorageAdapter
{
    public const DRIVER = 'database';
    public function __construct(private MutatingSmokeTestStore $store) {}
    public function driver(): string { return self::DRIVER; }
    public function status(): array
    {
        return [
            'ok' => true,
            'driver' => self::DRIVER,
            'revision' => $this->store->revision,
            'state_sha256' => MutatingSmokeTestFixture::sha($this->store->state),
            'projection_outbox_enabled' => true,
        ];
    }
    public function readOnly(callable $callback): mixed { return $callback($this->store->state); }
    public function transaction(callable $callback): mixed
    {
        $before = MutatingSmokeTestFixture::sha($this->store->state);
        $state = $this->store->state;
        $result = $callback($state);
        $after = MutatingSmokeTestFixture::sha($state);
        if (!hash_equals($before, $after)) {
            $this->store->state = $state;
            $this->store->revision++;
            $this->store->outbox[] = MutatingSmokeTestFixture::event(
                $this->store->revision,
                $after,
                'pending',
                0
            );
        }
        return $result;
    }
}

final class MutatingSmokeTestWorker implements RuntimePrimaryProjectionWorkerInterface
{
    public bool $fail = false;
    public function __construct(private MutatingSmokeTestStore $store) {}
    public function runOnce(): array
    {
        $index = array_key_last($this->store->outbox);
        if ($index === null) throw new RuntimeException('Missing test event.');
        $row = $this->store->outbox[$index];
        if ($this->fail) return ['ok' => false, 'action' => 'projection_failed', 'claimed' => true];
        $this->store->outbox[$index]['status'] = 'completed';
        $this->store->outbox[$index]['attempt_count'] = 1;
        return [
            'ok' => true,
            'action' => 'projection_completed',
            'claimed' => true,
            'state_revision' => (int)$row['state_revision'],
            'state_sha256' => (string)$row['state_sha256'],
            'attempt_count' => 1,
            'projected_modules' => MutatingSmokeTestFixture::MODULES,
            'parity_ok' => true,
        ];
    }
}

final class MutatingSmokeTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public bool $fail = false;
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        return [
            'ok' => !$this->fail,
            'parity_ok' => !$this->fail,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => MutatingSmokeTestFixture::MODULES,
            'all_module_fingerprint' => hash('sha256', MutatingSmokeTestFixture::canonicalJson([
                'revision' => $stateRevision,
                'sha' => $stateSha256,
                'modules' => MutatingSmokeTestFixture::MODULES,
            ])),
        ];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeRollbackSignal.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingBoundedMutatingSmoke.php';

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
$make = static function (): array {
    $state = [
        'users' => ['100' => ['id' => '100', 'balance' => 50]],
        'games' => [],
        'queue' => [],
        'system' => ['sequence' => 1],
    ];
    $sha = MutatingSmokeTestFixture::sha($state);
    $store = new MutatingSmokeTestStore($state, 1, [MutatingSmokeTestFixture::event(1, $sha)]);
    $mutationDatabase = new MutatingSmokeTestDatabase($store);
    $verificationDatabase = new MutatingSmokeTestDatabase($store);
    $worker = new MutatingSmokeTestWorker($store);
    $mutationAuditor = new MutatingSmokeTestAuditor();
    $verificationAuditor = new MutatingSmokeTestAuditor();
    $smoke = new RuntimePrimaryStagingBoundedMutatingSmoke(
        $mutationDatabase,
        $verificationDatabase,
        new DatabasePrimaryStateStorageAdapter($store),
        new DatabasePrimaryStateStorageAdapter($store),
        $worker,
        $mutationAuditor,
        $verificationAuditor,
        1784743200
    );
    return [$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state];
};

$approvalId = str_repeat('a', 64);
$challengeSha = str_repeat('b', 64);

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$report = $smoke->run($approvalId, 1, $sha, $challengeSha);
$assertTrue(($report['ok'] ?? false) === true, 'Bounded mutating smoke must pass');
$assertTrue(($report['mutation_state_revision'] ?? 0) === 2, 'Exactly one temporary revision must be created');
$assertTrue(($report['worker_tick_count'] ?? 0) === 1, 'Exactly one worker tick must run');
$assertTrue(($report['rollback_signal_caught'] ?? false) === true, 'Rollback signal must be caught');
$assertTrue(($report['committed_state_write_count'] ?? -1) === 0, 'No state write may remain committed');
$assertTrue(($report['committed_outbox_event_count'] ?? -1) === 0, 'No outbox event may remain committed');
$assertTrue(($report['production_changed'] ?? true) === false, 'Production must remain unchanged');
$assertTrue($store->revision === 1 && $store->state === $state, 'State must be restored exactly');
$assertTrue(count($store->outbox) === 1 && $store->outbox[0]['status'] === 'completed', 'Outbox must be restored exactly');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$worker->fail = true;
$assertThrows(static fn() => $smoke->run($approvalId, 1, $sha, $challengeSha), 'worker did not complete');
$assertTrue($store->revision === 1 && $store->state === $state && count($store->outbox) === 1, 'Worker failure must rollback');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$mutationAuditor->fail = true;
$assertThrows(static fn() => $smoke->run($approvalId, 1, $sha, $challengeSha), 'all-module audit is invalid');
$assertTrue($store->revision === 1 && $store->state === $state && count($store->outbox) === 1, 'Audit failure must rollback');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$assertThrows(static fn() => $smoke->run($approvalId, 2, $sha, $challengeSha), 'no longer matches the approved');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor] = $make();
$store->state['__mgw_staging_mutating_smoke'] = ['stale' => true];
$staleSha = MutatingSmokeTestFixture::sha($store->state);
$store->outbox[0]['state_sha256'] = $staleSha;
$assertThrows(
    static fn() => $smoke->run($approvalId, 1, $staleSha, $challengeSha),
    'already contains the reserved'
);

fwrite(STDOUT, "RuntimePrimaryStagingBoundedMutatingSmokeTest passed: {$assertions} assertions.\n");
