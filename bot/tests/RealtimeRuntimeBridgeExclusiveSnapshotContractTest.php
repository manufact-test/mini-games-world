<?php
declare(strict_types=1);

$bridge = file_get_contents(dirname(__DIR__) . '/realtime/RealtimeRuntimeBridge.php');
if (!is_string($bridge)) {
    throw new RuntimeException('Realtime runtime bridge source is unavailable.');
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
    str_contains($bridge, '$storage->exclusiveReadOnly('),
    'Realtime bridge must keep the JSON lock while the DB projection is synchronized.'
);
$assert(
    str_contains($bridge, 'return $repository->synchronize($snapshot);'),
    'Realtime DB synchronization must execute inside the exclusive JSON snapshot callback.'
);
$assert(
    !str_contains($bridge, '$storage->readOnly('),
    'Realtime bridge must not release a shared JSON snapshot before DB synchronization.'
);

fwrite(STDOUT, "RealtimeRuntimeBridgeExclusiveSnapshotContractTest: {$assertions} assertions passed\n");
