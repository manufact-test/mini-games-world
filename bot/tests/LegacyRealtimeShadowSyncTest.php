<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/realtime/LegacyRealtimeShadowSyncService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyRealtimeShadowSyncTest requires pdo_sqlite.');
}

final class ArrayRealtimeShadowStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}

    public function replace(array $data): void
    {
        $this->data = $data;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this->data);
    }

    public function readOnly(callable $callback): mixed
    {
        $snapshot = $this->data;
        return $callback($snapshot);
    }

    public function driver(): string
    {
        return 'json';
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Shadow test schema must include all migrations');

$initial = [
    'games' => [
        'game-1' => [
            'id' => 'game-1',
            'status' => 'active',
            'updated_at' => '2026-07-17T09:00:00+00:00',
            'server_state' => ['z' => 2, 'a' => 1],
        ],
    ],
    'queue' => [[
        'id' => 'queue-1',
        'user_id' => '1001',
        'game_type' => 'domino',
        'updated_at' => '2026-07-17T09:00:01+00:00',
    ]],
    'invites' => [[
        'id' => 'invite-1',
        'token' => 'token-1',
        'status' => 'pending',
        'updated_at' => '2026-07-17T09:00:02+00:00',
    ]],
    'notifications' => [[
        'id' => 'notification-1',
        'event_key' => 'invite:invite-1:received',
        'user_id' => '1002',
        'message' => 'Первое уведомление',
        'created_at' => '2026-07-17T09:00:03+00:00',
    ]],
];

$storage = new ArrayRealtimeShadowStorage($initial);
$service = new LegacyRealtimeShadowSyncService($storage, $database);

$preview = $service->preview();
$assertSame(true, $preview['dry_run'], 'Preview must be dry-run');
foreach (['games', 'queue', 'invites', 'notifications'] as $section) {
    $assertSame(1, $preview['sections'][$section]['inserted_count'], $section . ' preview must detect one insert');
}
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), 'Preview must not write rows');

$first = $service->run();
$assertSame(false, $first['dry_run'], 'Run must not be dry-run');
$assertSame(4, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), 'Run must copy all realtime records');
foreach (['games', 'queue', 'invites', 'notifications'] as $section) {
    $assertSame(1, $first['sections'][$section]['inserted_count'], $section . ' must insert once');
}

$repeat = $service->run();
foreach (['games', 'queue', 'invites', 'notifications'] as $section) {
    $assertSame(1, $repeat['sections'][$section]['unchanged_count'], $section . ' repeat must be unchanged');
    $assertSame(0, $repeat['sections'][$section]['updated_count'], $section . ' repeat must not update');
    $assertSame(0, $repeat['sections'][$section]['repair_count'], $section . ' repeat must not repair healthy rows');
}
$assertSame($first['source_fingerprint'], $repeat['source_fingerprint'], 'Repeated source fingerprint must be stable');

$database->execute(
    'UPDATE mgw_legacy_realtime_shadow SET payload_json = :payload_json
     WHERE entity_type = :entity_type AND entity_key = :entity_key',
    [
        'payload_json' => '{"id":"game-1","status":"tampered"}',
        'entity_type' => 'games',
        'entity_key' => 'game-1',
    ]
);
$repairPreview = $service->preview();
$assertSame(1, $repairPreview['sections']['games']['repair_count'], 'Payload/hash corruption must be detected');
$assertSame(1, $repairPreview['sections']['games']['updated_count'], 'Corrupted payload must be scheduled for update');
$repairRun = $service->run();
$assertSame(1, $repairRun['sections']['games']['repair_count'], 'Run must report the repaired row');
$repairedPayload = json_decode((string)$database->fetchValue(
    'SELECT payload_json FROM mgw_legacy_realtime_shadow
     WHERE entity_type = :entity_type AND entity_key = :entity_key',
    ['entity_type' => 'games', 'entity_key' => 'game-1']
), true, 512, JSON_THROW_ON_ERROR);
$assertSame('active', $repairedPayload['status'] ?? null, 'Run must restore exact source payload after corruption');
$assertSame(0, $service->preview()['sections']['games']['repair_count'], 'Repaired row must become healthy');

$reordered = $initial;
$reordered['games']['game-1'] = [
    'server_state' => ['a' => 1, 'z' => 2],
    'updated_at' => '2026-07-17T09:00:00+00:00',
    'status' => 'active',
    'id' => 'game-1',
];
$storage->replace($reordered);
$canonical = $service->preview();
$assertSame($first['source_fingerprint'], $canonical['source_fingerprint'], 'Associative key order must not change the fingerprint');
$assertSame(1, $canonical['sections']['games']['unchanged_count'], 'Canonical JSON must ignore associative key order');

$changed = $reordered;
$changed['notifications'][0]['message'] = 'Обновлённое уведомление';
unset($changed['invites'][0]);
$changed['invites'] = array_values($changed['invites']);
$storage->replace($changed);
$delta = $service->run();
$assertSame(1, $delta['sections']['notifications']['updated_count'], 'Changed notification must update once');
$assertSame(1, $delta['sections']['invites']['deleted_count'], 'Removed invite must be pruned');
$assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_realtime_shadow'), 'Pruned shadow must match source count');
$assertSame(
    0,
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type = :type',
        ['type' => 'invites']
    ),
    'Removed invite must not remain in shadow storage'
);
$payload = (string)$database->fetchValue(
    'SELECT payload_json FROM mgw_legacy_realtime_shadow
     WHERE entity_type = :type AND entity_key = :key',
    ['type' => 'notifications', 'key' => 'notification-1']
);
$assertTrue(str_contains($payload, 'Обновлённое уведомление'), 'Updated exact JSON payload must be stored');

$duplicate = $changed;
$duplicate['queue'] = [
    ['user_id' => '1001', 'game_type' => 'domino'],
    ['user_id' => '1001', 'game_type' => 'chess'],
];
$storage->replace($duplicate);
$assertThrows(
    static fn() => $service->preview(),
    'duplicate legacy shadow key',
    'Duplicate source identities must fail closed'
);

fwrite(STDOUT, "LegacyRealtimeShadowSyncTest: {$assertions} assertions passed\n");
