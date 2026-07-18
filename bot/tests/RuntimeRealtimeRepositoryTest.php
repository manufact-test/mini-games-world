<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    '/storage/contracts/StorageTransactionInterface.php',
    '/storage/contracts/StorageAdapterInterface.php',
    '/database/DatabaseConnectionInterface.php',
    '/database/DatabaseConfig.php',
    '/database/PdoDatabaseConnection.php',
    '/database/PdoConnectionFactory.php',
    '/database/DatabaseMigrationInterface.php',
    '/database/MigrationRepository.php',
    '/database/MigrationRunner.php',
    '/storage/RuntimeStorageRouter.php',
    '/realtime/RealtimeDatabaseStore.php',
    '/realtime/RuntimeRealtimeRepository.php',
] as $file) require $root . $file;

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('pdo_sqlite is required.');
$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertThrows = static function (callable $callback, string $needle, string $message) use (&$assertions): void {
    $assertions++;
    try { $callback(); } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($needle))) return;
        throw new RuntimeException($message . ': ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

require __DIR__ . '/support/RuntimeRealtimeRepositoryFixture.php';
require __DIR__ . '/support/RuntimeRealtimeRepositoryScenario.php';
fwrite(STDOUT, "RuntimeRealtimeRepositoryTest: {$assertions} assertions passed\n");
