<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/database/MigrationRepository.php';
require $root . '/database/MigrationRunner.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyOpeningBalancePostOwnershipReplayTest requires pdo_sqlite.');
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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Replay test must create all schemas');

$legacyUserId = '1002';
$payload = [
    'legacy_user_id' => $legacyUserId,
    'telegram_id' => $legacyUserId,
    'balance_match' => 40,
    'balance_gold' => 0,
    'registered_at' => '2026-07-01T10:00:00+00:00',
    'last_seen_at' => '2026-07-17T16:59:00+00:00',
    'source_record_sha256' => hash('sha256', 'source|' . $legacyUserId),
];
$payloadJson = LedgerIntegrity::canonicalJson($payload);
$database->execute(
    'INSERT INTO mgw_legacy_realtime_shadow (
        entity_type, entity_key, payload_json, payload_sha256,
        source_updated_at_utc, synced_at_utc
     ) VALUES (
        :entity_type, :entity_key, :payload_json, :payload_sha256,
        :source_updated_at_utc, :synced_at_utc
     )',
    [
        'entity_type' => 'economy_user_balance',
        'entity_key' => $legacyUserId,
        'payload_json' => $payloadJson,
        'payload_sha256' => hash('sha256', $payloadJson),
        'source_updated_at_utc' => '2026-07-17 16:59:00.000000',
        'synced_at_utc' => '2026-07-17 17:00:00.000000',
    ]
);

$service = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    new LedgerIntegrityVerifier($database),
    true
);

$first = $service->run();
$assertSame('completed', $first['status'], 'Opening import must complete before ownership linking');
$assertSame(2, $first['created_balance_count'], 'Opening import must create two asset balances');
$assertSame(1, $first['created_ledger_count'], 'Only the positive balance must create a ledger entry');
$assertSame(null, $database->fetchValue(
    'SELECT mgw_id FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:' . $legacyUserId, 'asset_code' => 'match_coin']
), 'Pre-ownership balance metadata must have no MGW ID');
$assertSame(null, $database->fetchValue(
    'SELECT mgw_id FROM mgw_ledger_entries WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:' . $legacyUserId, 'asset_code' => 'match_coin']
), 'Pre-ownership ledger history must have no MGW ID');

$mgwId = 'MGW-1123456789ABCDEF';
$now = '2026-07-18 12:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Post Ownership Replay',
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
        'provider_subject' => $legacyUserId,
        'linked_at' => $now,
        'authenticated_at' => $now,
    ]
);

$postOwnershipPreview = $service->preview();
$assertSame(true, $postOwnershipPreview['ready'], 'Completed opening import must remain inspectable after identity linking');
$assertSame('completed', $postOwnershipPreview['status'], 'Opening import metadata must remain completed');
$assertSame(2, $postOwnershipPreview['unchanged_count'], 'Both historical asset rows must remain unchanged');
$assertSame(0, $postOwnershipPreview['conflict_count'], 'Identity linking must not create opening import conflicts');
$assertSame([], $postOwnershipPreview['blocking_reasons'], 'Identity linking must not block opening import replay');

$repeat = $service->run();
$assertSame(0, $repeat['created_balance_count'], 'Post-ownership replay must not create balances');
$assertSame(0, $repeat['created_ledger_count'], 'Post-ownership replay must not create ledger entries');
$assertSame(1, $repeat['replayed_ledger_count'], 'Post-ownership replay must reuse the historical positive operation');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), 'Post-ownership replay must preserve balance count');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Post-ownership replay must preserve ledger count');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_idempotency_keys'), 'Post-ownership replay must preserve idempotency count');
$assertSame(null, $database->fetchValue(
    'SELECT mgw_id FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:' . $legacyUserId, 'asset_code' => 'match_coin']
), 'Ownership linking must not rewrite historical balance metadata');
$assertSame(null, $database->fetchValue(
    'SELECT mgw_id FROM mgw_ledger_entries WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:' . $legacyUserId, 'asset_code' => 'match_coin']
), 'Ownership linking must not rewrite immutable ledger history');
$assertSame(true, (new LedgerIntegrityVerifier($database))->verifyAccountAsset(
    'legacy:' . $legacyUserId,
    'match_coin'
)['ok'], 'Post-ownership replay must preserve ledger integrity');

fwrite(STDOUT, "LegacyOpeningBalancePostOwnershipReplayTest: {$assertions} assertions passed\n");
