<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseDir = $root . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/DatabaseConfig.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/accounts/AccountIdentityService.php';
require $root . '/accounts/RuntimeAccountOwnershipService.php';
require $root . '/accounts/RuntimeAccountIdentityResolver.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('RuntimeAccountIdentityResolverTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(7, $migration['executed_count'], 'Account routing test schema must include all migrations');

$baseConfig = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'mgw_account_session_ttl_sec' => 3600,
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
];

$legacy = new RuntimeAccountIdentityResolver($baseConfig, null, $database);
$legacyUser = $legacy->attach([
    'id' => 'telegram-legacy',
    'first_name' => 'Legacy route',
    'username' => 'legacy_route',
], 'session-legacy');
$assertTrue(MgwIdGenerator::isValid((string)($legacyUser['mgw_id'] ?? '')), 'Router-disabled compatibility must preserve DB account identity');
$assertSame('telegram', $legacyUser['mgw_identity_provider'] ?? null, 'Compatibility route must preserve identity provider');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Compatibility route must create one account');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), 'Compatibility route must not silently change ownership behavior');

$jsonConfig = $baseConfig;
$jsonConfig['feature_flags'] = [
    'database_runtime' => [
        'enabled' => true,
        'modules' => ['accounts' => false],
    ],
];
$jsonResolver = new RuntimeAccountIdentityResolver($jsonConfig, null, $database);
$jsonUser = $jsonResolver->attach([
    'id' => 'telegram-json',
    'first_name' => 'JSON route',
], 'session-json');
$assertSame(false, array_key_exists('mgw_id', $jsonUser), 'Explicit JSON account route must not write normalized identity');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'JSON account route must leave DB accounts unchanged');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), 'JSON account route must leave ownership unchanged');

$dbConfig = $baseConfig;
$dbConfig['feature_flags'] = [
    'database_runtime' => [
        'enabled' => true,
        'modules' => ['accounts' => true],
    ],
];
$dbResolver = new RuntimeAccountIdentityResolver($dbConfig, null, $database);
$dbUser = $dbResolver->attach([
    'id' => 'telegram-database',
    'first_name' => 'Database route',
    'username' => 'database_route',
], 'session-database');
$assertTrue(MgwIdGenerator::isValid((string)($dbUser['mgw_id'] ?? '')), 'Enabled account DB route must attach MGW identity');
$assertSame('legacy:telegram-database', $dbUser['mgw_account_ref'] ?? null, 'Enabled account DB route must attach stable ownership');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Enabled account DB route must create exactly one account');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), 'Enabled account DB route must create exactly one ownership row');
$assertSame(
    (string)$dbUser['mgw_id'],
    (string)$database->fetchValue(
        'SELECT mgw_id FROM mgw_account_ownership WHERE legacy_user_id = :legacy_user_id',
        ['legacy_user_id' => 'telegram-database']
    ),
    'Runtime ownership must point to the authenticated MGW account'
);
$repeatDbUser = $dbResolver->attach([
    'id' => 'telegram-database',
    'first_name' => 'Database route updated',
    'username' => 'database_route',
], 'session-database-repeat');
$assertSame($dbUser['mgw_id'], $repeatDbUser['mgw_id'], 'Repeated DB route must preserve MGW identity');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), 'Repeated DB route must not duplicate ownership');
$assertSame('database', (new RuntimeStorageRouter($dbConfig))->routeFor('accounts'), 'Router must report account database route');

$invalidDependency = $baseConfig;
$invalidDependency['feature_flags'] = [
    'database_runtime' => [
        'enabled' => true,
        'modules' => [
            'accounts' => false,
            'notifications' => true,
        ],
    ],
];
$assertThrows(
    static fn() => new RuntimeStorageRouter($invalidDependency),
    'require accounts routing',
    'Every normalized DB module must require stable account identity'
);

fwrite(STDOUT, "RuntimeAccountIdentityResolverTest: {$assertions} assertions passed\n");
