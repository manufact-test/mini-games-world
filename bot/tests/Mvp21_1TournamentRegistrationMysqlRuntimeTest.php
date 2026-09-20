<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/storage/contracts/StorageTransactionInterface.php';
require $root . '/storage/contracts/StorageAdapterInterface.php';
require $root . '/database/DatabaseConnectionInterface.php';
require $root . '/database/PdoDatabaseConnection.php';
require $root . '/database/DatabaseMigrationInterface.php';
require $root . '/ledger/LedgerIntegrity.php';
require $root . '/ledger/LedgerWriteService.php';
require $root . '/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $root . '/runtime/RuntimeMatchEventContext.php';
require $root . '/runtime/RuntimeMatchEventLogWriter.php';
require $root . '/runtime/DatabasePrimaryStateStorageAdapter.php';
require $root . '/tournaments/TournamentRegistrationService.php';

if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException('Mvp21_1TournamentRegistrationMysqlRuntimeTest requires pdo_mysql.');
}

$host = getenv('MGW_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MGW_TEST_MYSQL_PORT') ?: 3306);
$name = getenv('MGW_TEST_MYSQL_DATABASE') ?: 'mgw_test';
$user = getenv('MGW_TEST_MYSQL_USER') ?: 'root';
$password = getenv('MGW_TEST_MYSQL_PASSWORD') ?: 'root';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db = new PdoDatabaseConnection($pdo);

foreach ([
    'mgw_tournament_registrations',
    'mgw_tournaments',
    'mgw_reservation_events',
    'mgw_ledger_entries',
    'mgw_reservations',
    'mgw_idempotency_keys',
    'mgw_balances',
    'mgw_runtime_primary_state',
    'mgw_users',
] as $table) {
    $db->execute('DROP TABLE IF EXISTS ' . $table);
}

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

(require $root . '/database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require $root . '/database/migrations/20260920_0049_create_official_tournaments.php')->up($db);

$db->execute(<<<'SQL'
CREATE TABLE mgw_runtime_primary_state (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL,
    state_json LONGTEXT NOT NULL,
    state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc VARCHAR(40) NOT NULL,
    updated_at_utc VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
};

$mgwId = 'MGW-0123456789ABCDEF';
$legacyUserId = '777001';
$accountRef = 'legacy:' . $legacyUserId;
$db->execute(
    'INSERT INTO mgw_users (mgw_id,status) VALUES (:mgw_id,:status)',
    ['mgw_id'=>$mgwId,'status'=>'active']
);

$clock = '2026-09-20 14:00:00.000000';
$ledger = new LedgerWriteService($db, static function () use (&$clock): string {
    return $clock;
});
$ledger->postAvailableDelta([
    'operation_key'=>'mvp21:mysql:grant',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>$legacyUserId,
    'asset_code'=>TournamentRegistrationService::ENTRY_ASSET,
    'available_delta'=>154702,
    'category'=>'test_grant',
    'source_type'=>'test',
]);

$service = new TournamentRegistrationService($db, $ledger);
$draft = $service->createDraft(
    'Проверка MySQL',
    'tictactoe',
    8,
    'test:mysql',
    new DateTimeImmutable('2026-09-20T14:01:00Z')
);
$tournamentId = (string)$draft['tournament']['tournament_id'];
$service->openRegistration(
    $tournamentId,
    'test:mysql',
    new DateTimeImmutable('2026-09-20T14:02:00Z')
);

$storage = new DatabasePrimaryStateStorageAdapter($db);
$storage->initializeFromSnapshot([
    'users'=>[
        $legacyUserId=>[
            'id'=>$legacyUserId,
            'mgw_id'=>$mgwId,
            'mgw_account_ref'=>$accountRef,
            'balance'=>154702,
        ],
    ],
    'transactions'=>[],
]);

$clock = '2026-09-20 14:03:00.000000';
$result = $storage->transaction(function (array &$state) use (
    $service,
    $mgwId,
    $accountRef,
    $legacyUserId
): array {
    $snapshot = $service->register(
        $mgwId,
        $accountRef,
        new DateTimeImmutable('2026-09-20T14:03:00Z')
    );
    $state['users'][$legacyUserId]['balance'] = (int)$snapshot['balance']['available_amount'];
    return $snapshot;
});

$assertSame('registered', $result['registration']['state'], 'MySQL registration must be durable.');
$assertSame(1, $result['tournament']['registered_count'], 'MySQL registration must occupy one seat.');
$assertSame(104702, $result['balance']['available_amount'], 'Entry hold must reduce spendable amount.');
$assertSame(50000, $result['balance']['reserved_amount'], 'Entry hold must reserve exactly 50,000.');

$stateAfterRegister = $storage->readOnly(static fn(array $state): array => $state);
$assertSame(
    104702,
    $stateAfterRegister['users'][$legacyUserId]['balance'],
    'DB-primary runtime state must publish the spendable amount in the same outer transaction.'
);

$fresh = $service->snapshot($mgwId, $accountRef);
$assertSame(1, $fresh['tournament']['registered_count'], 'Fresh server snapshot must remain 1/8.');
$assertSame('registered', $fresh['registration']['state'], 'Fresh server snapshot must expose registration.');

$duplicate = $storage->transaction(function (array &$state) use (
    $service,
    $mgwId,
    $accountRef,
    $legacyUserId
): array {
    $snapshot = $service->register($mgwId, $accountRef);
    $state['users'][$legacyUserId]['balance'] = (int)$snapshot['balance']['available_amount'];
    return $snapshot;
});
$assertSame(1, $duplicate['tournament']['registered_count'], 'Duplicate click must not occupy another seat.');
$assertSame(104702, $duplicate['balance']['available_amount'], 'Duplicate click must not reserve twice.');
$assertSame(50000, $duplicate['balance']['reserved_amount'], 'Duplicate click must preserve one entry hold.');

$clock = '2026-09-20 14:04:00.000000';
$left = $storage->transaction(function (array &$state) use (
    $service,
    $mgwId,
    $accountRef,
    $legacyUserId
): array {
    $snapshot = $service->leave(
        $mgwId,
        $accountRef,
        new DateTimeImmutable('2026-09-20T14:04:00Z')
    );
    $state['users'][$legacyUserId]['balance'] = (int)$snapshot['balance']['available_amount'];
    return $snapshot;
});
$assertSame('withdrawn', $left['registration']['state'], 'Leave must persist withdrawn state.');
$assertSame(0, $left['tournament']['registered_count'], 'Leave must release the seat.');
$assertSame(154702, $left['balance']['available_amount'], 'Leave must restore the complete 50,000 entry.');
$assertSame(0, $left['balance']['reserved_amount'], 'Leave must clear tournament reserve.');

$stateAfterLeave = $storage->readOnly(static fn(array $state): array => $state);
$assertSame(
    154702,
    $stateAfterLeave['users'][$legacyUserId]['balance'],
    'DB-primary runtime state must restore spendable amount after leave.'
);

$assertSame(
    1,
    (int)$db->fetchValue(
        "SELECT COUNT(*) FROM mgw_tournament_registrations
         WHERE tournament_id=:tournament_id AND mgw_id=:mgw_id AND registration_state='withdrawn'",
        ['tournament_id'=>$tournamentId,'mgw_id'=>$mgwId]
    ),
    'Withdrawn attempt must remain durable for audit.'
);

if ($assertions < 15) {
    throw new RuntimeException('MySQL tournament runtime acceptance coverage is incomplete.');
}
fwrite(STDOUT, "Mvp21_1TournamentRegistrationMysqlRuntimeTest: {$assertions} assertions passed\n");
