<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('AccountOwnershipMigrationTest requires pdo_sqlite.');
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$result = $runner->migrate(false);
$assertSame(7, (int)$result['executed_count'], 'All seven migrations must be applied');

$now = '2026-07-18 10:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'MGW-OWNERSHIP00001',
        'status' => 'active',
        'display_name' => 'Ownership Test',
        'created_at' => $now,
        'updated_at' => $now,
        'last_seen_at' => $now,
    ]
);
$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, provider, provider_subject,
        ownership_status, source_type, linked_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :provider, :provider_subject,
        :ownership_status, :source_type, :linked_at, :verified_at
     )',
    [
        'account_ref' => 'legacy:972585905',
        'mgw_id' => 'MGW-OWNERSHIP00001',
        'legacy_user_id' => '972585905',
        'provider' => 'telegram',
        'provider_subject' => '972585905',
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'linked_at' => $now,
        'verified_at' => $now,
    ]
);

$assertSame(
    'MGW-OWNERSHIP00001',
    (string)$database->fetchValue(
        'SELECT mgw_id FROM mgw_account_ownership WHERE account_ref = :account_ref',
        ['account_ref' => 'legacy:972585905']
    ),
    'Stable legacy account reference must resolve to its MGW user'
);
$assertThrows(
    static function () use ($database, $now): void {
        $database->execute(
            'INSERT INTO mgw_account_ownership (
                account_ref, mgw_id, legacy_user_id, provider, provider_subject,
                ownership_status, source_type, linked_at_utc, verified_at_utc
             ) VALUES (
                :account_ref, :mgw_id, :legacy_user_id, :provider, :provider_subject,
                :ownership_status, :source_type, :linked_at, :verified_at
             )',
            [
                'account_ref' => 'legacy:duplicate',
                'mgw_id' => 'MGW-OWNERSHIP00001',
                'legacy_user_id' => 'duplicate',
                'provider' => 'telegram',
                'provider_subject' => 'duplicate',
                'ownership_status' => 'active',
                'source_type' => 'legacy_json',
                'linked_at' => $now,
                'verified_at' => $now,
            ]
        );
    },
    'One MGW user must not own multiple canonical account references'
);
$assertThrows(
    static function () use ($database, $now): void {
        $database->execute(
            'INSERT INTO mgw_account_ownership (
                account_ref, mgw_id, legacy_user_id, provider, provider_subject,
                ownership_status, source_type, linked_at_utc, verified_at_utc
             ) VALUES (
                :account_ref, :mgw_id, :legacy_user_id, :provider, :provider_subject,
                :ownership_status, :source_type, :linked_at, :verified_at
             )',
            [
                'account_ref' => 'legacy:missing-user',
                'mgw_id' => 'MGW-NOTFOUND000001',
                'legacy_user_id' => 'missing-user',
                'provider' => 'telegram',
                'provider_subject' => 'missing-user',
                'ownership_status' => 'active',
                'source_type' => 'legacy_json',
                'linked_at' => $now,
                'verified_at' => $now,
            ]
        );
    },
    'Ownership must reject an unknown MGW user'
);

fwrite(STDOUT, "AccountOwnershipMigrationTest passed: {$assertions} assertions.\n");
