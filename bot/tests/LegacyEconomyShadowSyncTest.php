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
require $root . '/ledger/LegacyEconomyShadowSyncService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyEconomyShadowSyncTest requires pdo_sqlite.');
}

final class ArrayEconomyShadowStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function replace(array $data): void { $this->data = $data; }
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
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
    try { $callback(); }
    catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(6, $runner->migrate(false)['executed_count'], 'Economy shadow test must include all migrations');

$now = '2026-07-17 16:00:00.000000';
$mgwId = 'MGW-0123456789ABCDEF';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Mapped user',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_identities (
        mgw_id, provider, provider_subject, provider_username, linked_at_utc, last_authenticated_at_utc
     ) VALUES (
        :mgw_id, :provider, :provider_subject, NULL, :linked_at, :authenticated_at
     )',
    [
        'mgw_id' => $mgwId,
        'provider' => 'telegram',
        'provider_subject' => '1001',
        'linked_at' => $now,
        'authenticated_at' => $now,
    ]
);

$source = [
    'users' => [
        '1001' => [
            'id' => '1001',
            'telegram_id' => '1001',
            'balance_match' => 70,
            'balance_gold' => 25,
            'registered_at' => '2026-07-01T10:00:00+00:00',
            'last_seen_at' => '2026-07-17T15:59:00+00:00',
            'stats' => ['wins' => 3],
        ],
        '1002' => [
            'id' => '1002',
            'balance_match' => '40',
            'balance_gold' => 0,
            'registered_at' => '2026-07-02T10:00:00+00:00',
        ],
    ],
    'transactions' => [
        ['id' => 'tx-1', 'type' => 'balance_change', 'user_id' => '1001', 'room' => 'match', 'amount' => 50, 'category' => 'welcome_bonus', 'created_at' => '2026-07-01T10:00:00+00:00'],
        ['id' => 'tx-2', 'type' => 'game_start', 'players' => ['1001', '1002'], 'room' => 'match', 'bet' => 10, 'created_at' => '2026-07-17T12:00:00+00:00'],
    ],
];
$storage = new ArrayEconomyShadowStorage($source);
$service = new LegacyEconomyShadowSyncService($storage, $database);

$preview = $service->preview();
$assertSame(true, $preview['dry_run'], 'Preview must be dry-run');
$assertSame(2, $preview['sections']['user_balances']['inserted_count'], 'Preview must detect two user balances');
$assertSame(2, $preview['sections']['transactions']['inserted_count'], 'Preview must detect two transactions');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type LIKE 'economy_%'"), 'Preview must not write');
$assertSame(1, $preview['reconciliation']['mapped_identity_count'], 'Known Telegram identity must map to MGW-ID');
$assertSame(1, $preview['reconciliation']['legacy_only_count'], 'Unknown identity must keep a legacy account reference');
$assertSame(4, $preview['reconciliation']['missing_balance_row_count'], 'Both assets must be planned for both users');
$assertSame(['match_coin' => 110, 'gold_coin' => 25], $preview['reconciliation']['source_totals'], 'Source balance totals must be exact');

$first = $service->run();
$assertSame(false, $first['dry_run'], 'Run must write');
$assertSame(4, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type LIKE 'economy_%'"), 'Run must write exact economy rows');
$assertSame(4, $first['shadow_integrity']['checked_count'], 'Every economy shadow row must be checked');
$assertSame(0, $first['shadow_integrity']['corrupted_count'], 'Fresh economy shadow must be healthy');

$repeat = $service->run();
$assertSame(2, $repeat['sections']['user_balances']['unchanged_count'], 'Repeated users must be unchanged');
$assertSame(2, $repeat['sections']['transactions']['unchanged_count'], 'Repeated transactions must be unchanged');
$assertSame($first['source_fingerprint'], $repeat['source_fingerprint'], 'Source fingerprint must be stable');

$insertBalance = static function (string $accountRef, ?string $mgwIdValue, string $legacyId, string $asset, int $amount) use ($database, $now): void {
    $database->execute(
        'INSERT INTO mgw_balances (
            account_ref, mgw_id, legacy_user_id, asset_code,
            available_amount, reserved_amount, version, created_at_utc, updated_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :asset_code,
            :available_amount, 0, 1, :created_at, :updated_at
         )',
        [
            'account_ref' => $accountRef,
            'mgw_id' => $mgwIdValue,
            'legacy_user_id' => $legacyId,
            'asset_code' => $asset,
            'available_amount' => $amount,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
};
$insertBalance('mgw:' . $mgwId, $mgwId, '1001', 'match_coin', 70);
$insertBalance('mgw:' . $mgwId, $mgwId, '1001', 'gold_coin', 25);
$insertBalance('legacy:1002', null, '1002', 'match_coin', 40);
$insertBalance('legacy:1002', null, '1002', 'gold_coin', 0);
$balanced = $service->preview();
$assertSame(0, $balanced['reconciliation']['balance_mismatch_count'], 'Matching DB balances must reconcile');
$assertSame(['match_coin' => 110, 'gold_coin' => 25], $balanced['reconciliation']['database_totals'], 'DB totals must match source totals');

$database->execute(
    "UPDATE mgw_legacy_realtime_shadow SET payload_json = :payload
     WHERE entity_type = 'economy_transaction' AND entity_key = 'tx-1'",
    ['payload' => '{"id":"tx-1","tampered":true}']
);
$tampered = $service->preview();
$assertSame(1, $tampered['sections']['transactions']['repair_count'], 'Tampered transaction payload must be detected');
$repaired = $service->run();
$assertSame(1, $repaired['sections']['transactions']['repair_count'], 'Run must report the repaired transaction');
$assertSame(0, $service->preview()['sections']['transactions']['repair_count'], 'Repaired transaction must become healthy');

$changed = $source;
$changed['users']['1001']['balance_match'] = 80;
array_pop($changed['transactions']);
$storage->replace($changed);
$delta = $service->run();
$assertSame(1, $delta['sections']['user_balances']['updated_count'], 'Changed source balance must update shadow once');
$assertSame(1, $delta['sections']['transactions']['deleted_count'], 'Removed source transaction must be pruned');
$assertSame(1, $delta['reconciliation']['balance_mismatch_count'], 'Changed source balance must appear in reconciliation');
$assertSame(1, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_legacy_realtime_shadow WHERE entity_type = 'economy_transaction'"), 'Pruned transaction must not remain');

$invalid = $changed;
$invalid['users']['1001']['balance_gold'] = -1;
$storage->replace($invalid);
$assertThrows(static fn() => $service->preview(), 'negative balance_gold', 'Negative legacy balances must fail closed');

fwrite(STDOUT, "LegacyEconomyShadowSyncTest: {$assertions} assertions passed\n");
