<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyFinancialArchiveSchemaTest requires pdo_sqlite.');
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
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Archive schema test must include seven migrations');
$assertSame(0, $runner->migrate(false)['executed_count'], 'Repeated archive migration run must be idempotent');

$tables = [
    'mgw_legacy_payments',
    'mgw_legacy_shop_orders',
    'mgw_legacy_financial_transactions',
];
foreach ($tables as $table) {
    $assertSame(
        $table,
        (string)$database->fetchValue(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name",
            ['name' => $table]
        ),
        $table . ' must exist'
    );
    $columns = array_column($database->fetchAll('PRAGMA table_info(' . $table . ')'), 'name');
    $assertTrue(in_array('snapshot_json', $columns, true), $table . ' must preserve an exact snapshot');
    $assertTrue(in_array('snapshot_sha256', $columns, true), $table . ' must preserve a snapshot hash');
    $assertTrue(!in_array('updated_at_utc', $columns, true), $table . ' must not expose a mutable archive timestamp');
}

$now = '2026-07-17 20:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'mgw_archive_user',
        'status' => 'active',
        'display_name' => 'Archive User',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);

$paymentSnapshot = json_encode([
    'id' => 'pay_archive_1',
    'user_id' => '972585905',
    'status' => 'paid',
    'room' => 'match',
    'coins' => 30,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$paymentHash = hash('sha256', $paymentSnapshot);
$database->execute(
    'INSERT INTO mgw_legacy_payments (
        legacy_payment_id, account_ref, mgw_id, legacy_user_id, provider,
        status_raw, status_normalized, room_raw, asset_code, coin_amount,
        fiat_amount_minor, currency, balance_applied, source_created_at_utc,
        snapshot_json, snapshot_sha256, archive_batch_id, source_file, source_index, archived_at_utc
     ) VALUES (
        :legacy_payment_id, :account_ref, :mgw_id, :legacy_user_id, :provider,
        :status_raw, :status_normalized, :room_raw, :asset_code, :coin_amount,
        :fiat_amount_minor, :currency, 1, :source_created_at,
        :snapshot_json, :snapshot_sha256, :archive_batch_id, :source_file, :source_index, :archived_at
     )',
    [
        'legacy_payment_id' => 'pay_archive_1',
        'account_ref' => 'mgw:mgw_archive_user',
        'mgw_id' => 'mgw_archive_user',
        'legacy_user_id' => '972585905',
        'provider' => 'manual_test',
        'status_raw' => 'paid',
        'status_normalized' => 'completed',
        'room_raw' => 'match',
        'asset_code' => 'match_coin',
        'coin_amount' => 30,
        'fiat_amount_minor' => 3000,
        'currency' => 'RUB',
        'source_created_at' => $now,
        'snapshot_json' => $paymentSnapshot,
        'snapshot_sha256' => $paymentHash,
        'archive_batch_id' => hash('sha256', 'archive-batch-1'),
        'source_file' => 'payments.json',
        'source_index' => 0,
        'archived_at' => $now,
    ]
);
$payment = $database->fetchAll('SELECT * FROM mgw_legacy_payments WHERE legacy_payment_id = :id', ['id' => 'pay_archive_1'])[0];
$assertSame('paid', (string)$payment['status_raw'], 'Raw payment status must remain exact');
$assertSame('completed', (string)$payment['status_normalized'], 'Payment status migration must be queryable');
$assertSame('match_coin', (string)$payment['asset_code'], 'Legacy Match payment must remain separate');
$assertSame($paymentHash, (string)$payment['snapshot_sha256'], 'Payment snapshot hash must round-trip');

$orderSnapshot = json_encode([
    'id' => 'shop_archive_1',
    'user_id' => '972585905',
    'status' => 'fulfilled',
    'amount' => 50,
    'prize_snapshot' => ['title' => 'Legacy certificate'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$orderHash = hash('sha256', $orderSnapshot);
$database->execute(
    'INSERT INTO mgw_legacy_shop_orders (
        legacy_order_id, account_ref, mgw_id, legacy_user_id, status_raw,
        status_normalized, refund_done, gold_amount, item_id, denomination_id,
        provider, country_code, source_created_at_utc, snapshot_json,
        snapshot_sha256, archive_batch_id, source_file, source_index, archived_at_utc
     ) VALUES (
        :legacy_order_id, :account_ref, :mgw_id, :legacy_user_id, :status_raw,
        :status_normalized, 0, :gold_amount, :item_id, :denomination_id,
        :provider, :country_code, :source_created_at, :snapshot_json,
        :snapshot_sha256, :archive_batch_id, :source_file, :source_index, :archived_at
     )',
    [
        'legacy_order_id' => 'shop_archive_1',
        'account_ref' => 'mgw:mgw_archive_user',
        'mgw_id' => 'mgw_archive_user',
        'legacy_user_id' => '972585905',
        'status_raw' => 'fulfilled',
        'status_normalized' => 'completed',
        'gold_amount' => 50,
        'item_id' => 'legacy_certificate',
        'denomination_id' => 'legacy_50',
        'provider' => 'legacy_manual',
        'country_code' => 'RU',
        'source_created_at' => $now,
        'snapshot_json' => $orderSnapshot,
        'snapshot_sha256' => $orderHash,
        'archive_batch_id' => hash('sha256', 'archive-batch-1'),
        'source_file' => 'shop_orders.json',
        'source_index' => 0,
        'archived_at' => $now,
    ]
);
$order = $database->fetchAll('SELECT * FROM mgw_legacy_shop_orders WHERE legacy_order_id = :id', ['id' => 'shop_archive_1'])[0];
$assertSame('fulfilled', (string)$order['status_raw'], 'Raw order status must remain exact');
$assertSame(50, (int)$order['gold_amount'], 'Legacy certificate order amount must remain exact');
$assertSame($orderHash, (string)$order['snapshot_sha256'], 'Order snapshot hash must round-trip');

$transactionSnapshot = json_encode([
    'id' => 'tx_archive_1',
    'category' => 'shop_order',
    'order_id' => 'shop_archive_1',
    'amount' => -50,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$transactionHash = hash('sha256', $transactionSnapshot);
$database->execute(
    'INSERT INTO mgw_legacy_financial_transactions (
        legacy_transaction_id, account_ref, mgw_id, legacy_user_id, legacy_order_id,
        type_raw, category_raw, status_normalized, room_raw, asset_code, amount,
        source_created_at_utc, snapshot_json, snapshot_sha256, archive_batch_id,
        source_file, source_index, archived_at_utc
     ) VALUES (
        :legacy_transaction_id, :account_ref, :mgw_id, :legacy_user_id, :legacy_order_id,
        :type_raw, :category_raw, :status_normalized, :room_raw, :asset_code, :amount,
        :source_created_at, :snapshot_json, :snapshot_sha256, :archive_batch_id,
        :source_file, :source_index, :archived_at
     )',
    [
        'legacy_transaction_id' => 'tx_archive_1',
        'account_ref' => 'mgw:mgw_archive_user',
        'mgw_id' => 'mgw_archive_user',
        'legacy_user_id' => '972585905',
        'legacy_order_id' => 'shop_archive_1',
        'type_raw' => 'balance_change',
        'category_raw' => 'shop_order',
        'status_normalized' => 'completed',
        'room_raw' => 'gold',
        'asset_code' => 'gold_coin',
        'amount' => -50,
        'source_created_at' => $now,
        'snapshot_json' => $transactionSnapshot,
        'snapshot_sha256' => $transactionHash,
        'archive_batch_id' => hash('sha256', 'archive-batch-1'),
        'source_file' => 'transactions.json',
        'source_index' => 7,
        'archived_at' => $now,
    ]
);
$transaction = $database->fetchAll('SELECT * FROM mgw_legacy_financial_transactions WHERE legacy_transaction_id = :id', ['id' => 'tx_archive_1'])[0];
$assertSame('shop_archive_1', (string)$transaction['legacy_order_id'], 'Related legacy transaction must retain its order link');
$assertSame('gold_coin', (string)$transaction['asset_code'], 'Legacy Gold transaction must remain separate');
$assertSame($transactionHash, (string)$transaction['snapshot_sha256'], 'Transaction snapshot hash must round-trip');

$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_legacy_payments (
            legacy_payment_id, account_ref, status_raw, status_normalized,
            snapshot_json, snapshot_sha256, archive_batch_id, source_file, source_index, archived_at_utc
         ) VALUES (
            :id, :account_ref, :status_raw, :status_normalized,
            :snapshot_json, :snapshot_sha256, :archive_batch_id, :source_file, :source_index, :archived_at
         )',
        [
            'id' => 'pay_archive_duplicate_source',
            'account_ref' => 'legacy:972585905',
            'status_raw' => 'unknown_future_status',
            'status_normalized' => 'unknown',
            'snapshot_json' => '{}',
            'snapshot_sha256' => hash('sha256', '{}'),
            'archive_batch_id' => hash('sha256', 'archive-batch-2'),
            'source_file' => 'payments.json',
            'source_index' => 0,
            'archived_at' => $now,
        ]
    ),
    'unique',
    'The same source position must not be archived twice'
);

fwrite(STDOUT, "LegacyFinancialArchiveSchemaTest passed: {$assertions} assertions.\n");
