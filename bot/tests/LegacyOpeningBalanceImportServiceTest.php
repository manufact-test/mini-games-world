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
    throw new RuntimeException('LegacyOpeningBalanceImportServiceTest requires pdo_sqlite.');
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
$assertSame(7, $runner->migrate(false)['executed_count'], 'Opening import test must create all schemas');

$now = '2026-07-17 17:00:00.000000';
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
        'display_name' => 'Opening Import',
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

$writeShadow = static function (string $legacyUserId, int $match, int $gold) use ($database): void {
    $payload = [
        'legacy_user_id' => $legacyUserId,
        'telegram_id' => $legacyUserId,
        'balance_match' => $match,
        'balance_gold' => $gold,
        'registered_at' => '2026-07-01T10:00:00+00:00',
        'last_seen_at' => '2026-07-17T16:59:00+00:00',
        'source_record_sha256' => hash('sha256', 'source|' . $legacyUserId . '|' . $match . '|' . $gold),
    ];
    $payloadJson = LedgerIntegrity::canonicalJson($payload);
    $parameters = [
        'entity_type' => 'economy_user_balance',
        'entity_key' => $legacyUserId,
        'payload_json' => $payloadJson,
        'payload_sha256' => hash('sha256', $payloadJson),
        'source_updated_at_utc' => '2026-07-17 16:59:00.000000',
        'synced_at_utc' => '2026-07-17 17:00:00.000000',
    ];
    $database->execute(
        'INSERT INTO mgw_legacy_realtime_shadow (
            entity_type, entity_key, payload_json, payload_sha256,
            source_updated_at_utc, synced_at_utc
         ) VALUES (
            :entity_type, :entity_key, :payload_json, :payload_sha256,
            :source_updated_at_utc, :synced_at_utc
         ) ON CONFLICT(entity_type, entity_key) DO UPDATE SET
            payload_json = excluded.payload_json,
            payload_sha256 = excluded.payload_sha256,
            source_updated_at_utc = excluded.source_updated_at_utc,
            synced_at_utc = excluded.synced_at_utc',
        $parameters
    );
};
$writeShadow('1001', 70, 25);
$writeShadow('1002', 40, 0);

$ledger = new LedgerWriteService($database);
$verifier = new LedgerIntegrityVerifier($database);
$service = new LegacyOpeningBalanceImportService($database, $ledger, $verifier);

$preview = $service->preview();
$assertSame(true, $preview['ready'], 'Fresh opening import must be ready');
$assertSame('not_started', $preview['status'], 'Fresh opening import must not have metadata');
$assertSame(2, $preview['source_user_count'], 'Two source users must be detected');
$assertSame(4, $preview['source_asset_count'], 'Two assets per user must be planned');
$assertSame(['match_coin' => 110, 'gold_coin' => 25], $preview['source_totals'], 'Source totals must remain separate');
$assertSame(4, $preview['planned_balance_create_count'], 'Every source asset must receive a balance row');
$assertSame(3, $preview['planned_ledger_create_count'], 'Only non-zero source assets need opening ledger entries');
$assertSame(0, $preview['conflict_count'], 'Fresh import must have no conflicts');

$first = $service->run();
$assertSame(true, $first['ok'], 'Opening import must complete');
$assertSame(4, $first['created_balance_count'], 'Opening import must create four balance rows');
$assertSame(3, $first['created_ledger_count'], 'Opening import must create three opening ledger entries');
$assertSame(0, $first['replayed_ledger_count'], 'First opening import must not replay entries');
$assertSame(4, $first['verification']['verified_chain_count'], 'Every imported account asset must be verified');
$assertSame(['match_coin' => 110, 'gold_coin' => 25], $first['verification']['database_totals'], 'Imported totals must equal source totals');
$assertSame(4, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'), 'Balance table must contain exactly four imported rows');
$assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Ledger must contain exactly three opening entries');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_idempotency_keys WHERE operation_type = 'available_delta'"), 'Every non-zero opening entry must be idempotent');
$assertSame(70, (int)$database->fetchValue(
    'SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'mgw:' . $mgwId, 'asset_code' => 'match_coin']
), 'Mapped user Match balance must import under MGW-ID');
$assertSame(0, (int)$database->fetchValue(
    'SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'legacy:1002', 'asset_code' => 'gold_coin']
), 'Zero Gold balance must remain a separate zero row');

$repeatPreview = $service->preview();
$assertSame(true, $repeatPreview['ready'], 'Completed identical import must remain safe to inspect');
$assertSame('completed', $repeatPreview['status'], 'Metadata must record completed import');
$assertSame(4, $repeatPreview['unchanged_count'], 'All imported account assets must be unchanged');
$assertSame(0, $repeatPreview['planned_balance_create_count'], 'Repeat preview must plan no balance writes');
$assertSame(0, $repeatPreview['planned_ledger_create_count'], 'Repeat preview must plan no ledger writes');

$repeat = $service->run();
$assertSame(0, $repeat['created_balance_count'], 'Repeat run must not create balances');
$assertSame(0, $repeat['created_ledger_count'], 'Repeat run must not create ledger entries');
$assertSame(3, $repeat['replayed_ledger_count'], 'Repeat run must replay all non-zero opening operations');
$assertSame(3, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Repeat run must not duplicate ledger entries');

$writeShadow('1001', 80, 25);
$changed = $service->preview();
$assertSame(false, $changed['ready'], 'Changed source after completed import must fail closed');
$assertTrue(in_array('Opening import metadata belongs to a different source fingerprint.', $changed['blocking_reasons'], true), 'Changed source fingerprint must be reported');
$assertThrows(static fn() => $service->run(), 'not ready', 'Changed source must not mutate imported balances');
$assertSame(70, (int)$database->fetchValue(
    'SELECT available_amount FROM mgw_balances WHERE account_ref = :account_ref AND asset_code = :asset_code',
    ['account_ref' => 'mgw:' . $mgwId, 'asset_code' => 'match_coin']
), 'Blocked changed source must leave the imported balance unchanged');

$writeShadow('1001', 70, 25);
$database->execute(
    'INSERT INTO mgw_balances (
        account_ref, mgw_id, legacy_user_id, asset_code,
        available_amount, reserved_amount, version, created_at_utc, updated_at_utc
     ) VALUES (
        :account_ref, NULL, :legacy_user_id, :asset_code,
        0, 0, 0, :created_at, :updated_at
     )',
    [
        'account_ref' => 'legacy:unmanaged',
        'legacy_user_id' => 'unmanaged',
        'asset_code' => 'match_coin',
        'created_at' => $now,
        'updated_at' => $now,
    ]
);
$unmanaged = $service->preview();
$assertSame(false, $unmanaged['ready'], 'Unmanaged balances must block opening import replay');
$assertSame(1, $unmanaged['unmanaged_balance_count'], 'Unmanaged balance must be counted');

$database->execute(
    'DELETE FROM mgw_balances WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:unmanaged']
);
$database->execute(
    "UPDATE mgw_legacy_realtime_shadow SET payload_sha256 = :payload_sha256
     WHERE entity_type = 'economy_user_balance' AND entity_key = '1001'",
    ['payload_sha256' => str_repeat('0', 64)]
);
$assertThrows(static fn() => $service->preview(), 'hash mismatch', 'Corrupted economy shadow must fail closed');

fwrite(STDOUT, "LegacyOpeningBalanceImportServiceTest: {$assertions} assertions passed\n");
