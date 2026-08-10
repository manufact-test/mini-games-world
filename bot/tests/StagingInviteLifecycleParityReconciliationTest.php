<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeDatabaseStore.php';
require $root . '/invites/RuntimeInviteRepository.php';

final class StagingInviteLifecycleFakeDatabase implements DatabaseConnectionInterface
{
    /** @var array<string, array{invite_id:string}> */
    private array $invites = [];

    /** @var array<string, int> */
    private array $relatedMatches = [];

    public function __construct(array $inviteIds, array $relatedMatches = [], private int $eventDeletes = 0)
    {
        foreach ($inviteIds as $inviteId) {
            $inviteId = (string)$inviteId;
            $this->invites[$inviteId] = ['invite_id' => $inviteId];
        }
        foreach ($relatedMatches as $inviteId => $count) {
            $this->relatedMatches[(string)$inviteId] = (int)$count;
        }
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        if (str_contains($sql, 'DELETE FROM mgw_invite_events')) {
            return $this->eventDeletes;
        }
        if (str_contains($sql, 'DELETE FROM mgw_invites')) {
            $inviteId = (string)($parameters['invite_id'] ?? '');
            if ($inviteId === '' || !isset($this->invites[$inviteId])) return 0;
            unset($this->invites[$inviteId]);
            return 1;
        }
        throw new RuntimeException('Unexpected execute SQL: ' . $sql);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'SELECT invite_id FROM mgw_invites')) {
            return array_values($this->invites);
        }
        if (str_contains($sql, 'SELECT * FROM mgw_invites ORDER BY invite_id')) {
            return array_values($this->invites);
        }
        throw new RuntimeException('Unexpected fetchAll SQL: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        if (str_contains($sql, 'SELECT GET_LOCK(')) return 1;
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) return 1;
        if (str_contains($sql, 'SELECT COUNT(*) FROM mgw_matches')) {
            $inviteId = (string)($parameters['invite_id'] ?? '');
            return $this->relatedMatches[$inviteId] ?? 0;
        }
        throw new RuntimeException('Unexpected fetchValue SQL: ' . $sql);
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this);
    }

    public function inviteCount(): int
    {
        return count($this->invites);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'notifications' => true,
                'invites' => true,
            ],
        ],
    ],
];
$router = new RuntimeStorageRouter($config);
$property = (new ReflectionClass(RuntimeInviteRepository::class))
    ->getProperty('reconcileDatabaseOnlyRows');
$property->setAccessible(true);

$liveRepository = new RuntimeInviteRepository($config, $router);
$assertSame(
    true,
    $property->getValue($liveRepository),
    'Live staging repository must own DB-only lifecycle reconciliation'
);

$injectedDatabase = new StagingInviteLifecycleFakeDatabase([]);
$injectedRepository = new RuntimeInviteRepository($config, $router, $injectedDatabase);
$assertSame(
    true,
    $property->getValue($injectedRepository),
    'Every staging repository view must recognize the same DB-only lifecycle history semantics'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$productionRepository = new RuntimeInviteRepository($productionConfig, $router, $injectedDatabase);
$assertSame(
    false,
    $property->getValue($productionRepository),
    'Production repositories must not inherit staging lifecycle reconciliation implicitly'
);

$staleDatabase = new StagingInviteLifecycleFakeDatabase(['stale-invite'], [], 2);
$staleRepository = new RuntimeInviteRepository($config, $router, $staleDatabase, true);
$staleReport = $staleRepository->synchronize(['invites' => []]);
$assertSame(true, $staleReport['parity'], 'Unreferenced DB-only invite must be reconciled to parity');
$assertSame(1, $staleReport['pruned_invite_rows'], 'Exactly one stale DB-only invite must be removed');
$assertSame(2, $staleReport['pruned_invite_event_rows'], 'Dependent invite events must be removed first');
$assertSame(0, $staleReport['database_count'], 'Active invite DB count must match empty JSON after cleanup');
$assertSame(0, $staleDatabase->inviteCount(), 'Unreferenced stale invite must be absent after synchronization');

$historicalDatabase = new StagingInviteLifecycleFakeDatabase(
    ['historical-invite'],
    ['historical-invite' => 1]
);
$historicalRepository = new RuntimeInviteRepository($config, $router, $historicalDatabase, true);
$historicalReport = $historicalRepository->synchronize(['invites' => []]);
$historicalAudit = $historicalRepository->auditParity(['invites' => []]);
$assertSame(true, $historicalReport['parity'], 'Match-referenced invite must not block active lifecycle parity');
$assertSame(1, $historicalReport['preserved_historical_invite_rows'], 'Historical invite must be reported as preserved');
$assertSame(0, $historicalReport['pruned_invite_rows'], 'Historical invite must never be deleted');
$assertSame(1, $historicalDatabase->inviteCount(), 'Historical invite row must remain in DB');
$assertSame(true, $historicalAudit['ok'], 'Read-only parity audit must ignore preserved match history');
$assertSame(1, $historicalAudit['preserved_historical_invite_rows'], 'Audit must report preserved historical row');

$source = file_get_contents($root . '/invites/RuntimeInviteRepository.php') ?: '';
$assertContains("?? ((\$config['environment'] ?? null) === 'staging');", $source, 'Staging lifecycle semantics must be environment-scoped');
$assertContains('SELECT COUNT(*) FROM mgw_matches WHERE invite_id = :invite_id', $source, 'Match references must protect historical rows');
$assertContains('DELETE FROM mgw_invite_events WHERE invite_id = :invite_id', $source, 'Dependent invite events must be pruned first');
$assertContains('DELETE FROM mgw_invites WHERE invite_id = :invite_id', $source, 'Only DB-only invite rows may be pruned');

fwrite(STDOUT, "StagingInviteLifecycleParityReconciliationTest: {$assertions} assertions passed\n");
