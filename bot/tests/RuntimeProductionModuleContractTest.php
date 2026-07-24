<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/RuntimeStorageRouter.php';
require $root . '/history/RuntimeHistoryRepository.php';
require $root . '/shop/RuntimeShopRepository.php';
require $root . '/payments/RuntimePaymentRepository.php';
require $root . '/weekly/RuntimeWeeklyBonusRepository.php';

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

$invokeGuard = static function (
    string $className,
    array $config,
    RuntimeStorageRouter $router
): void {
    $reflection = new ReflectionClass($className);
    $repository = $reflection->newInstanceWithoutConstructor();

    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);
    $configProperty->setValue($repository, $config);

    $routerProperty = $reflection->getProperty('router');
    $routerProperty->setAccessible(true);
    $routerProperty->setValue($repository, $router);

    $guard = $reflection->getMethod('assertDatabaseRoute');
    $guard->setAccessible(true);
    $guard->invoke($repository);
};

$modules = array_fill_keys([
    'accounts',
    'realtime',
    'invites',
    'notifications',
    'economy',
    'history',
    'shop',
    'payments',
    'weekly_bonus',
], true);

$productionConfig = [
    'environment' => 'production',
    'storage_driver' => RuntimeStorageRouter::DRIVER_JSON,
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => RuntimeStorageRouter::PRODUCTION_ACTIVATION_BUILD,
            'activation_plan_fingerprint' => str_repeat('a', 64),
            'activation_source_fingerprint' => str_repeat('b', 64),
            'activated_at_utc' => '2026-07-24T18:24:08+00:00',
            'rollback_driver' => RuntimeStorageRouter::DRIVER_JSON,
            'modules' => $modules,
        ],
    ],
];

$productionRouter = new RuntimeStorageRouter($productionConfig);
$assertSame(
    true,
    $productionRouter->publicStatus()['production_allowed'] ?? false,
    'Exact protected production activation must be allowed'
);

$repositories = [
    RuntimeHistoryRepository::class,
    RuntimeShopRepository::class,
    RuntimePaymentRepository::class,
    RuntimeWeeklyBonusRepository::class,
];

foreach ($repositories as $repositoryClass) {
    $invokeGuard($repositoryClass, $productionConfig, $productionRouter);
    $assertSame(true, true, $repositoryClass . ' must allow the exact protected production activation');
}

$stagingConfig = $productionConfig;
$stagingConfig['environment'] = 'staging';
$stagingRouter = new RuntimeStorageRouter($stagingConfig);
$assertSame(
    false,
    $stagingRouter->publicStatus()['production_allowed'] ?? true,
    'Staging router must not advertise protected production activation'
);

foreach ($repositories as $repositoryClass) {
    $invokeGuard($repositoryClass, $stagingConfig, $stagingRouter);
    $assertSame(true, true, $repositoryClass . ' must continue to allow staging DB runtime');
}

foreach ($repositories as $repositoryClass) {
    $blocked = false;

    try {
        $invokeGuard($repositoryClass, $productionConfig, $stagingRouter);
    } catch (RuntimeException $error) {
        $blocked = str_contains(
            $error->getMessage(),
            'exact protected production activation contract'
        );
    }

    $assertSame(
        true,
        $blocked,
        $repositoryClass . ' must fail closed without the protected production activation contract'
    );
}

fwrite(
    STDOUT,
    "RuntimeProductionModuleContractTest passed: {$assertions} assertions.\n"
);
