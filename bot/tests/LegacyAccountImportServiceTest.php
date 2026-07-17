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

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('LegacyAccountImportServiceTest requires pdo_sqlite.');
}

final class LegacyAccountImportArrayStorage implements StorageAdapterInterface
{
    public function __construct(private array $data) {}
    public function setData(array $data): void { $this->data = $data; }
    public function transaction(callable $callback): mixed { return $callback($this->data); }
    public function readOnly(callable $callback): mixed { $snapshot = $this->data; return $callback($snapshot); }
    public function driver(): string { return 'json'; }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
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
        if (str_contains($error->getMessage(), $contains)) return;
        throw new RuntimeException($message . ': unexpected error ' . $error->getMessage());
    }
    throw new RuntimeException($message . ': exception was not thrown');
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

$storage = new LegacyAccountImportArrayStorage($data);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $root . '/database/migrations');
$assertSame(6, $runner->migrate(false)['executed_count'], 'Account import test must apply all migrations');

$economy = new LegacyEconomyShadowSyncService($storage, $database);
$assertSame(true, $economy->run()['ok'], 'Economy shadow must be prepared');
$opening = new LegacyOpeningBalanceImportService(
    $database,
    new LedgerWriteService($database),
    new LedgerIntegrityVerifier($database)
);
$assertSame('completed', $opening->run()['status'], 'Opening balances must be imported before accounts');

$service = new LegacyAccountImportService($storage, $database);
$preview = $service->preview();
$assertSame(true, $preview['ready'], 'Account preview must be ready');
$assertSame(1, $preview['planned_user_create_count'], 'One MGW user must be planned');
$assertSame(1, $preview['planned_legacy_link_create_count'], 'One staging identity must be planned');
$assertSame(0, $preview['conflict_count'], 'Preview must have no conflicts');

$first = $service->run();
$assertSame('completed', $first['status'], 'Account import must complete');
$assertSame(1, $first['created_user_count'], 'First run must create one user');
$assertSame(1, $first['created_legacy_link_count'], 'First run must create one legacy link');
$assertSame(true, $first['verification']['ok'], 'Account verification must pass');

$mgwId = (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'legacy_import', 'subject' => '972585905']
);
$assertTrue(MgwIdGenerator::isValid($mgwId), 'Imported user must receive a valid opaque MGW ID');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_users'), 'Exactly one MGW user must exist');
$assertSame(0, (int)$database->fetchValue(
    "SELECT COUNT(*) FROM mgw_identities WHERE provider IN ('telegram', 'development')"
), 'Real provider identity must remain untouched until ownership mapping');
$assertSame('Илья', (string)$database->fetchValue(
    'SELECT display_name FROM mgw_users WHERE mgw_id = :mgw_id',
    ['mgw_id' => $mgwId]
), 'Display name must be preserved');

$openingAfterAccounts = $opening->preview();
$assertSame(true, $openingAfterAccounts['ready'], 'Staged account mapping must not invalidate opening balances');
$assertSame(2, $openingAfterAccounts['unchanged_count'], 'Both Match and Gold opening balances must remain unchanged');
$assertSame(0, $openingAfterAccounts['unmanaged_balance_count'], 'No balance may become unmanaged');
$assertSame(0, $openingAfterAccounts['unmanaged_ledger_count'], 'No ledger entry may become unmanaged');

$repeat = $service->run();
$assertSame(0, $repeat['created_user_count'], 'Repeat must not create another user');
$assertSame(0, $repeat['created_legacy_link_count'], 'Repeat must not create another link');
$assertSame(1, $repeat['unchanged_user_count'], 'Repeat must verify the existing user');
$assertSame($mgwId, (string)$database->fetchValue(
    'SELECT mgw_id FROM mgw_identities WHERE provider = :provider AND provider_subject = :subject',
    ['provider' => 'legacy_import', 'subject' => '972585905']
), 'Repeat must preserve the same MGW ID');

$changed = $data;
$changed['users']['972585905']['username'] = 'changed_after_shadow';
$storage->setData($changed);
$assertThrows(
    static fn() => $service->preview(),
    'differs from the verified economy shadow',
    'Unsynchronized JSON drift must fail closed'
);

fwrite(STDOUT, "LegacyAccountImportServiceTest passed: {$assertions} assertions.\n");
