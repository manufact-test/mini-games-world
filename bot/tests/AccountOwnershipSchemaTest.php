<?php
declare(strict_types=1);

$databaseDir = dirname(__DIR__) . '/database';
require $databaseDir . '/DatabaseConnectionInterface.php';
require $databaseDir . '/PdoDatabaseConnection.php';
require $databaseDir . '/DatabaseMigrationInterface.php';
require $databaseDir . '/MigrationRepository.php';
require $databaseDir . '/MigrationRunner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('AccountOwnershipSchemaTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message . ': no error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Ownership schema test must include seven migrations');
$assertSame(0, $runner->migrate(false)['executed_count'], 'Repeated ownership migration must be idempotent');

$assertSame(
    'mgw_account_ownership',
    (string)$database->fetchValue(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'mgw_account_ownership'"
    ),
    'Ownership table must exist'
);

$now = '2026-07-18 10:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'MGW-0123456789ABCDEF',
        'status' => 'active',
        'display_name' => 'Ownership User',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status,
        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
        :source_type, :source_ref, :source_sha256, :created_at, :verified_at
     )',
    [
        'account_ref' => 'legacy:972585905',
        'mgw_id' => 'MGW-0123456789ABCDEF',
        'legacy_user_id' => '972585905',
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:972585905',
        'source_sha256' => hash('sha256', 'legacy-user-972585905'),
        'created_at' => $now,
        'verified_at' => $now,
    ]
);

$row = $database->fetchAll(
    'SELECT account_ref, mgw_id, legacy_user_id, ownership_status
     FROM mgw_account_ownership WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:972585905']
)[0] ?? null;
$assertSame('MGW-0123456789ABCDEF', (string)($row['mgw_id'] ?? ''), 'Ownership must preserve MGW-ID');
$assertSame('972585905', (string)($row['legacy_user_id'] ?? ''), 'Ownership must preserve legacy user ID');
$assertSame('active', (string)($row['ownership_status'] ?? ''), 'Ownership must be active');

$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_account_ownership (
            account_ref, mgw_id, legacy_user_id, ownership_status,
            source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
            :source_type, :source_ref, :source_sha256, :created_at, :verified_at
         )',
        [
            'account_ref' => 'legacy:duplicate-mgw',
            'mgw_id' => 'MGW-0123456789ABCDEF',
            'legacy_user_id' => 'duplicate-mgw',
            'ownership_status' => 'active',
            'source_type' => 'legacy_json',
            'source_ref' => 'users.json:duplicate-mgw',
            'source_sha256' => hash('sha256', 'duplicate-mgw'),
            'created_at' => $now,
            'verified_at' => $now,
        ]
    ),
    'One MGW-ID must not own two legacy account refs'
);

$assertThrows(
    static fn() => $database->execute(
        'INSERT INTO mgw_account_ownership (
            account_ref, mgw_id, legacy_user_id, ownership_status,
            source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
         ) VALUES (
            :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
            :source_type, :source_ref, :source_sha256, :created_at, :verified_at
         )',
        [
            'account_ref' => 'legacy:invalid-owner',
            'mgw_id' => 'MGW-NOT-EXISTS0000',
            'legacy_user_id' => 'invalid-owner',
            'ownership_status' => 'active',
            'source_type' => 'legacy_json',
            'source_ref' => 'users.json:invalid-owner',
            'source_sha256' => hash('sha256', 'invalid-owner'),
            'created_at' => $now,
            'verified_at' => $now,
        ]
    ),
    'Ownership must require an existing MGW user'
);

fwrite(STDOUT, "AccountOwnershipSchemaTest passed: {$assertions} assertions.\n");
