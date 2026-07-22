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

final class RollbackGuardStore
{
    public function __construct(public array $state, public int $revision, public array $outbox) {}
}
final class DatabasePrimaryStateStorageAdapter
{
    public const DRIVER = 'database';
    public function __construct(private RollbackGuardStore $store) {}
    public function driver(): string { return self::DRIVER; }
    public function status(): array
    {
        return [
            'ok' => true,
            'driver' => self::DRIVER,
            'revision' => $this->store->revision,
            'state_sha256' => RollbackGuardFixture::sha($this->store->state),
            'projection_outbox_enabled' => true,
        ];
    }
    public function readOnly(callable $callback): mixed { return $callback($this->store->state); }
}
final class RollbackGuardDatabase implements DatabaseConnectionInterface
{
    public function __construct(private RollbackGuardStore $store) {}
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int { return 0; }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $limit = (int)($parameters['state_revision'] ?? 0);
        return array_values(array_filter(
            $this->store->outbox,
            static fn(array $row): bool => (int)$row['state_revision'] <= $limit
        ));
    }
}
final class RollbackGuardAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        return [
            'ok' => true,
            'parity_ok' => true,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => RollbackGuardFixture::MODULES,
            'all_module_fingerprint' => hash('sha256', RollbackGuardFixture::canonicalJson([
                'revision' => $stateRevision,
                'sha' => $stateSha256,
                'modules' => RollbackGuardFixture::MODULES,
            ])),
        ];
    }
}
final class RollbackGuardFixture
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
        return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function sha(array $state): string { return hash('sha256', self::canonicalJson($state)); }
    public static function event(int $revision, string $sha): array
    {
        return [
            'state_revision' => $revision,
            'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
            'state_sha256' => $sha,
            'status' => 'completed',
            'attempt_count' => 1,
            'lease_token' => '',
            'lease_expires_at_utc' => '',
            'last_error' => '',
            'available_at_utc' => '2026-07-22T17:00:00+00:00',
            'created_at_utc' => '2026-07-22T17:00:00+00:00',
            'updated_at_utc' => '2026-07-22T17:00:00+00:00',
        ];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingMutatingSmokeRollbackGuard.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try { $callback(); } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$state = ['users' => [], 'games' => [], 'system' => ['sequence' => 1]];
$sha = RollbackGuardFixture::sha($state);
$store = new RollbackGuardStore($state, 1, [RollbackGuardFixture::event(1, $sha)]);
$guard = new RuntimePrimaryStagingMutatingSmokeRollbackGuard(
    new DatabasePrimaryStateStorageAdapter($store),
    new RollbackGuardDatabase($store),
    new RollbackGuardAuditor()
);
$baseline = $guard->capture();
$assertTrue(($baseline['state_revision'] ?? 0) === 1, 'Rollback guard must capture revision');
$assertTrue(($baseline['state_sha256'] ?? '') === $sha, 'Rollback guard must capture state SHA');
$assertTrue(($baseline['outbox_event_count'] ?? 0) === 1, 'Rollback guard must capture outbox count');
$verified = $guard->assertRestored($baseline);
$assertTrue(($verified['ok'] ?? false) === true, 'Unchanged baseline must verify');
$assertTrue(($verified['committed_state_write_count'] ?? -1) === 0, 'Verified baseline must prove zero committed writes');

$store->state['system']['sequence'] = 2;
$assertThrows(static fn() => $guard->assertRestored($baseline), 'restoration failed: state_sha256');
$store->state = $state;
$store->outbox[0]['attempt_count'] = 2;
$assertThrows(static fn() => $guard->assertRestored($baseline), 'restoration failed: outbox_fingerprint');
$store->outbox[0]['attempt_count'] = 1;
$store->outbox[0]['status'] = 'pending';
$assertThrows(static fn() => $guard->capture(), 'not cleanly completed');

fwrite(STDOUT, "RuntimePrimaryStagingMutatingSmokeRollbackGuardTest passed: {$assertions} assertions.\n");
