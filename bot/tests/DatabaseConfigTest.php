<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database/DatabaseConfig.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$disabled = DatabaseConfig::fromApplicationConfig([]);
$assertSame(false, $disabled->enabled(), 'Database must be disabled by default');
$assertSame('mysql', $disabled->driver(), 'Default private database driver must be mysql');
$assertSame(false, $disabled->safeSummary()['configured'], 'Disabled database must not be reported as configured');
$assertSame(false, $disabled->safeSummary()['identity_configured'], 'Empty database identity must not be reported as configured');
$assertSame('', $disabled->identityFingerprint(), 'Unconfigured database must have no identity fingerprint');

$reserved = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => false,
        'driver' => 'mysql',
        'host' => 'prod-db.internal',
        'port' => 3306,
        'name' => 'mgw_production',
        'charset' => 'utf8mb4',
    ],
]);
$assertSame(false, $reserved->enabled(), 'Reserved production identity must stay disabled');
$assertSame(false, $reserved->safeSummary()['configured'], 'Reserved identity without credentials must not be connection-ready');
$assertSame(true, $reserved->safeSummary()['identity_configured'], 'Reserved identity must be reported safely');
$assertSame(64, strlen($reserved->identityFingerprint()), 'Reserved identity must produce a protected fingerprint');

$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mariadb',
        'host' => 'db.internal',
        'port' => 3307,
        'name' => 'mgw_stage',
        'user' => 'mgw_user',
        'password' => 'super-private-password',
        'charset' => 'utf8mb4',
    ],
]);
$assertSame(true, $config->enabled(), 'Enabled database must be recognized');
$assertSame('mysql', $config->driver(), 'MariaDB must use the PDO mysql driver');
$assertSame('mysql:host=db.internal;port=3307;dbname=mgw_stage;charset=utf8mb4', $config->dsn(), 'DSN must be deterministic');
$assertSame('mgw_user', $config->user(), 'Database user must be available to the PDO factory');
$assertSame('super-private-password', $config->password(), 'Database password must be available only to the PDO factory');
$expectedFingerprint = hash('sha256', json_encode([
    'dsn' => '',
    'host' => 'db.internal',
    'port' => '3307',
    'name' => 'mgw_stage',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$assertSame($expectedFingerprint, $config->identityFingerprint(), 'Database identity fingerprint must be deterministic');
$assertTrue(!str_contains(json_encode($config->safeSummary(), JSON_THROW_ON_ERROR), 'super-private-password'), 'Safe summary must not expose the password');
$assertTrue(!str_contains($config->dsn(), 'super-private-password'), 'DSN must not contain the password');
$assertTrue(!str_contains($config->identityFingerprint(), 'super-private-password'), 'Identity fingerprint must not expose the password');

$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => ['enabled' => true]]),
    'incomplete',
    'Incomplete enabled database config must fail'
);
$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => [
        'enabled' => false,
        'host' => 'reserved-db.internal',
    ]]),
    'identity configuration is incomplete',
    'Incomplete disabled database identity must fail'
);
$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => [
        'enabled' => true,
        'host' => 'db.internal;dbname=other',
        'name' => 'mgw',
        'user' => 'user',
        'password' => 'pass',
    ]]),
    'host contains unsupported characters',
    'Database host must not inject extra DSN parameters'
);
$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => [
        'enabled' => true,
        'host' => 'db',
        'name' => 'mgw',
        'user' => 'user',
        'password' => 'pass',
        'charset' => 'latin1',
    ]]),
    'utf8mb4',
    'Non-utf8mb4 database config must fail'
);
$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => ['driver' => 'pgsql']]),
    'Unsupported database driver',
    'Unsupported database driver must fail even before cutover'
);
$assertThrows(
    static fn() => DatabaseConfig::fromApplicationConfig(['database' => [
        'enabled' => true,
        'host' => 'db',
        'port' => 70000,
        'name' => 'mgw',
        'user' => 'user',
        'password' => 'pass',
    ]]),
    'port',
    'Invalid database port must fail'
);

fwrite(STDOUT, "DatabaseConfigTest: {$assertions} assertions passed\n");
