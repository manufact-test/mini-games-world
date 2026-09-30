<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/helpers/validators.php';
require_once $root . '/database/DatabaseConnectionInterface.php';
require_once $root . '/database/PdoDatabaseConnection.php';
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/storage/RuntimeStorageRouter.php';
require_once $root . '/economy/UnifiedBalanceMigrationRule.php';
require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/services/UserService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Mvp26_3_9PrepaintRuntimeEnsureTest requires pdo_sqlite.');
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

$config = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'initial_match_coins' => 0,
    'initial_gold_coins' => 0,
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-only-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'economy' => true,
            ],
        ],
    ],
];

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database = new PdoDatabaseConnection($pdo);
$database->execute(
    'CREATE TABLE mgw_idempotency_keys (
        operation_type TEXT NOT NULL,
        status TEXT NOT NULL
    )'
);
$database->execute(
    'CREATE TABLE mgw_balances (
        account_ref TEXT NOT NULL,
        mgw_id TEXT NOT NULL,
        legacy_user_id TEXT NOT NULL,
        asset_code TEXT NOT NULL,
        available_amount INTEGER NOT NULL,
        reserved_amount INTEGER NOT NULL
    )'
);
$database->execute(
    "INSERT INTO mgw_idempotency_keys (operation_type, status)
     VALUES ('unified_balance_cutover', 'completed')"
);

$legacyUserId = '777001';
$mgwId = 'MGW-1234567890ABCDEF';
$accountRef = 'legacy:' . $legacyUserId;
$database->execute(
    'INSERT INTO mgw_balances (
        account_ref, mgw_id, legacy_user_id, asset_code,
        available_amount, reserved_amount
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :asset_code,
        :available_amount, 0
     )',
    [
        'account_ref' => $accountRef,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $legacyUserId,
        'asset_code' => UnifiedBalanceMigrationRule::TARGET_ASSET,
        'available_amount' => 4321,
    ]
);

$authenticatedUser = [
    'id' => $legacyUserId,
    'first_name' => 'Linked Player',
    'username' => 'Linked Player',
    'mgw_id' => $mgwId,
    'mgw_account_ref' => $accountRef,
    'mgw_identity_provider' => 'android_device',
    'mgw_nickname' => 'Linked Player',
];

$service = new UserService($config, $database);

// Case 1: target runtime row exists, but the unified balance field is missing.
// This is the post-link/cold-start shape that must converge before first paint.
$missingField = [
    'users' => [
        $legacyUserId => [
            'id' => $legacyUserId,
            'mgw_id' => $mgwId,
            'mgw_account_ref' => $accountRef,
            'first_name' => 'Linked Player',
            'username' => '',
            'last_seen_at' => now_iso(),
        ],
    ],
];
$hydrated = $service->ensureUser($missingField, $authenticatedUser);
$assertSame(4321, (int)$hydrated['balance'], 'Missing runtime balance must rehydrate from canonical post-cutover balance');
$assertSame(4321, (int)$missingField['users'][$legacyUserId]['balance'], 'Rehydrated balance must persist into runtime snapshot');

// Case 2: target runtime row is completely absent. The canonical ensureUser
// path may create it, then restore the verified canonical balance.
$missingUser = ['users' => []];
$created = $service->ensureUser($missingUser, $authenticatedUser);
$assertSame(4321, (int)$created['balance'], 'Absent linked target runtime user must be created with canonical balance');
$assertSame($mgwId, (string)$missingUser['users'][$legacyUserId]['mgw_id'], 'Created runtime user must keep target MGW owner');

// Case 3: an explicit runtime zero is authoritative mutable state. It must not
// be overwritten merely because canonical DB currently contains a larger amount.
$explicitZero = [
    'users' => [
        $legacyUserId => [
            'id' => $legacyUserId,
            'mgw_id' => $mgwId,
            'mgw_account_ref' => $accountRef,
            'first_name' => 'Linked Player',
            'username' => '',
            'last_seen_at' => now_iso(),
            'balance' => 0,
        ],
    ],
];
$preserved = $service->ensureUser($explicitZero, $authenticatedUser);
$assertSame(0, (int)$preserved['balance'], 'Explicit runtime zero must remain zero');
$assertSame(0, (int)$explicitZero['users'][$legacyUserId]['balance'], 'Explicit zero must remain persisted in runtime');

fwrite(STDOUT, "Mvp26_3_9PrepaintRuntimeEnsureTest: {$assertions} assertions passed\n");
