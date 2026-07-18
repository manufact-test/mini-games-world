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
require $root . '/migration/LegacyOpeningBalanceOwnershipReconciliationService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyOpeningBalanceOwnershipReconciliationServiceTest requires pdo_sqlite.');
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
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, (int)$runner->migrate(false)['executed_count'], 'All seven migrations must be applied');

$legacyUserId = '972585905';
$mgwId = 'MGW-N67MQT6P7GQM842Y';
$now = '2026-07-18 12:00:00.000000';
$payload = [
    'legacy_user_id' => $legacyUserId,
    'telegram_id' => $legacyUserId,
    'balance_match' => 30,
    'balance_gold' => 0,
    'registered_at' => '2026-07-01T10:00:00+00:00',
    'last_seen_at' => '2026-07-18T11:59:00+00:00',
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
        'source_updated_at_utc' => $now,
        'synced_at_utc' => $now,
    ]
);

$opening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    new LedgerIntegrityVerifier($database)
);
$first = $opening->run();
$assertSame(true, $first['ok'], 'Opening balances must be imported before ownership linking');
$assertSame(2, $first['created_balance_count'], 'Two asset balances must be imported');
$assertSame(1, $first['created_ledger_count'], 'Only non-zero Match must create an opening entry');

$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Ownership Reconciliation',
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
$database->execute(
    'INSERT INTO mgw_account_ownership (
        account_ref, mgw_id, legacy_user_id, ownership_status,
        source_type, source_ref, source_sha256, created_at_utc, verified_at_utc
     ) VALUES (
        :account_ref, :mgw_id, :legacy_user_id, :ownership_status,
        :source_type, :source_ref, :source_sha256, :created_at, :verified_at
     )',
    [
        'account_ref' => 'legacy:' . $legacyUserId,
        'mgw_id' => $mgwId,
        'legacy_user_id' => $legacyUserId,
        'ownership_status' => 'active',
        'source_type' => 'legacy_json',
        'source_ref' => 'users.json:' . $legacyUserId,
        'source_sha256' => hash('sha256', 'ownership|' . $legacyUserId),
        'created_at' => $now,
        'verified_at' => $now,
    ]
);

$service = new LegacyOpeningBalanceOwnershipReconciliationService($database);
$preview = $service->preview();
$assertSame(true, $preview['ok'], 'Ownership link must not invalidate historical opening rows');
$assertSame(true, $preview['ready'], 'Ownership-aware opening reconciliation must be ready');
$assertSame('completed', $preview['status'], 'Opening import status must remain completed');
$assertSame(2, $preview['unchanged_count'], 'Both Match and Gold rows must be unchanged');
$assertSame(0, $preview['conflict_count'], 'No ownership-related false conflict is allowed');
$assertSame(0, $preview['unmanaged_balance_count'], 'Legacy balance rows must remain managed');
$assertSame(0, $preview['unmanaged_ledger_count'], 'Immutable legacy opening entry must remain managed');
$assertSame([], $preview['blocking_reasons'], 'Valid ownership mapping must leave no blockers');
$assertSame(
    'legacy:' . $legacyUserId,
    $preview['items'][0]['account_ref'],
    'Stable legacy account reference must be preserved'
);
$assertSame(
    null,
    $database->fetchValue(
        'SELECT mgw_id FROM mgw_ledger_entries WHERE account_ref = :account_ref',
        ['account_ref' => 'legacy:' . $legacyUserId]
    ),
    'Ownership reconciliation must not rewrite immutable ledger MGW-ID'
);

$database->execute(
    'UPDATE mgw_balances SET available_amount = 31
     WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:' . $legacyUserId, 'asset_code' => 'match_coin']
);
$drift = $service->preview();
$assertSame(false, $drift['ok'], 'Balance drift must still fail closed');
$assertSame(1, $drift['conflict_count'], 'Exactly one changed asset must conflict');

fwrite(STDOUT, "LegacyOpeningBalanceOwnershipReconciliationServiceTest passed: {$assertions} assertions.\n");
