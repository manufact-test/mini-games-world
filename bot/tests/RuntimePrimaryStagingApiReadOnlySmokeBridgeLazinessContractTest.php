<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$paths = [
    'realtime' => 'bot/realtime/RealtimeRuntimeBridge.php',
    'economy' => 'bot/ledger/EconomyRuntimeBridge.php',
    'shop' => 'bot/shop/ShopRuntimeBridge.php',
    'payments' => 'bot/payments/PaymentRuntimeBridge.php',
    'weekly_bonus' => 'bot/weekly/WeeklyBonusRuntimeBridge.php',
];

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ($paths as $module => $relative) {
    $source = file_get_contents($projectRoot . '/' . $relative);
    if (!is_string($source)) {
        throw new RuntimeException('Runtime bridge source is unavailable: ' . $module . '.');
    }
    $constructorStart = strpos($source, 'public function __construct(');
    $syncStart = strpos($source, 'public function synchronizeCurrentJson(');
    $assertTrue(
        $constructorStart !== false && $syncStart !== false && $constructorStart < $syncStart,
        'Runtime bridge must declare constructor before lazy synchronization: ' . $module
    );
    $constructorBlock = substr($source, $constructorStart, $syncStart - $constructorStart);
    $assertTrue(
        !str_contains($constructorBlock, 'StorageFactory::create(')
            && !str_contains($constructorBlock, 'StorageFactory::createJson(')
            && !str_contains($constructorBlock, 'PdoConnectionFactory::create('),
        'Runtime bridge constructor must not resolve storage or open DB: ' . $module
    );
    $factoryPosition = strpos($source, 'StorageFactory::create');
    if ($factoryPosition !== false) {
        $assertTrue(
            $factoryPosition > $syncStart,
            'Runtime bridge storage resolution must remain inside lazy synchronization: ' . $module
        );
    } else {
        $assertTrue(
            str_contains($source, 'repository()->synchronizeCurrentJson()')
                || str_contains($source, 'repository->synchronizeCurrentJson()'),
            'Runtime bridge without direct factory must remain repository-lazy: ' . $module
        );
    }
}

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeBridgeLazinessContractTest passed: {$assertions} assertions.\n");
