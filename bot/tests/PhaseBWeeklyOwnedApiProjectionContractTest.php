<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/realtime/RealtimeRuntimeBridge.php';
require $root . '/ledger/EconomyRuntimeBridge.php';
require $root . '/weekly/WeeklyBonusRuntimeBridge.php';

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};

$baseModules = [
    'accounts' => true,
    'realtime' => true,
    'invites' => false,
    'notifications' => false,
    'economy' => true,
    'history' => true,
    'shop' => false,
    'payments' => false,
    'weekly_bonus' => true,
];
$baseConfig = [
    'environment' => 'staging',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mgw_contract',
        'user' => 'mgw_contract',
        'password' => 'contract-password',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => $baseModules,
        ],
    ],
];

$weeklyRouter = new RuntimeStorageRouter($baseConfig);
$weeklyRealtime = new RealtimeRuntimeBridge($baseConfig, $weeklyRouter);
$weeklyEconomy = new EconomyRuntimeBridge($baseConfig, $weeklyRouter);
$weeklyBridge = new WeeklyBonusRuntimeBridge($baseConfig, $weeklyRouter);
$apiServer = ['SCRIPT_FILENAME' => '/srv/mgw/bot/api.php'];
$webhookServer = ['SCRIPT_FILENAME' => '/srv/mgw/bot/webhook.php'];

$assertSame(true, $weeklyRealtime->enabled(), 'Realtime must remain DB-routed when Weekly owns API projection');
$assertSame(true, $weeklyEconomy->enabled(), 'Economy must remain DB-routed when Weekly owns API projection');
$assertSame(false, $weeklyRealtime->shouldAttachToCurrentRequest($apiServer), 'Realtime top-level API hook must be suppressed while Weekly owns it');
$assertSame(false, $weeklyEconomy->shouldAttachToCurrentRequest($apiServer), 'Economy top-level API hook must be suppressed while Weekly owns it');
$assertSame(true, $weeklyEconomy->shouldAttachToCurrentRequest($webhookServer), 'Economy webhook ownership must remain unchanged');
$assertSame(true, $weeklyBridge->shouldAttachToCurrentRequest($apiServer), 'Weekly must remain the API projection owner');
$assertSame(true, $weeklyBridge->shouldSynchronizeApiAction('profile'), 'Weekly must still synchronize profile requests');
$assertSame(true, $weeklyBridge->shouldSynchronizeApiAction('game_state'), 'Weekly must still synchronize game_state requests');
$assertSame(true, $weeklyBridge->shouldSynchronizeApiAction('game_action'), 'Weekly must still synchronize game_action requests');

$withoutWeekly = $baseConfig;
$withoutWeekly['feature_flags']['database_runtime']['modules']['weekly_bonus'] = false;
$withoutWeeklyRouter = new RuntimeStorageRouter($withoutWeekly);
$withoutWeeklyRealtime = new RealtimeRuntimeBridge($withoutWeekly, $withoutWeeklyRouter);
$withoutWeeklyEconomy = new EconomyRuntimeBridge($withoutWeekly, $withoutWeeklyRouter);
$assertSame(true, $withoutWeeklyRealtime->shouldAttachToCurrentRequest($apiServer), 'Realtime API hook must return when Weekly is not DB-routed');
$assertSame(true, $withoutWeeklyEconomy->shouldAttachToCurrentRequest($apiServer), 'Economy API hook must return when Weekly is not DB-routed');
$assertSame(true, $withoutWeeklyEconomy->shouldAttachToCurrentRequest($webhookServer), 'Economy webhook must stay attached without Weekly');

$weeklyBridgeSource = (string)file_get_contents($root . '/weekly/WeeklyBonusRuntimeBridge.php');
$weeklyRepositorySource = (string)file_get_contents($root . '/weekly/RuntimeWeeklyBonusRepository.php');
$assertContains(
    'new RealtimeRuntimeBridge(',
    $weeklyBridgeSource,
    'Weekly bridge must directly synchronize the realtime dependency inside its frozen snapshot'
);
$assertContains(
    'new RuntimeEconomyRepository(',
    $weeklyRepositorySource,
    'Weekly repository must directly synchronize/audit the economy dependency'
);
$assertContains(
    'exclusiveReadOnly(',
    $weeklyBridgeSource,
    'Weekly dependency ownership must remain inside the exclusive JSON snapshot boundary'
);

fwrite(STDOUT, "PhaseBWeeklyOwnedApiProjectionContractTest passed: {$assertions} assertions.\n");
