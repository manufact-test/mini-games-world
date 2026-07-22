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

final class RuntimePrimaryStagingMutatingSmokeTestStore
{
    public function __construct(
        public array $state,
        public int $revision,
        public array $outbox
    ) {}
}

final class RuntimePrimaryStagingMutatingSmokeTestDatabase implements DatabaseConnectionInterface
{
    public function __construct(public RuntimePrimaryStagingMutatingSmokeTestStore $store) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int { return 0; }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'WHERE state_revision <= :state_revision')) {
            $limit = (int)($parameters['state_revision'] ?? 0);
            return array_values(array_filter(
                $this->store->outbox,
                static fn(array $row): bool => (int)$row['state_revision'] <= $limit
            ));
        }
        if (str_contains($sql, 'WHERE state_revision = :state_revision')) {
            $revision = (int)($parameters['state_revision'] ?? 0);
            return array_values(array_filter(
                $this->store->outbox,
                static fn(array $row): bool => (int)$row['state_revision'] === $revision
            ));
        }
        return [];
    }
    public function transaction(callable $callback): mixed
    {
        $backup = unserialize(serialize([
            'state' => $this->store->state,
            'revision' => $this->store->revision,
            'outbox' => $this->store->outbox,
        ]));
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
    public function __construct(private RuntimePrimaryStagingMutatingSmokeTestStore $store) {}
    public function driver(): string { return self::DRIVER; }
    public function status(): array
    {
        return [
            'ok' => true,
            'driver' => self::DRIVER,
            'revision' => $this->store->revision,
            'state_sha256' => self::sha($this->store->state),
            'projection_outbox_enabled' => true,
        ];
    }
    public function readOnly(callable $callback): mixed
    {
        return $callback($this->store->state);
    }
    public function transaction(callable $callback): mixed
    {
        $before = self::sha($this->store->state);
        $state = $this->store->state;
        $result = $callback($state);
        $after = self::sha($state);
        if (!hash_equals($before, $after)) {
            $this->store->state = $state;
            $this->store->revision++;
            $revision = $this->store->revision;
            $this->store->outbox[] = RuntimePrimaryStagingMutatingSmokeTestFixture::event(
                $revision,
                $after,
                'pending',
                0
            );
        }
        return $result;
    }
    private static function sha(array $state): string
    {
        return hash('sha256', RuntimePrimaryStagingMutatingSmokeTestFixture::canonicalJson($state));
    }
}

final class RuntimePrimaryStagingMutatingSmokeTestFixture
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
    public static function event(int $revision, string $sha, string $status, int $attempt): array
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

final class RuntimePrimaryStagingMutatingSmokeTestWorker implements RuntimePrimaryProjectionWorkerInterface
{
    public bool $fail = false;
    public function __construct(private RuntimePrimaryStagingMutatingSmokeTestStore $store) {}
    public function runOnce(): array
    {
        $index = array_key_last($this->store->outbox);
        if ($index === null) throw new RuntimeException('missing event');
        $row = $this->store->outbox[$index];
        if ($this->fail) {
            return ['ok' => false, 'action' => 'projection_failed', 'claimed' => true];
        }
        $this->store->outbox[$index]['status'] = 'completed';
        $this->store->outbox[$index]['attempt_count'] = 1;
        return [
            'ok' => true,
            'action' => 'projection_completed',
            'claimed' => true,
            'state_revision' => (int)$row['state_revision'],
            'state_sha256' => (string)$row['state_sha256'],
            'attempt_count' => 1,
            'projected_modules' => RuntimePrimaryStagingMutatingSmokeTestFixture::MODULES,
            'parity_ok' => true,
        ];
    }
}

