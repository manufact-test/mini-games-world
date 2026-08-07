<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bridge = file_get_contents($root . '/realtime/RealtimeRuntimeBridge.php');
$database = file_get_contents($root . '/storage/JsonDatabase.php');
$weekly = file_get_contents($root . '/weekly/WeeklyBonusRuntimeBridge.php');
if (!is_string($bridge) || !is_string($database) || !is_string($weekly)) {
    throw new RuntimeException('Realtime serialization sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($bridge, 'if (!$storage instanceof ExclusiveSnapshotStorageInterface)'),
    'Realtime bridge must fail closed when exclusive JSON snapshot support is unavailable.'
);
$assert(
    str_contains($bridge, '$storage->exclusiveReadOnly(')
        && str_contains($bridge, 'return $repository->synchronize($snapshot);'),
    'Realtime DB projection and parity comparison must complete while the exclusive JSON snapshot lock is held.'
);
$assert(
    !str_contains($bridge, '$storage->readOnly('),
    'Realtime bridge must not release a shared JSON snapshot before projection finishes.'
);
$assert(
    str_contains($database, 'public function exclusiveReadOnly(callable $callback): mixed')
        && str_contains($database, 'return $this->exclusiveReadOnlySections(array_keys(self::FILES), $callback);')
        && str_contains($database, 'return $this->readSectionsWithLock($sections, LOCK_EX, $callback);'),
    'JSON storage must keep the existing exclusive snapshot primitive backed by the same app lock used by writers.'
);
$assert(
    str_contains($weekly, '(new RealtimeRuntimeBridge($this->config, $this->router))->synchronizeCurrentJson();'),
    'Nested weekly runtime reconciliation must pass through the same serialized realtime bridge.'
);

fwrite(STDOUT, "RealtimeRuntimeBridgeExclusiveSnapshotContractTest: {$assertions} assertions passed\n");
