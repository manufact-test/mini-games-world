<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Environment.php';
require_once dirname(__DIR__) . '/core/ConfigValidator.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$base = [
    'environment' => 'staging',
    'base_url' => 'https://staging.example.com',
    'allowed_hosts' => ['staging.example.com'],
    'bot_token' => 'staging-token-value-for-database-test',
    'staging_bot_username' => 'mgw_test_bot',
    'data_dir' => '/srv/mgw_staging_data',
    'storage_driver' => 'json',
    'environment_guard' => [
        'production_hosts' => ['play.example.com'],
        'production_data_dir' => '/srv/mgw_data',
    ],
];

$disabled = $base;
$disabled['database'] = [
    'enabled' => false,
    'driver' => 'mysql',
    'host' => 'PRIVATE_HOST',
    'port' => 3306,
    'name' => 'PRIVATE_DATABASE_NAME',
    'user' => 'PRIVATE_DATABASE_USER',
    'password' => 'PRIVATE_DATABASE_PASSWORD',
    'charset' => 'utf8mb4',
];
$validatedDisabled = ConfigValidator::validate($disabled, ['HTTP_HOST' => 'staging.example.com']);
$assertTrue($validatedDisabled['environment'] === 'staging', 'Disabled database metadata must not require a production fingerprint');

$database = [
    'enabled' => true,
    'driver' => 'mysql',
    'host' => 'staging-db.internal',
    // Deliberately omit port: the canonical default 3306 must still participate
    // in the isolation fingerprint.
    'name' => 'mgw_staging',
    'user' => 'mgw_staging_user',
    'password' => 'private-staging-password',
    'charset' => 'utf8mb4',
];

$missingFingerprint = $base;
$missingFingerprint['database'] = $database;
$assertThrows(
    static fn() => ConfigValidator::validate($missingFingerprint),
    'protected production database fingerprint',
    'Enabled staging database must require protected production metadata'
);

$identity = [
    'dsn' => '',
    'host' => strtolower($database['host']),
    'port' => '3306',
    'name' => $database['name'],
];
$fingerprint = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$matching = $base;
$matching['database'] = $database;
$matching['environment_guard']['production_database_sha256'] = $fingerprint;
$assertThrows(
    static fn() => ConfigValidator::validate($matching),
    'database matches production',
    'Staging database fingerprint must match the canonical default port'
);

$isolated = $base;
$isolated['database'] = $database;
$isolated['environment_guard']['production_database_sha256'] = hash('sha256', 'different-production-database');
$validated = ConfigValidator::validate($isolated, ['HTTP_HOST' => 'staging.example.com']);
$assertTrue($validated['environment'] === 'staging', 'Isolated staging database config must pass');

$wrongStorage = $base;
$wrongStorage['storage_driver'] = 'mysql';
$assertThrows(
    static fn() => ConfigValidator::validate($wrongStorage),
    'before the database cutover',
    'MySQL storage driver must remain blocked before MVP-14.8'
);

fwrite(STDOUT, "DatabaseEnvironmentGuardTest: {$assertions} assertions passed\n");
