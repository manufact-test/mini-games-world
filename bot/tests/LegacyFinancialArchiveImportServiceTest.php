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
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LegacyFinancialStatusNormalizer.php';
require $root . '/ledger/LegacyFinancialArchiveImportService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyFinancialArchiveImportServiceTest requires pdo_sqlite.');
}

final class LegacyFinancialArchiveTestStorage implements StorageAdapterInterface
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Financial archive import test must create all schemas');

$now = '2026-07-17 21:00:00.000000';
$mgwId = 'MGW-ARCHIVEIMPORT01';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Archive Import',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_identities (
        mgw_id, provider, provider_subject, linked_at_utc, last_authenticated_at_utc
     ) VALUES (
        :mgw_id, :provider, :provider_subject, :linked_at, :authenticated_at
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
    'payments' => [
        [
            'id' => 'pay_archive_import_1',
            'user_id' => '1001',
            'provider' => 'manual_test',
            'status' => 'paid',
            'room' => 'match',
            'coins' => 30,
            'price' => 15,
            'currency' => 'RUB',
            'balance_applied' => true,
            'created_at' => '2026-07-17T20:00:00+00:00',
            'updated_at' => '2026-07-17T20:05:00+00:00',
            'applied_at' => '2026-07-17T20:05:00+00:00',
        ],
        [
            'id' => 'pay_archive_import_2',
            'user_id' => '1002',
            'status' => 'future-status',
            'room' => 'gold',
            'coins' => 5,
            'amount_rub' => 5,
            'currency' => 'RUB',
            'balance_applied' => false,
            'created_at' => '2026-07-17T20:10:00+00:00',
        ],
    ],
    'shop_orders' => [
        [
            'id' => 'shop_archive_import_1',
            'user_id' => '1001',
            'status' => 'done',
            'amount' => 50,
            'item_id' => 'certificate-a',
            'denomination_id' => 'certificate-a-50',
            'provider' => 'Legacy Provider',
            'country_code' => 'ru',
            'created_at' => '2026-07-17T20:15:00+00:00',
            'completed_at' => '2026-07-17T20:20:00+00:00',
            'prize_snapshot' => ['title' => 'Certificate A'],
        ],
        [
            'id' => 'shop_archive_import_2',
            'user_id' => '1002',
            'status' => 'pending',
            'gold_cost' => 25,
            'item_id' => 'certificate-b',
            'denomination_id' => 'certificate-b-25',
            'created_at' => '2026-07-17T20:25:00+00:00',
        ],
    ],
    'transactions' => [
        ['id' => 'tx_archive_1', 'category' => 'payment_draft', 'payment_id' => 'pay_archive_import_1', 'user_id' => '1001', 'room' => 'match', 'amount' => 0, 'amount_rub' => 15, 'currency' => 'RUB', 'created_at' => '2026-07-17T20:00:00+00:00'],
        ['id' => 'tx_archive_2', 'category' => 'payment_apply', 'payment_id' => 'pay_archive_import_1', 'user_id' => '1001', 'room' => 'match', 'amount' => 30, 'balance_before' => 0, 'balance_after' => 30, 'amount_rub' => 15, 'currency' => 'RUB', 'created_at' => '2026-07-17T20:05:00+00:00'],
        ['id' => 'tx_archive_3', 'category' => 'shop_order', 'order_id' => 'shop_archive_import_1', 'user_id' => '1001', 'room' => 'gold', 'amount' => -50, 'balance_after' => 50, 'created_at' => '2026-07-17T20:15:00+00:00'],
        ['id' => 'tx_archive_4', 'category' => 'shop_order_done', 'order_id' => 'shop_archive_import_1', 'user_id' => '1001', 'room' => 'gold', 'amount' => 0, 'created_at' => '2026-07-17T20:20:00+00:00'],
        ['id' => 'tx_archive_5', 'category' => 'shop_refund', 'order_id' => 'shop_archive_import_2', 'user_id' => '1002', 'room' => 'gold', 'amount' => 25, 'created_at' => '2026-07-17T20:30:00+00:00'],
        ['id' => 'tx_archive_6', 'category' => '', 'type' => 'shop_order_reject', 'order_id' => 'shop_archive_import_2', 'user_id' => '1002', 'room' => 'gold', 'amount' => 0, 'created_at' => '2026-07-17T20:31:00+00:00'],
        ['id' => 'tx_unrelated', 'category' => 'match_settlement', 'user_id' => '1001', 'room' => 'match', 'amount' => 10, 'created_at' => '2026-07-17T20:32:00+00:00'],
        ['id' => 'tx_archive_7', 'category' => 'future_category', 'payment_id' => 'pay_archive_import_2', 'user_id' => '1002', 'room' => 'gold', 'amount' => 0, 'created_at' => '2026-07-17T20:33:00+00:00'],
    ],
];

$storage = new LegacyFinancialArchiveTestStorage($source);
$service = new LegacyFinancialArchiveImportService(
    $storage,
    $database,
    new LegacyFinancialStatusNormalizer()
);

