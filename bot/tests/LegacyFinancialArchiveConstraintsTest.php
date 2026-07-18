<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyFinancialArchiveConstraintsTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Archive constraint test must include seven migrations');

$now = '2026-07-17 20:30:00.000000';
$basePayment = [
    'legacy_payment_id' => 'pay_independent_1',
    'account_ref' => 'mgw:deleted-user',
    'mgw_id' => 'deleted-user',
    'legacy_user_id' => '972585905',
    'status_raw' => 'paid',
    'status_normalized' => 'completed',
    'balance_applied' => 1,
    'snapshot_json' => '{"id":"pay_independent_1"}',
    'snapshot_sha256' => hash('sha256', '{"id":"pay_independent_1"}'),
    'archive_batch_id' => hash('sha256', 'archive-constraint-batch'),
    'source_file' => 'payments.json',
    'source_index' => 0,
    'archived_at_utc' => $now,
];
$insertPayment = static function (array $row) use ($database): void {
    $database->execute(
        'INSERT INTO mgw_legacy_payments (
            legacy_payment_id, account_ref, mgw_id, legacy_user_id,
            status_raw, status_normalized, balance_applied,
            snapshot_json, snapshot_sha256, archive_batch_id,
            source_file, source_index, archived_at_utc
         ) VALUES (
            :legacy_payment_id, :account_ref, :mgw_id, :legacy_user_id,
            :status_raw, :status_normalized, :balance_applied,
            :snapshot_json, :snapshot_sha256, :archive_batch_id,
            :source_file, :source_index, :archived_at_utc
         )',
        $row
    );
};

$insertPayment($basePayment);
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_payments'), 'Archive rows must not require a mutable user row');

$invalidStatus = $basePayment;
$invalidStatus['legacy_payment_id'] = 'pay_invalid_status';
$invalidStatus['source_index'] = 1;
$invalidStatus['status_normalized'] = 'silently_misclassified';
$assertThrows(static fn() => $insertPayment($invalidStatus), 'check', 'Unknown normalized labels must be rejected');

$invalidBoolean = $basePayment;
$invalidBoolean['legacy_payment_id'] = 'pay_invalid_boolean';
$invalidBoolean['source_index'] = 2;
$invalidBoolean['balance_applied'] = 2;
$assertThrows(static fn() => $insertPayment($invalidBoolean), 'check', 'Archive booleans must be limited to zero or one');

$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_legacy_payments'), 'Rejected rows must not alter the archive');

fwrite(STDOUT, "LegacyFinancialArchiveConstraintsTest passed: {$assertions} assertions.\n");
