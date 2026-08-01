<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bot/storage/RuntimeStorageRouter.php';

$modules = [
    'accounts',
    'realtime',
    'invites',
    'notifications',
    'economy',
    'history',
    'shop',
    'payments',
    'weekly_bonus',
];

$config = [
    'environment' => 'production',
    'storage_driver' => 'json',
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => RuntimeStorageRouter::PRODUCTION_ACTIVATION_BUILD,
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'activation_plan_fingerprint' => str_repeat('a', 64),
            'activation_source_fingerprint' => str_repeat('b', 64),
            'modules' => array_fill_keys($modules, true),
        ],
    ],
];

$router = new RuntimeStorageRouter($config);
if ($router->enabled()) {
    throw new RuntimeException('Production DB runtime must be disabled during emergency JSON failback.');
}
foreach ($modules as $module) {
    if ($router->routeFor($module) !== RuntimeStorageRouter::DRIVER_JSON) {
        throw new RuntimeException('Module did not fail back to JSON: ' . $module);
    }
}

$status = $router->publicStatus();
if (($status['emergency_json_failback'] ?? false) !== true) {
    throw new RuntimeException('Public runtime status must expose emergency JSON failback.');
}
if (($status['enabled_modules'] ?? null) !== []) {
    throw new RuntimeException('No DB runtime modules may remain enabled during failback.');
}

$health = file_get_contents($root . '/bot/health.php');
if (!is_string($health)
    || !str_contains($health, '$databaseRuntimeRequired = $runtimeStorageRouter->enabledModules() !== [];')
    || !str_contains($health, '$databaseReady = !$databaseRuntimeRequired')) {
    throw new RuntimeException('Health must treat MySQL as optional when runtime modules use JSON.');
}

fwrite(STDOUT, "ProductionJsonFailbackContractTest: passed\n");
