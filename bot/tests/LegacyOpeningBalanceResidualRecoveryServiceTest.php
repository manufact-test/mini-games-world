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
require $root . '/accounts/MgwIdGenerator.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/ledger/LedgerIntegrityVerifier.php';
require $root . '/ledger/LegacyEconomyShadowSyncService.php';
require $root . '/ledger/LegacyOpeningBalanceImportService.php';
require $root . '/accounts/LegacyAccountImportService.php';
require $root . '/accounts/LegacyAccountOwnershipLinkService.php';
require $root . '/cutover/LegacyOpeningBalanceResidualRecoveryService.php';

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyOpeningBalanceResidualRecoveryServiceTest requires pdo_sqlite.');
}

final class LegacyOpeningResidualStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
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
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Recovery test must build all schemas');

$legacyId = '972585905';
$mgwId = 'MGW-DAAP0R0XG47R6JJ1';
$now = '2026-07-05 15:25:29.000000';
$data = [
    'users' => [
        $legacyId => [
            'id' => $legacyId,
            'telegram_id' => $legacyId,
            'first_name' => 'Production Owner',
            'username' => 'production_owner',
            'balance_match' => 3150,
            'balance_gold' => 10000,
            'registered_at' => '2026-07-05T15:25:29+00:00',
            'last_seen_at' => '2026-07-24T07:34:59+00:00',
        ],
    ],
    'transactions' => [],
    'games' => [],
    'queue' => [],
    'invites' => [],
    'notifications' => [],
    'payments' => [],
    'shop_orders' => [],
];
$storage = new LegacyOpeningResidualStorage($data);

$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username,
        created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, :username,
        :created_at_utc, :updated_at_utc, :last_seen_at_utc
     )',
    [
        'mgw_id' => $mgwId,
        'status' => 'active',
        'display_name' => 'Production Owner',
        'username' => 'production_owner',
        'created_at_utc' => $now,
        'updated_at_utc' => $now,
        'last_seen_at_utc' => '2026-07-24 07:34:59.000000',
    ]
);
$database->execute(
    'INSERT INTO mgw_identities (
        mgw_id, provider, provider_subject, provider_username,
        linked_at_utc, last_authenticated_at_utc
     ) VALUES (
        :mgw_id, :provider, :provider_subject, :provider_username,
        :linked_at_utc, :last_authenticated_at_utc
     )',
    [
        'mgw_id' => $mgwId,
        'provider' => 'telegram',
        'provider_subject' => $legacyId,
        'provider_username' => 'production_owner',
        'linked_at_utc' => $now,
        'last_authenticated_at_utc' => '2026-07-24 07:34:59.000000',
    ]
);

$assertSame(true, (new LegacyEconomyShadowSyncService($storage, $database))->run()['ok'], 'Economy shadow must sync');
$verifier = new LedgerIntegrityVerifier($database);
$oldOpening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    $verifier
);
$assertSame('completed', $oldOpening->run()['status'], 'Old identity-aware opening import must complete');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_balances WHERE account_ref = :account_ref',
    ['account_ref' => 'mgw:' . $mgwId]
), 'Old importer must reproduce two MGW-scoped balances');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_ledger_entries WHERE account_ref = :account_ref',
    ['account_ref' => 'mgw:' . $mgwId]
), 'Old importer must reproduce two MGW-scoped opening entries');
$assertSame('completed', (new LegacyAccountImportService($storage, $database))->run()['status'], 'Account import must complete');
$assertSame($mgwId, (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'legacy_import', 'subject' => $legacyId]
), 'Account import must stage the preexisting MGW ID');

$service = new LegacyOpeningBalanceResidualRecoveryService($storage, $database, $verifier);
$preview = $service->preview($legacyId, $mgwId);
$assertSame(true, $preview['ready'], 'Exact production residual must be recoverable');
$assertSame(2, $preview['balance_row_count'], 'Recovery preview must bind two balances');
$assertSame(2, $preview['ledger_row_count'], 'Recovery preview must bind two opening ledger entries');
$assertSame(2, $preview['idempotency_row_count'], 'Recovery preview must bind two idempotency rows');
$assertTrue(
    preg_match('/\A[a-f0-9]{64}\z/', (string)$preview['plan_fingerprint']) === 1,
    'Recovery preview must expose an exact plan fingerprint'
);

