<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$json = $read('storage/JsonDatabase.php');
$realtime = $read('realtime/RealtimeRuntimeBridge.php');
$economy = $read('ledger/EconomyRuntimeBridge.php');
$shop = $read('shop/ShopRuntimeBridge.php');
$payment = $read('payments/PaymentRuntimeBridge.php');
$weekly = $read('weekly/WeeklyBonusRuntimeBridge.php');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($json, 'private ?array $exclusiveSnapshot = null;')
        && str_contains($json, 'if ($this->exclusiveSnapshot !== null)')
        && str_contains($json, '$this->snapshotSections($this->exclusiveSnapshot, $sections)'),
    'JsonDatabase must reuse an active exclusive snapshot for nested reads instead of reacquiring app.lock.'
);
$assert(
    str_contains($json, '$this->exclusiveSnapshot = $snapshot;')
        && str_contains($json, 'finally {')
        && str_contains($json, '$this->exclusiveSnapshot = null;'),
    'JsonDatabase must clear the reentrant snapshot even when an external bridge throws.'
);

foreach ([
    'realtime' => $realtime,
    'economy' => $economy,
    'shop' => $shop,
    'payment' => $payment,
    'weekly' => $weekly,
] as $name => $source) {
    $assert(
        str_contains($source, 'instanceof ExclusiveSnapshotStorageInterface')
            && str_contains($source, 'exclusiveReadOnly('),
        ucfirst($name) . ' JSON→DB bridge must hold the exclusive source snapshot through projection/parity.'
    );
}

$assert(
    str_contains($shop, 'new RuntimeShopRepository(')
        && str_contains($shop, '$storage')
        && str_contains($shop, 'return $repository->synchronizeCurrentJson();'),
    'Shop bridge must inject the same locked storage adapter into nested repository reads.'
);
$assert(
    str_contains($payment, 'new RuntimePaymentRepository($this->config, $this->router, $storage)')
        && str_contains($payment, 'return $repository->synchronizeCurrentJson();'),
    'Payment bridge must inject the same locked storage adapter into nested repository reads.'
);
$assert(
    str_contains($weekly, 'new RealtimeRuntimeBridge($this->config, $this->router, $storage)')
        && str_contains($weekly, 'new RuntimeWeeklyBonusRepository(')
        && str_contains($weekly, '$snapshot')
        && str_contains($weekly, '$notificationRepository->synchronizeAndList($snapshot, $legacyUserId)'),
    'Weekly bridge must keep realtime, weekly, economy-shadow and notification checks on one frozen JSON snapshot.'
);

fwrite(STDOUT, "RuntimeJsonBridgeExclusiveSnapshotContractTest: {$assertions} assertions passed\n");