final class RuntimePrimaryStagingMutatingSmokeTestAuditor implements RuntimePrimaryProjectionAuditorInterface
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
            'projected_modules' => RuntimePrimaryStagingMutatingSmokeTestFixture::MODULES,
            'all_module_fingerprint' => hash(
                'sha256',
                RuntimePrimaryStagingMutatingSmokeTestFixture::canonicalJson([
                    'revision' => $stateRevision,
                    'sha' => $stateSha256,
                    'modules' => RuntimePrimaryStagingMutatingSmokeTestFixture::MODULES,
                ])
            ),
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
    $sha = RuntimePrimaryStagingMutatingSmokeTestFixture::sha($state);
    $store = new RuntimePrimaryStagingMutatingSmokeTestStore(
        $state,
        1,
        [RuntimePrimaryStagingMutatingSmokeTestFixture::event(1, $sha, 'completed', 1)]
    );
    $mutationDatabase = new RuntimePrimaryStagingMutatingSmokeTestDatabase($store);
    $verificationDatabase = new RuntimePrimaryStagingMutatingSmokeTestDatabase($store);
    $mutationStorage = new DatabasePrimaryStateStorageAdapter($store);
    $verificationStorage = new DatabasePrimaryStateStorageAdapter($store);
    $worker = new RuntimePrimaryStagingMutatingSmokeTestWorker($store);
    $mutationAuditor = new RuntimePrimaryStagingMutatingSmokeTestAuditor();
    $verificationAuditor = new RuntimePrimaryStagingMutatingSmokeTestAuditor();
    $smoke = new RuntimePrimaryStagingBoundedMutatingSmoke(
        $mutationDatabase,
        $verificationDatabase,
        $mutationStorage,
        $verificationStorage,
        $worker,
        $mutationAuditor,
        $verificationAuditor,
        1784743200
    );
    return [
        $smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state,
    ];
};

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$approvalId = str_repeat('a', 64);
$challengeSha = str_repeat('b', 64);
$report = $smoke->run($approvalId, 1, $sha, $challengeSha);
$assertTrue(($report['ok'] ?? false) === true, 'Bounded mutating smoke must pass');
$assertTrue(($report['action'] ?? '') === RuntimePrimaryStagingBoundedMutatingSmoke::ACTION, 'Action must remain exact');
$assertTrue(($report['mutation_state_revision'] ?? 0) === 2, 'Exactly one temporary revision must be created');
$assertTrue(($report['worker_tick_count'] ?? 0) === 1, 'Exactly one temporary worker tick must run');
$assertTrue(($report['temporary_projection_completed'] ?? false) === true, 'Temporary projection must complete');
$assertTrue(($report['rollback_signal_caught'] ?? false) === true, 'Mandatory rollback signal must be caught');
$assertTrue(($report['committed_state_write_count'] ?? -1) === 0, 'No state write may remain committed');
$assertTrue(($report['committed_outbox_event_count'] ?? -1) === 0, 'No outbox event may remain committed');
$assertTrue(($report['production_changed'] ?? true) === false, 'Production must remain unchanged');
$assertTrue($store->revision === 1, 'Store revision must be exactly restored');
$assertTrue($store->state === $state, 'Store snapshot must be exactly restored');
$assertTrue(count($store->outbox) === 1, 'Temporary outbox event must be rolled back');
$assertTrue(($store->outbox[0]['status'] ?? '') === 'completed', 'Baseline outbox event must remain completed');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$worker->fail = true;
$assertThrows(
    static fn() => $smoke->run($approvalId, 1, $sha, $challengeSha),
    'worker did not complete'
);
$assertTrue($store->revision === 1 && $store->state === $state && count($store->outbox) === 1, 'Worker failure must rollback all temporary writes');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha, $state] = $make();
$mutationAuditor->fail = true;
$assertThrows(
    static fn() => $smoke->run($approvalId, 1, $sha, $challengeSha),
    'all-module audit is invalid'
);
$assertTrue($store->revision === 1 && $store->state === $state && count($store->outbox) === 1, 'Audit failure must rollback all temporary writes');

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$assertThrows(
    static fn() => $smoke->run($approvalId, 2, $sha, $challengeSha),
    'no longer matches the approved'
);

[$smoke, $store, $worker, $mutationAuditor, $verificationAuditor, $sha] = $make();
$store->state['__mgw_staging_mutating_smoke'] = ['stale' => true];
$assertThrows(
    static fn() => $smoke->run(
        $approvalId,
        1,
        RuntimePrimaryStagingMutatingSmokeTestFixture::sha($store->state),
        $challengeSha
    ),
    'already contains the reserved'
);

fwrite(
    STDOUT,
    "RuntimePrimaryStagingBoundedMutatingSmokeTest passed: {$assertions} assertions.\n"
);