$beforeWrongPlan = [
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'),
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_idempotency_keys'),
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
        ['meta_key' => 'legacy_economy_opening_import_v1']
    ),
];
$assertThrows(
    static fn() => $service->run($legacyId, $mgwId, str_repeat('0', 64)),
    'fingerprint',
    'Wrong recovery plan must fail closed'
);
$afterWrongPlan = [
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_balances'),
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'),
    (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_idempotency_keys'),
    (int)$database->fetchValue(
        'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
        ['meta_key' => 'legacy_economy_opening_import_v1']
    ),
];
$assertSame($beforeWrongPlan, $afterWrongPlan, 'Wrong plan must not mutate recovery rows');

$result = $service->run($legacyId, $mgwId, (string)$preview['plan_fingerprint']);
$assertSame(true, $result['ok'], 'Exact recovery must complete');
$assertSame(2, $result['deleted']['balance_rows_deleted'], 'Recovery must delete two wrong-scope balances');
$assertSame(2, $result['deleted']['ledger_rows_deleted'], 'Recovery must delete two wrong-scope ledger entries');
$assertSame(2, $result['deleted']['idempotency_rows_deleted'], 'Recovery must delete two wrong-scope idempotency rows');
$assertSame(1, $result['deleted']['opening_meta_rows_deleted'], 'Recovery must reset opening import metadata');
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_balances WHERE legacy_user_id = :legacy_user_id',
    ['legacy_user_id' => $legacyId]
), 'Recovery target balances must be absent');
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_ledger_entries WHERE legacy_user_id = :legacy_user_id',
    ['legacy_user_id' => $legacyId]
), 'Recovery target ledger entries must be absent');
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_idempotency_keys WHERE owner_ref = :owner_ref',
    ['owner_ref' => 'mgw:' . $mgwId]
), 'Recovery target idempotency rows must be absent');
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
    ['meta_key' => 'legacy_economy_opening_import_v1']
), 'Opening import metadata must be reset');
$assertSame(1, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_meta WHERE meta_key = :meta_key',
    ['meta_key' => 'legacy_account_import_v1']
), 'Account import metadata must be preserved');
$assertSame(1, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_users WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'MGW user must be preserved');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_identities WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'Provider and staging identities must be preserved');

$forcedOpening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    $verifier,
    true
);
$forcedPreview = $forcedOpening->preview();
$assertSame(true, $forcedPreview['ready'], 'Cleaned opening import must be ready');
$assertSame(2, $forcedPreview['planned_balance_create_count'], 'Cleaned opening import must plan two balances');
$assertSame(2, $forcedPreview['planned_ledger_create_count'], 'Cleaned opening import must plan two ledger entries');
$assertSame('completed', $forcedOpening->run()['status'], 'Forced legacy opening import must complete');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_balances WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:' . $legacyId]
), 'Recovered balances must be recreated under the legacy account');
$assertSame(0, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_balances WHERE account_ref = :account_ref',
    ['account_ref' => 'mgw:' . $mgwId]
), 'Recovered balances must not return to the MGW account scope');

$ownership = new LegacyAccountOwnershipLinkService($storage, $database, $verifier);
$ownershipPreview = $ownership->preview();
$assertSame(true, $ownershipPreview['ready'], 'Ownership must be ready after recovery and reimport');
$assertSame(1, $ownershipPreview['planned_ownership_create_count'], 'Ownership must plan one row');
$assertSame(0, $ownershipPreview['planned_provider_identity_create_count'], 'Existing provider identity must be reused');
$ownershipResult = $ownership->run();
$assertSame(true, $ownershipResult['verification']['ok'], 'Ownership verification must pass after recovery');
$assertSame($mgwId, (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_account_ownership WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:' . $legacyId]
), 'Ownership must link the recovered legacy account to the existing MGW ID');
$assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'match_coin')['ok'], 'Recovered Match chain must verify');
$assertSame(true, $verifier->verifyAccountAsset('legacy:' . $legacyId, 'gold_coin')['ok'], 'Recovered Gold chain must verify');

$repeatPreview = $service->preview($legacyId, $mgwId);
$assertSame(false, $repeatPreview['ready'], 'Completed recovery must not be executable again');
$assertTrue($repeatPreview['blocking_reasons'] !== [], 'Repeat recovery must explain why it is blocked');

fwrite(
    STDOUT,
    "LegacyOpeningBalanceResidualRecoveryServiceTest: {$assertions} assertions passed\n"
);
