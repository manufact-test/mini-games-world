<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../ledger/LedgerIntegrity.php';
require_once __DIR__ . '/../ledger/LedgerWriteService.php';
require_once __DIR__ . '/../ledger/LedgerIntegrityVerifier.php';
require_once __DIR__ . '/../economy/CompensationService.php';

$dsn = trim((string)getenv('MGW_COMPENSATION_MYSQL_DSN'));
$user = (string)getenv('MGW_COMPENSATION_MYSQL_USER');
$pass = (string)getenv('MGW_COMPENSATION_MYSQL_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "Mvp22_2CompensationMySqlIntegrationTest skipped: no DSN.\n");
    return;
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db = new PdoDatabaseConnection($pdo);

$tables = [
    'mgw_compensations',
    'mgw_reservation_events',
    'mgw_ledger_entries',
    'mgw_reservations',
    'mgw_idempotency_keys',
    'mgw_balances',
    'mgw_users',
];
$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

$db->execute(<<<'SQL'
CREATE TABLE mgw_users (
    mgw_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    nickname VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

(require __DIR__ . '/../database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require __DIR__ . '/../database/migrations/20260925_0062_create_compensations.php')->up($db);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$mgwId = 'MGW-0000000000000022';
$accountRef = 'mgw:' . $mgwId;
$db->execute('INSERT INTO mgw_users (mgw_id,nickname) VALUES (:id,:nickname)', [
    'id'=>$mgwId,
    'nickname'=>'MySQL Comp',
]);

$ledger = new LedgerWriteService($db);
$ledger->postAvailableDelta([
    'operation_key'=>'mysql-comp-seed',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'asset_code'=>'mgw_coin',
    'available_delta'=>120000,
    'category'=>'test_seed',
    'source_type'=>'test',
    'source_ref'=>'mvp22.2',
]);
$original = $ledger->postAvailableDelta([
    'operation_key'=>'mysql-original-operation',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'asset_code'=>'mgw_coin',
    'available_delta'=>-20000,
    'category'=>'store_purchase',
    'source_type'=>'store',
    'source_ref'=>'mysql-purchase',
]);

$service = new CompensationService($db, $ledger);
$pending = $service->requestCompensation(
    'mysql-original-operation',
    50000,
    'MySQL second-confirmation proof',
    'telegram:mysql-admin',
    'mysql-request-large'
);
$assert($pending['status'] === 'pending_confirmation', 'MySQL large compensation must wait for confirmation.');
$assert($ledger->getBalance($accountRef, 'mgw_coin')['available_amount'] === 100000, 'Pending MySQL compensation must not change balance.');

$applied = $service->confirm((string)$pending['compensation_id'], 'telegram:mysql-admin');
$assert($applied['status'] === 'applied', 'MySQL confirmed compensation must be applied.');
$assert($applied['available_after'] === 150000, 'MySQL compensation must use canonical ledger balance.');

$entryCount = (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$service->confirm((string)$pending['compensation_id'], 'telegram:mysql-admin');
$assert((int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries') === $entryCount, 'MySQL confirm retry must not duplicate ledger entry.');

$small = $service->requestCompensation(
    (string)$original['entry_id'],
    1234,
    'MySQL immediate compensation proof',
    'telegram:mysql-admin',
    'mysql-request-small'
);
$assert($small['status'] === 'applied', 'MySQL small compensation must apply immediately.');
$assert($small['available_after'] === 151234, 'MySQL small compensation amount must reconcile.');

$integrity = (new LedgerIntegrityVerifier($db))->verifyAccountAsset($accountRef, 'mgw_coin');
$assert($integrity['ok'] === true, 'MySQL compensation must preserve ledger integrity.');
$assert(count($service->history(10)) === 2, 'MySQL compensation audit must retain two records.');

$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $db->execute('DROP TABLE IF EXISTS ' . $table);
$db->execute('SET FOREIGN_KEY_CHECKS=1');

fwrite(STDOUT, "MVP-22.2 compensation MySQL 8.4 integration OK.\n");
