<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/DatabaseConfig.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/PdoConnectionFactory.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';

$dsn = trim((string)(getenv('MGW_TEST_MYSQL_DSN') ?: ''));
if ($dsn === '') {
    fwrite(STDOUT, "Mvp24LegacyArchiveCurrentMySqlContractTest: MySQL skipped.\n");
    return;
}

$dsnValue = static function (string $key) use ($dsn): string {
    return preg_match('/(?:^|[:;])' . preg_quote($key, '/') . '=([^;]+)/', $dsn, $matches) === 1
        ? trim((string)$matches[1])
        : '';
};

$config = DatabaseConfig::fromApplicationConfig([
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => $dsnValue('host'),
        'port' => (int)($dsnValue('port') !== '' ? $dsnValue('port') : '3306'),
        'name' => $dsnValue('dbname'),
        'user' => (string)(getenv('MGW_TEST_MYSQL_USER') ?: ''),
        'password' => (string)(getenv('MGW_TEST_MYSQL_PASSWORD') ?: ''),
        'charset' => 'utf8mb4',
    ],
]);

$database = PdoConnectionFactory::create($config);
$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $contains, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($contains))) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$migrationDir = $root . '/database/migrations';
$expectedMigrations = count(glob($migrationDir . '/*.php') ?: []);
$runner = new MigrationRunner($database, $migrationDir);
$assertSame($expectedMigrations, $runner->migrate(false)['executed_count'], 'Current MySQL archive proof must apply every current migration');
$assertSame(0, $runner->migrate(false)['executed_count'], 'Current MySQL archive proof must be idempotent');

foreach (['mgw_legacy_payments', 'mgw_legacy_shop_orders', 'mgw_legacy_financial_transactions'] as $table) {
    $assertSame(
        $table,
        (string)$database->fetchValue(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
            ['name' => $table]
        ),
        'Historical archive table must remain available: ' . $table
    );
}

$now = '2026-09-29 11:45:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'mgw_archive_mvp24',
        'status' => 'active',
        'display_name' => 'MVP24 Archive',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);

$snapshot = json_encode([
    'id' => 'mvp24_archive_payment_1',
    'status' => 'paid',
    'room' => 'gold',
    'amount_rub' => 100,
], JSON_THROW_ON_ERROR);

$parameters = [
    'id' => 'mvp24_archive_payment_1',
    'account_ref' => 'mgw:mgw_archive_mvp24',
    'mgw_id' => 'mgw_archive_mvp24',
    'legacy_user_id' => '924001',
    'status_raw' => 'paid',
    'status_normalized' => 'completed',
    'room_raw' => 'gold',
    'asset_code' => 'gold_coin',
    'snapshot_json' => $snapshot,
    'snapshot_sha256' => hash('sha256', $snapshot),
    'archive_batch_id' => hash('sha256', 'mvp24-current-archive-proof'),
    'source_file' => 'payments.json',
    'source_index' => 0,
    'archived_at' => $now,
];

$insertPayment = static function (array $parameters) use ($database): void {
    $database->execute(
        'INSERT INTO mgw_legacy_payments (
            legacy_payment_id, account_ref, mgw_id, legacy_user_id,
            status_raw, status_normalized, room_raw, asset_code,
            snapshot_json, snapshot_sha256, archive_batch_id,
            source_file, source_index, archived_at_utc
         ) VALUES (
            :id, :account_ref, :mgw_id, :legacy_user_id,
            :status_raw, :status_normalized, :room_raw, :asset_code,
            :snapshot_json, :snapshot_sha256, :archive_batch_id,
            :source_file, :source_index, :archived_at
         )',
        $parameters
    );
};

$insertPayment($parameters);
$assertSame('gold_coin', (string)$database->fetchValue(
    'SELECT asset_code FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => $parameters['id']]
), 'Historical Gold asset identity must remain queryable in the archive');
$assertSame($parameters['snapshot_sha256'], (string)$database->fetchValue(
    'SELECT snapshot_sha256 FROM mgw_legacy_payments WHERE legacy_payment_id = :id',
    ['id' => $parameters['id']]
), 'Historical archive snapshot hash must round-trip exactly');

$duplicate = $parameters;
$duplicate['id'] = 'mvp24_archive_payment_duplicate';
$assertThrows(
    static fn() => $insertPayment($duplicate),
    'duplicate',
    'Historical archive must preserve unique source-position protection'
);

fwrite(STDOUT, "Mvp24LegacyArchiveCurrentMySqlContractTest: {$assertions} assertions passed\n");