$preview = $service->preview();
$assertSame(true, $preview['ready'], 'Fresh archive preview must be ready');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 8], $preview['source_counts'], 'Preview must report all source records');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 7], $preview['archive_counts'], 'Preview must keep only related transactions');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 7], $preview['planned_create_counts'], 'Fresh preview must plan every archive row');
$assertSame(1, $preview['skipped_transaction_count'], 'Unrelated transactions must remain outside the archive');
$assertSame(2, $preview['unknown_status_count'], 'Unknown raw statuses must be visible, not guessed');
$assertSame(0, $preview['synthetic_id_count'], 'Complete source records must keep their original IDs');

$first = $service->run();
$assertSame(true, $first['ok'], 'First archive import must complete');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 7], $first['created_counts'], 'First run must create every planned row');
$assertSame(['payments' => 0, 'shop_orders' => 0, 'transactions' => 0], $first['unchanged_counts'], 'First run must not replay rows');
$assertSame(true, $first['verification']['ok'], 'First run must verify archive rows');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_payments'), 'Payment archive must contain two rows');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_shop_orders'), 'Order archive must contain two rows');
$assertSame(7, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_financial_transactions'), 'Transaction archive must contain only related rows');
$assertSame('mgw:' . $mgwId, (string)$database->fetchValue(
    'SELECT account_ref FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => 'pay_archive_import_1']
), 'Mapped payment must use the MGW account reference');
$assertSame('legacy:1002', (string)$database->fetchValue(
    'SELECT account_ref FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => 'pay_archive_import_2']
), 'Unmapped payment must retain the legacy account reference');
$assertSame(1500, (int)$database->fetchValue(
    'SELECT fiat_amount_minor FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => 'pay_archive_import_1']
), 'Payment amount must be stored in minor currency units');
$assertSame('match_coin', (string)$database->fetchValue(
    'SELECT asset_code FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => 'pay_archive_import_1']
), 'Match payment must remain separate from Gold');
$assertSame('completed', (string)$database->fetchValue(
    'SELECT status_normalized FROM mgw_legacy_shop_orders WHERE legacy_order_id = :id',
    ['id' => 'shop_archive_import_1']
), 'Current legacy order status done must normalize to completed');
$assertSame('unknown', (string)$database->fetchValue(
    'SELECT status_normalized FROM mgw_legacy_financial_transactions WHERE legacy_transaction_id = :id',
    ['id' => 'tx_archive_7']
), 'Unknown related transaction status must remain unknown');
$assertSame(7, (int)$database->fetchValue(
    'SELECT source_index FROM mgw_legacy_financial_transactions WHERE legacy_transaction_id = :id',
    ['id' => 'tx_archive_7']
), 'Archive must preserve the original source index');

$repeatPreview = $service->preview();
$assertSame(true, $repeatPreview['ready'], 'Completed identical archive must remain inspectable');
$assertSame('completed', $repeatPreview['status'], 'Metadata must record completion');
$assertSame(['payments' => 0, 'shop_orders' => 0, 'transactions' => 0], $repeatPreview['planned_create_counts'], 'Repeat preview must plan no writes');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 7], $repeatPreview['unchanged_counts'], 'Repeat preview must match every row');

$repeat = $service->run();
$assertSame(['payments' => 0, 'shop_orders' => 0, 'transactions' => 0], $repeat['created_counts'], 'Repeat run must not duplicate archive rows');
$assertSame(['payments' => 2, 'shop_orders' => 2, 'transactions' => 7], $repeat['unchanged_counts'], 'Repeat run must replay every row safely');
$assertSame(7, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_financial_transactions'), 'Repeat run must not duplicate related transactions');

$unrelatedChanged = $source;
$unrelatedChanged['transactions'][6]['amount'] = 999;
$storage->replace($unrelatedChanged);
$unrelatedPreview = $service->preview();
$assertSame(true, $unrelatedPreview['ready'], 'Changes outside the financial archive scope must not invalidate the archive fingerprint');
$assertSame($repeatPreview['source_fingerprint'], $unrelatedPreview['source_fingerprint'], 'Skipped transactions must not change the archive fingerprint');

$changed = $source;
$changed['payments'][0]['coins'] = 31;
$storage->replace($changed);
$changedPreview = $service->preview();
$assertSame(false, $changedPreview['ready'], 'Changed archived source data after completion must fail closed');
$assertTrue(in_array('Archive metadata belongs to a different source fingerprint.', $changedPreview['blocking_reasons'], true), 'Changed source fingerprint must be reported');
$assertThrows(static fn() => $service->run(), 'not ready', 'Changed source must not mutate the completed archive');

$storage->replace($source);
$database->execute(
    'UPDATE mgw_legacy_payments SET snapshot_sha256 = :hash WHERE legacy_payment_id = :id',
    ['hash' => str_repeat('0', 64), 'id' => 'pay_archive_import_1']
);
$corrupt = $service->preview();
$assertSame(false, $corrupt['ready'], 'Corrupted archive row must fail closed');
$assertSame(1, $corrupt['conflict_counts']['payments'], 'Corrupted payment row must be counted as a conflict');

fwrite(STDOUT, "LegacyFinancialArchiveImportServiceTest passed: {$assertions} assertions.\n");
