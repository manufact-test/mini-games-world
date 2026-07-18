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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyAccountOwnershipLinkServiceTest requires pdo_sqlite.');
}

final class LegacyOwnershipArrayStorage implements StorageAdapterInterface
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
$canonicalRows = static function (array $rows): string {
    return LedgerIntegrity::canonicalJson($rows);
};

$data = [
    'users' => [
        '972585905' => [
            'id' => '972585905',
            'telegram_id' => '972585905',
            'is_dev_user' => false,
            'first_name' => 'Илья',
            'username' => 'legacy_player',
            'balance_match' => 30,
            'balance_gold' => 0,
            'registered_at' => '2026-07-17T10:00:00+00:00',
            'last_seen_at' => '2026-07-17T11:00:00+00:00',
        ],
    ],
    'transactions' => [[
        'id' => 'tx_game_1',
        'category' => 'match_result',
        'user_id' => '972585905',
        'amount' => 10,
        'created_at' => '2026-07-17T10:10:00+00:00',
    ]],
    'games' => [],
    'queue' => [],
    'invites' => [],
    'notifications' => [],
    'payments' => [],
    'shop_orders' => [],
];

$storage = new LegacyOwnershipArrayStorage($data);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(7, $runner->migrate(false)['executed_count'], 'Ownership link test must apply all migrations');

$economy = new LegacyEconomyShadowSyncService($storage, $database);
$assertSame(true, $economy->run()['ok'], 'Economy shadow must be prepared');
$verifier = new LedgerIntegrityVerifier($database);
$opening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    $verifier
);
$assertSame('completed', $opening->run()['status'], 'Opening balances must be imported first');
$accounts = new LegacyAccountImportService($storage, $database);
$assertSame('completed', $accounts->run()['status'], 'Provider-neutral account must be staged first');

$mgwId = (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'legacy_import', 'subject' => '972585905']
);
$assertTrue(MgwIdGenerator::isValid($mgwId), 'Staged account must have a valid MGW ID');
$assertSame(0, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_identities WHERE provider IN ('telegram', 'development')"
), 'Real provider identity must not exist before ownership linking');

$balanceSnapshot = $canonicalRows($database->fetchAll(
    'SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount,
            reserved_amount, version, created_at_utc, updated_at_utc
     FROM mgw_balances ORDER BY account_ref, asset_code'
));
$ledgerSnapshot = $canonicalRows($database->fetchAll(
    'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
            asset_code, available_delta, reserved_delta, available_before, available_after,
            reserved_before, reserved_after, category, source_type, source_ref, reservation_id,
            metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
     FROM mgw_ledger_entries ORDER BY ledger_sequence'
));

$now = '2026-07-18 12:00:00.000000';
$database->execute(
    'INSERT INTO mgw_users (
        mgw_id, status, display_name, username, created_at_utc, updated_at_utc, last_seen_at_utc
     ) VALUES (
        :mgw_id, :status, :display_name, NULL, :created_at, :updated_at, :last_seen_at
     )',
    [
        'mgw_id' => 'MGW-CONFLICT0000001',
        'status' => 'active',
        'display_name' => 'Conflict',
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
        'mgw_id' => 'MGW-CONFLICT0000001',
        'provider' => 'telegram',
        'provider_subject' => '972585905',
        'linked_at' => $now,
        'authenticated_at' => $now,
    ]
);

$service = new LegacyAccountOwnershipLinkService($storage, $database, $verifier);
$conflict = $service->preview();
$assertSame(false, $conflict['ready'], 'Provider identity owned by another MGW account must block linking');
$assertSame(1, $conflict['conflict_count'], 'Provider identity conflict must be counted');
$assertTrue(
    str_contains(implode(' ', $conflict['blocking_reasons']), 'different MGW account'),
    'Provider identity conflict must be explicit'
);
$database->execute(
    'DELETE FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'telegram', 'subject' => '972585905']
);
$database->execute(
    'DELETE FROM mgw_users WHERE mgw_id = :mgw_id',
    ['mgw_id' => 'MGW-CONFLICT0000001']
);

$preview = $service->preview();
$assertSame(true, $preview['ready'], 'Clean ownership preview must be ready');
$assertSame('not_started', $preview['status'], 'Fresh ownership link must not have metadata');
$assertSame(1, $preview['source_user_count'], 'One source user must be planned');
$assertSame(1, $preview['planned_ownership_create_count'], 'One ownership row must be planned');
$assertSame(1, $preview['planned_provider_identity_create_count'], 'One provider identity must be planned');
$assertSame(0, $preview['conflict_count'], 'Clean preview must have no conflicts');
$assertSame(0, $preview['unmanaged_ownership_count'], 'Clean preview must have no unmanaged ownership rows');

