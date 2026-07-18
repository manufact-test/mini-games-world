<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/storage/StorageFactory.php';
require $root . '/realtime/RealtimeRuntimeBridge.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$base = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'not-a-secret-test-value',
        'charset' => 'utf8mb4',
    ],
];

$disabledConfig = $base;
$disabledConfig['feature_flags']['database_runtime'] = [
    'enabled' => true,
    'modules' => ['accounts' => true, 'realtime' => false],
];
$disabled = new RealtimeRuntimeBridge($disabledConfig, new RuntimeStorageRouter($disabledConfig));
$assertSame(false, $disabled->enabled(), 'Disabled bridge must stay inactive');
$assertSame(false, $disabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/api.php']), 'Disabled bridge must not attach');
$assertSame(null, $disabled->synchronizeCurrentJson(), 'Disabled bridge must not read or write storage');

$enabledConfig = $base;
$enabledConfig['feature_flags']['database_runtime'] = [
    'enabled' => true,
    'modules' => ['accounts' => true, 'realtime' => true],
];
$enabled = new RealtimeRuntimeBridge($enabledConfig, new RuntimeStorageRouter($enabledConfig));
$assertSame(true, $enabled->enabled(), 'Enabled bridge must be active');
$assertSame(true, $enabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/api.php']), 'Enabled bridge must attach to api.php');
$assertSame(true, $enabled->shouldAttachToCurrentRequest(['PHP_SELF' => '/bot/api.php']), 'PHP_SELF fallback must attach to api.php');
$assertSame(false, $enabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/invites.php']), 'Bridge must ignore other endpoints');
$assertSame(false, $enabled->shouldAttachToCurrentRequest([]), 'Bridge must ignore requests without a script path');

fwrite(STDOUT, "RealtimeRuntimeBridgeTest: {$assertions} assertions passed\n");
