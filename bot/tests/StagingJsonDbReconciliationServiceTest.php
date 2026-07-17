<?php
declare(strict_types=1);

$botDir = dirname(__DIR__);
$databaseDir = $botDir . '/database';

require $botDir . '/storage/contracts/StorageTransactionInterface.php';
require $botDir . '/storage/contracts/StorageAdapterInterface.php';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';
require $botDir . '/ledger/LedgerIntegrity.php';
require $botDir . '/ledger/LedgerWriteService.php';
require $botDir . '/ledger/LedgerIntegrityVerifier.php';
require $botDir . '/realtime/LegacyRealtimeShadowSyncService.php';
require $botDir . '/ledger/LegacyEconomyShadowSyncService.php';
require $botDir . '/ledger/LegacyOpeningBalanceImportService.php';
require $botDir . '/ledger/LegacyFinancialStatusNormalizer.php';
require $botDir . '/ledger/LegacyFinancialArchiveImportService.php';
require $botDir . '/migration/StagingJsonDbReconciliationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('StagingJsonDbReconciliationServiceTest requires pdo_sqlite.');
}

final class ReconciliationArrayStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback($this->data);
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->data);
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
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$data = [
    'users' => [
        '1001' => [
            'id' => '1001',
            'telegram_id' => '1001',
            'username' => 'legacy_player',
            'first_name' => 'Legacy',
            'balance_match' => 30,
            'balance_gold' => 0,
            'registered_at' => '2026-07-17T10:00:00+00:00',
            'last_seen_at' => '2026-07-17T11:00:00+00:00',
        ],
    ],
    'games' => [[
        'id' => 'game_1',
        'game_type' => 'tictactoe',
        'status' => 'active',
        'created_at' => '2026-07-17T10:05:00+00:00',
    ]],
    'queue' => [[
        'id' => 'queue_1',
        'user_id' => '1001',
        'game_type' => 'tictactoe',
        'created_at' => '2026-07-17T10:04:00+00:00',
    ]],
    'invites' => [[
        'id' => 'invite_1',
        'from_user_id' => '1001',
        'status' => 'pending',
        'created_at' => '2026-07-17T10:03:00+00:00',
    ]],
    'notifications' => [[
        'id' => 'notification_1',
        'user_id' => '1001',
        'type' => 'invite',
        'created_at' => '2026-07-17T10:03:30+00:00',
    ]],
    'transactions' => [[
        'id' => 'tx_game_1',
        'category' => 'match_result',
        'user_id' => '1001',
        'amount' => 10,
        'created_at' => '2026-07-17T10:10:00+00:00',
    ]],
    'payments' => [],
    'shop_orders' => [],
];

$storage = new ReconciliationArrayStorage($data);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(6, $runner->migrate(false)['executed_count'], 'Reconciliation test must apply six migrations');

$realtime = new LegacyRealtimeShadowSyncService($storage, $database);
$economy = new LegacyEconomyShadowSyncService($storage, $database);
$opening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    new LedgerIntegrityVerifier($database)
);
$archive = new LegacyFinancialArchiveImportService(
    $storage,
    $database,
    new LegacyFinancialStatusNormalizer()
);

$assertTrue($realtime->run()['ok'], 'Realtime shadow sync must succeed');
$assertTrue($economy->run()['ok'], 'Economy shadow sync must succeed');
$assertSame('completed', $opening->run()['status'], 'Opening balance import must complete');
$assertSame('completed', $archive->run()['status'], 'Financial archive import must complete');

$service = new StagingJsonDbReconciliationService(
    $database,
    $realtime,
    $economy,
    $opening,
    $archive
);
$first = $service->report();
$second = $service->report();

$assertSame(true, $first['ok'], 'Current staging baseline must be internally consistent');
$assertSame(true, $first['read_only'], 'Report must declare read-only behavior');
$assertSame(true, $first['ready_for_next_import_step'], 'Clean baseline must allow the next importer step');
$assertSame(false, $first['count_parity_complete'], 'Normalized realtime/account tables are intentionally not imported yet');
$assertSame($first['report_fingerprint'], $second['report_fingerprint'], 'Repeated report must be deterministic');
$assertSame(1, $first['account_mapping']['source_user_count'], 'One legacy user must be reported');
$assertSame(1, $first['account_mapping']['missing_identity_count'], 'Unlinked legacy user must be explicit');
$assertSame(1, $first['normalized_targets']['matches']['source_count'], 'One source match must be reported');
$assertSame(0, $first['normalized_targets']['matches']['database_count'], 'Normalized match table must still be empty');
$assertSame(2, $first['normalized_targets']['balances']['source_count'], 'Match and Gold must remain separate assets');
$assertSame(2, $first['normalized_targets']['balances']['database_count'], 'Both opening balance rows must exist');
$assertSame(true, $first['normalized_targets']['balances']['count_matches'], 'Opening balances must have count parity');
$assertSame(0, $first['financial_archive']['archive_counts']['payments'], 'Empty payment source must remain empty');
$assertSame(1, $first['financial_archive']['skipped_transaction_count'], 'Unrelated game transaction must be skipped');
$assertSame([], $first['blocking_reasons'], 'Clean baseline must have no blocking reasons');
$assertTrue(count($first['migration_gaps']) >= 5, 'Report must list normalized migration gaps');

$changed = $data;
$changed['notifications'][] = [
    'id' => 'notification_2',
    'user_id' => '1001',
    'type' => 'system',
    'created_at' => '2026-07-17T12:00:00+00:00',
];
$storage->setData($changed);
$drift = $service->report();
$assertSame(false, $drift['ok'], 'JSON drift after shadow sync must block the report');
$assertSame(false, $drift['ready_for_next_import_step'], 'Drift must block the next importer step');
$assertTrue(
    in_array('realtime/notifications: shadow differs from current JSON', $drift['blocking_reasons'], true),
    'Notification drift must be named explicitly'
);

fwrite(STDOUT, "StagingJsonDbReconciliationServiceTest passed: {$assertions} assertions.\n");