$first = $service->run();
$assertSame('completed', $first['status'], 'Ownership linking must complete');
$assertSame(1, $first['created_ownership_count'], 'First run must create one ownership row');
$assertSame(1, $first['created_provider_identity_count'], 'First run must create one provider identity');
$assertSame(0, $first['reused_provider_identity_count'], 'First run must not reuse provider identity');
$assertSame(true, $first['verification']['ok'], 'Final ownership verification must pass');
$assertSame(1, $first['verification']['verified_user_count'], 'One user must verify');

$ownership = $database->fetchAll(
    'SELECT account_ref, mgw_id, legacy_user_id, ownership_status,
            source_type, source_ref, source_sha256
     FROM mgw_account_ownership WHERE account_ref = :account_ref',
    ['account_ref' => 'legacy:972585905']
)[0] ?? [];
$assertSame($mgwId, (string)($ownership['mgw_id'] ?? ''), 'Ownership must resolve legacy account to the staged MGW ID');
$assertSame('972585905', (string)($ownership['legacy_user_id'] ?? ''), 'Ownership must retain legacy user ID');
$assertSame('active', (string)($ownership['ownership_status'] ?? ''), 'Ownership must be active');
$assertSame('legacy_json', (string)($ownership['source_type'] ?? ''), 'Ownership source must remain explicit');
$assertSame('users.json:972585905', (string)($ownership['source_ref'] ?? ''), 'Ownership source reference must be stable');
$assertSame(64, strlen((string)($ownership['source_sha256'] ?? '')), 'Ownership must store a source hash');

$assertSame($mgwId, (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'telegram', 'subject' => '972585905']
), 'Real Telegram identity must link to the same MGW ID');
$assertSame(1, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'legacy_import', 'subject' => '972585905']
), 'Internal legacy_import identity must remain as provenance');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_devices'), 'Ownership linking must not create devices');
$assertSame(0, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_sessions'), 'Ownership linking must not create sessions');
$assertSame($balanceSnapshot, $canonicalRows($database->fetchAll(
    'SELECT account_ref, mgw_id, legacy_user_id, asset_code, available_amount,
            reserved_amount, version, created_at_utc, updated_at_utc
     FROM mgw_balances ORDER BY account_ref, asset_code'
)), 'Ownership linking must not alter balances');
$assertSame($ledgerSnapshot, $canonicalRows($database->fetchAll(
    'SELECT ledger_sequence, entry_id, idempotency_key, account_ref, mgw_id, legacy_user_id,
            asset_code, available_delta, reserved_delta, available_before, available_after,
            reserved_before, reserved_after, category, source_type, source_ref, reservation_id,
            metadata_json, previous_entry_sha256, entry_sha256, created_at_utc
     FROM mgw_ledger_entries ORDER BY ledger_sequence'
)), 'Ownership linking must not rewrite immutable ledger history');
$assertSame(true, $verifier->verifyAccountAsset('legacy:972585905', 'match_coin')['ok'], 'Match ledger must remain valid');
$assertSame(true, $verifier->verifyAccountAsset('legacy:972585905', 'gold_coin')['ok'], 'Gold ledger must remain valid');

$repeat = $service->run();
$assertSame(0, $repeat['created_ownership_count'], 'Repeat must not create ownership rows');
$assertSame(0, $repeat['created_provider_identity_count'], 'Repeat must not create identities');
$assertSame(1, $repeat['reused_provider_identity_count'], 'Repeat must reuse the existing provider identity');
$assertSame(1, $repeat['unchanged_user_count'], 'Repeat must verify one unchanged user');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_account_ownership'), 'Repeat must preserve one ownership row');
$assertSame(2, (int)$database->fetchValue(
    'SELECT COUNT(*) FROM mgw_identities WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'Repeat must preserve internal and real identities only');

$changed = $data;
$changed['users']['972585905']['username'] = 'changed_after_link';
$storage->replace($changed);
$assertThrows(
    static fn() => $service->preview(),
    'differs from the verified economy shadow',
    'Unsynchronized JSON drift must fail closed'
);

fwrite(STDOUT, "LegacyAccountOwnershipLinkServiceTest passed: {$assertions} assertions.\n");
