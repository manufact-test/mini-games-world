<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/ledger/LedgerIntegrity.php';
require_once $root . '/realtime/RuntimeRealtimeIdentityTrait.php';
require_once $root . '/realtime/RuntimeRealtimeValueTrait.php';
require_once $root . '/realtime/RuntimeRealtimeSourceTrait.php';
require_once $root . '/realtime/RuntimeRealtimeDatabaseTrait.php';
require_once $root . '/ledger/RuntimeEconomyRepository.php';
require_once $root . '/realtime/RuntimeRealtimeRepository.php';

final class RuntimeBridgeMemoFakeDatabase implements DatabaseConnectionInterface
{
    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int { throw new RuntimeException('not used'); }
    public function fetchAll(string $sql, array $parameters = []): array { throw new RuntimeException('not used'); }
    public function fetchValue(string $sql, array $parameters = []): mixed { throw new RuntimeException('not used'); }
    public function transaction(callable $callback): mixed { throw new RuntimeException('not used'); }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertNotSame = static function (mixed $left, mixed $right, string $message) use (&$assertions): void {
    $assertions++;
    if ($left === $right) {
        throw new RuntimeException($message . ': values unexpectedly matched');
    }
};

$economyReflection = new ReflectionClass(RuntimeEconomyRepository::class);
$economy = $economyReflection->newInstanceWithoutConstructor();
$economyKey = $economyReflection->getMethod('requestCacheKey');
$economyKey->setAccessible(true);

$realtimeReflection = new ReflectionClass(RuntimeRealtimeRepository::class);
$realtime = $realtimeReflection->newInstanceWithoutConstructor();
$realtimeKey = $realtimeReflection->getMethod('requestCacheKey');
$realtimeKey->setAccessible(true);

$db1 = new RuntimeBridgeMemoFakeDatabase();
$db2 = new RuntimeBridgeMemoFakeDatabase();

$base = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'telegram_id' => 'u1',
            'balance_match' => 100,
            'balance_gold' => 0,
            'last_seen_at' => '2026-08-09T10:00:00Z',
        ],
    ],
    'transactions' => [
        ['id' => 'tx1', 'user_id' => 'u1', 'amount' => 100],
    ],
    'games' => [
        'g1' => ['id' => 'g1', 'status' => 'active', 'player_ids' => ['u1', 'u2']],
    ],
    'queue' => [],
];

$economyBase = $economyKey->invoke($economy, $db1, $base);
$assertSame(
    $economyBase,
    $economyKey->invoke($economy, $db1, $base),
    'Identical economy source on the same request DB object must reuse one key'
);

$gameOnly = $base;
$gameOnly['games']['g1']['turn'] = 'u2';
$assertSame(
    $economyBase,
    $economyKey->invoke($economy, $db1, $gameOnly),
    'Game-only state must not invalidate the economy source key'
);

$metadataChanged = $base;
$metadataChanged['users']['u1']['last_seen_at'] = '2026-08-09T10:00:01Z';
$assertNotSame(
    $economyBase,
    $economyKey->invoke($economy, $db1, $metadataChanged),
    'Economy memo must preserve current full-user shadow semantics, including metadata changes'
);

$balanceChanged = $base;
$balanceChanged['users']['u1']['balance_match'] = 90;
$assertNotSame(
    $economyBase,
    $economyKey->invoke($economy, $db1, $balanceChanged),
    'Balance changes must invalidate the economy memo key'
);

$transactionChanged = $base;
$transactionChanged['transactions'][] = ['id' => 'tx2', 'user_id' => 'u1', 'amount' => -10];
$assertNotSame(
    $economyBase,
    $economyKey->invoke($economy, $db1, $transactionChanged),
    'Transaction changes must invalidate the economy memo key'
);

$assertNotSame(
    $economyBase,
    $economyKey->invoke($economy, $db2, $base),
    'A different DB connection object must never reuse an economy request memo key'
);

$realtimeBase = $realtimeKey->invoke($realtime, $db1, $base);
$assertSame(
    $realtimeBase,
    $realtimeKey->invoke($realtime, $db1, $base),
    'Identical realtime source on the same request DB object must reuse one key'
);

$userOnly = $base;
$userOnly['users']['u1']['last_seen_at'] = '2026-08-09T10:00:02Z';
$assertSame(
    $realtimeBase,
    $realtimeKey->invoke($realtime, $db1, $userOnly),
    'User-only state must not invalidate realtime projection memo'
);

$gameChanged = $base;
$gameChanged['games']['g1']['turn'] = 'u2';
$assertNotSame(
    $realtimeBase,
    $realtimeKey->invoke($realtime, $db1, $gameChanged),
    'Game changes must invalidate realtime projection memo'
);

$queueChanged = $base;
$queueChanged['queue'][] = ['id' => 'q1', 'user_id' => 'u1'];
$assertNotSame(
    $realtimeBase,
    $realtimeKey->invoke($realtime, $db1, $queueChanged),
    'Queue changes must invalidate realtime projection memo'
);

$assertNotSame(
    $realtimeBase,
    $realtimeKey->invoke($realtime, $db2, $base),
    'A different DB connection object must never reuse a realtime request memo key'
);

fwrite(STDOUT, "RuntimeBridgeRequestProjectionMemoTest: {$assertions} assertions passed\n");
