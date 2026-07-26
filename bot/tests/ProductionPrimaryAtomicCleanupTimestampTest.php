<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/storage/contracts/StorageAdapterInterface.php';
require_once $root . '/runtime/ProductionPrimaryAtomicStorageAdapter.php';

$subject = (new ReflectionClass(
    ProductionPrimaryAtomicStorageAdapter::class
))->newInstanceWithoutConstructor();

$method = new ReflectionMethod(
    ProductionPrimaryAtomicStorageAdapter::class,
    'discardCleanupTimestampOnlyChange'
);
$method->setAccessible(true);

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$before = [
    'users' => ['1' => ['id' => '1', 'balance_match' => 50]],
    'system' => [
        'game_cleanup_at' => '2026-07-26T10:00:00+00:00',
        'sequence' => 8,
    ],
];
$after = $before;
$after['system']['game_cleanup_at'] = '2026-07-26T10:00:02+00:00';

$discarded = $method->invokeArgs($subject, [&$after, $before]);
$assertTrue($discarded === true, 'Timestamp-only cleanup change must be discarded.');
$assertTrue(
    $after['system']['game_cleanup_at'] === $before['system']['game_cleanup_at'],
    'Existing cleanup timestamp must be restored exactly.'
);
$assertTrue($after === $before, 'Timestamp-only cleanup must leave the snapshot unchanged.');

$beforeWithoutSystem = ['users' => ['1' => ['id' => '1']]];
$afterWithOnlyTimestamp = $beforeWithoutSystem;
$afterWithOnlyTimestamp['system']['game_cleanup_at'] = '2026-07-26T10:00:02+00:00';
$discarded = $method->invokeArgs(
    $subject,
    [&$afterWithOnlyTimestamp, $beforeWithoutSystem]
);
$assertTrue($discarded === true, 'A newly created cleanup-only system section must be discarded.');
$assertTrue(
    $afterWithOnlyTimestamp === $beforeWithoutSystem,
    'Cleanup-only empty system section must not remain in the state.'
);

$beforeSearch = [
    'users' => ['1' => ['id' => '1', 'status' => 'searching']],
    'queue' => [[
        'id' => 'queue_1',
        'user_id' => '1',
        'room' => 'match',
        'bet' => 10,
        'board_size' => 3,
        'updated_at' => '2026-07-26T10:00:00+00:00',
    ]],
    'system' => ['game_cleanup_at' => '2026-07-26T10:00:00+00:00'],
];
$afterSearchHeartbeat = $beforeSearch;
$afterSearchHeartbeat['queue'][0]['updated_at'] = '2026-07-26T10:00:01+00:00';
$afterSearchHeartbeat['system']['game_cleanup_at'] = '2026-07-26T10:00:01+00:00';
$discarded = $method->invokeArgs(
    $subject,
    [&$afterSearchHeartbeat, $beforeSearch]
);
$assertTrue(
    $discarded === true,
    'Search queue heartbeat plus cleanup timestamp must not create a state revision.'
);
$assertTrue(
    $afterSearchHeartbeat === $beforeSearch,
    'Search polling housekeeping fields must be restored exactly.'
);

$realQueueChange = $beforeSearch;
$realQueueChange['queue'][0]['updated_at'] = '2026-07-26T10:00:01+00:00';
$realQueueChange['queue'][0]['board_size'] = 5;
$discarded = $method->invokeArgs($subject, [&$realQueueChange, $beforeSearch]);
$assertTrue(
    $discarded === false,
    'A real queue condition change must never be hidden as a heartbeat.'
);
$assertTrue(
    $realQueueChange['queue'][0]['board_size'] === 5,
    'Real queue data must remain intact.'
);

$realChange = $before;
$realChange['users']['1']['balance_match'] = 40;
$realChange['system']['game_cleanup_at'] = '2026-07-26T10:00:02+00:00';
$discarded = $method->invokeArgs($subject, [&$realChange, $before]);
$assertTrue($discarded === false, 'Cleanup timestamp must remain when real state also changed.');
$assertTrue(
    $realChange['users']['1']['balance_match'] === 40,
    'Real state change must never be reverted by housekeeping optimization.'
);
$assertTrue(
    $realChange['system']['game_cleanup_at'] === '2026-07-26T10:00:02+00:00',
    'Cleanup timestamp accompanying a real change must remain current.'
);

fwrite(
    STDOUT,
    'ProductionPrimaryAtomicCleanupTimestampTest: '
    . $assertions
    . " assertions passed\n"
);
