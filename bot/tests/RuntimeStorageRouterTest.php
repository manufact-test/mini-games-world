<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/database/DatabaseConfig.php';
require $projectRoot . '/bot/storage/RuntimeStorageRouter.php';

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

$base = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'staging-db.example',
        'port' => 3306,
        'name' => 'mgw_staging',
        'user' => 'mgw_staging',
        'password' => 'staging-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [],
];

$disabled = new RuntimeStorageRouter($base);
$assertSame(false, $disabled->enabled(), 'Database runtime must default to disabled');
$assertSame('json', $disabled->routeFor('notifications'), 'Disabled router must keep JSON');
$assertSame([], $disabled->enabledModules(), 'Disabled router must expose no DB modules');
$assertSame('json', $disabled->publicStatus()['rollback_driver'], 'Rollback driver must remain JSON');

$enabledConfig = $base;
$enabledConfig['feature_flags']['database_runtime'] = [
    'enabled' => true,
    'modules' => [
        'accounts' => true,
        'notifications' => true,
        'invites' => 'enabled',
        'realtime' => false,
    ],
];
$enabled = new RuntimeStorageRouter($enabledConfig);
$assertSame(true, $enabled->enabled(), 'Staging router must enable explicitly');
$assertSame('database', $enabled->routeFor('accounts'), 'Account identity must route to DB first');
$assertSame('database', $enabled->routeFor('notifications'), 'Enabled module must route to DB');
$assertSame('database', $enabled->routeFor('invites'), 'Boolean-like module flag must route to DB');
$assertSame('json', $enabled->routeFor('realtime'), 'Disabled module must stay on JSON');
$assertSame(['accounts', 'invites', 'notifications'], $enabled->enabledModules(), 'Enabled modules must be stable and ordered');
$assertSame(false, $enabled->publicStatus()['production_allowed'], 'Production must remain forbidden');

$production = $enabledConfig;
$production['environment'] = 'production';
$assertThrows(
    static fn() => new RuntimeStorageRouter($production),
    'forbidden outside staging/local'
);

$databaseDisabled = $enabledConfig;
$databaseDisabled['database']['enabled'] = false;
$assertThrows(
    static fn() => new RuntimeStorageRouter($databaseDisabled),
    'requires an enabled database'
);

$wrongDriver = $enabledConfig;
$wrongDriver['storage_driver'] = 'database';
$assertThrows(
    static fn() => new RuntimeStorageRouter($wrongDriver),
    'must remain json'
);

$unknownModule = $enabledConfig;
$unknownModule['feature_flags']['database_runtime']['modules']['unknown'] = true;
$assertThrows(
    static fn() => new RuntimeStorageRouter($unknownModule),
    'unknown module'
);

$assertThrows(
    static fn() => $enabled->routeFor('unknown'),
    'unknown runtime storage module'
);

fwrite(STDOUT, "RuntimeStorageRouterTest: {$assertions} assertions passed\n");
