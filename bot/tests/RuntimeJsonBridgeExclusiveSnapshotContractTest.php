<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$json = $read('storage/JsonDatabase.php');
$coordinator = $read('runtime/RuntimeBridgeProjectionCoordinator.php');
$snapshotStorage = $read('runtime/RuntimeBridgeSnapshotStorage.php');
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

// Keep the old reentrant exclusive snapshot primitive available for owners that
// genuinely need it. Runtime DB publication no longer uses it across SQL work.
$assert(
    str_contains($json, 'private ?array $exclusiveSnapshot = null;')
        && str_contains($json, 'if ($this->exclusiveSnapshot !== null)')
        && str_contains($json, '$this->snapshotSections($this->exclusiveSnapshot, $sections)'),
    'JsonDatabase must retain safe reentrant exclusive snapshots for non-bridge owners.'
);

$assert(
    str_contains($coordinator, "'/runtime-db-projection.lock'")
        && str_contains($coordinator, 'flock($handle, LOCK_EX)')
        && str_contains($coordinator, '$storage->readOnly(')
        && str_contains($coordinator, 'new RuntimeBridgeSnapshotStorage($snapshot)'),
    'Runtime DB projection must serialize on its own lock and copy JSON only through a short read lock.'
);
$assert(
    !str_contains($coordinator, 'exclusiveReadOnly('),
    'Runtime DB projection coordinator must never hold app.lock exclusively through SQL projection/parity.'
);
$assert(
    str_contains($snapshotStorage, 'final class RuntimeBridgeSnapshotStorage')
        && str_contains($snapshotStorage, 'Runtime bridge snapshot storage is read-only.')
        && str_contains($snapshotStorage, 'exclusiveReadOnly('),
    'Nested bridge repositories must consume one immutable in-memory snapshot without reopening live JSON.'
);

foreach ([
    'realtime' => $realtime,
    'economy' => $economy,
    'shop' => $shop,
    'payment' => $payment,
    'weekly' => $weekly,
] as $name => $source) {
    $assert(
        str_contains($source, 'RuntimeBridgeProjectionCoordinator::synchronize(')
            && !str_contains($source, 'exclusiveReadOnly('),
        ucfirst($name) . ' JSON→DB bridge must project outside the global JSON lock.'
    );
    $assert(
        str_contains($source, 'mgw_runtime_bridge_dirty'),
        ucfirst($name) . ' bridge must skip unchanged API domains rather than re-project every passive request.'
    );
}

$assert(
    str_contains($shop, 'RuntimeBridgeSnapshotStorage $frozen')
        && str_contains($shop, '$this->router,')
        && str_contains($shop, '$frozen'),
    'Shop nested repository must use the frozen bridge snapshot.'
);
$assert(
    str_contains($payment, 'RuntimeBridgeSnapshotStorage $frozen')
        && str_contains($payment, '$frozen'),
    'Payment nested repository must use the frozen bridge snapshot.'
);
$assert(
    str_contains($weekly, 'new RealtimeRuntimeBridge($this->config, $this->router, $frozen)')
        && str_contains($weekly, 'new RuntimeWeeklyBonusRepository(')
        && str_contains($weekly, '$frozen')
        && str_contains($weekly, '$notificationRepository->synchronizeAndList($snapshot, $legacyUserId)'),
    'Weekly bridge must keep realtime, weekly, economy and notification work on one frozen snapshot.'
);

$assert(
    str_contains($json, 'publishRuntimeBridgeDirty($before, $db)')
        && str_contains($json, "'realtime' =>")
        && str_contains($json, "'economy' =>")
        && str_contains($json, "'shop' =>")
        && str_contains($json, "'payments' =>")
        && str_contains($json, "'weekly_bonus' =>"),
    'JSON transaction must publish exact domain dirtiness before success hooks run.'
);

fwrite(STDOUT, "RuntimeJsonBridgeExclusiveSnapshotContractTest: {$assertions} assertions passed\n");
