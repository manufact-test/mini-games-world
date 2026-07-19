<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/storage/StorageFactory.php';
require $root . '/ledger/EconomyRuntimeBridge.php';

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
    'modules' => ['accounts' => true, 'economy' => false],
];
$disabled = new EconomyRuntimeBridge($disabledConfig, new RuntimeStorageRouter($disabledConfig));
$assertSame(false, $disabled->enabled(), 'Disabled economy bridge must stay inactive');
$assertSame(false, $disabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/api.php']), 'Disabled bridge must not attach');
$assertSame(null, $disabled->synchronizeCurrentJson(), 'Disabled bridge must not touch storage');

$enabledConfig = $base;
$enabledConfig['feature_flags']['database_runtime'] = [
    'enabled' => true,
    'modules' => ['accounts' => true, 'economy' => true],
];
$enabled = new EconomyRuntimeBridge($enabledConfig, new RuntimeStorageRouter($enabledConfig));
$assertSame(true, $enabled->enabled(), 'Enabled economy bridge must be active');
$assertSame(true, $enabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/api.php']), 'Economy bridge must attach to api.php');
$assertSame(true, $enabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/webhook.php']), 'Economy bridge must attach to webhook.php');
$assertSame(true, $enabled->shouldAttachToCurrentRequest(['PHP_SELF' => '/bot/api.php']), 'PHP_SELF fallback must attach to api.php');
$assertSame(false, $enabled->shouldAttachToCurrentRequest(['SCRIPT_FILENAME' => '/srv/bot/health.php']), 'Economy bridge must ignore read-only health');
$assertSame(false, $enabled->shouldAttachToCurrentRequest([]), 'Economy bridge must ignore requests without a script path');

fwrite(STDOUT, "EconomyRuntimeBridgeTest: {$assertions} assertions passed\n");
