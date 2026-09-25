<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/DatabaseConnectionInterface.php';
require_once __DIR__ . '/../database/DatabaseExceptionClassifier.php';
require_once __DIR__ . '/../database/PdoDatabaseConnection.php';
require_once __DIR__ . '/../database/DatabaseMigrationInterface.php';
require_once __DIR__ . '/../ledger/LedgerIntegrity.php';
require_once __DIR__ . '/../ledger/LedgerWriteService.php';
require_once __DIR__ . '/../ledger/LedgerIntegrityVerifier.php';
require_once __DIR__ . '/../storage/contracts/StorageTransactionInterface.php';
require_once __DIR__ . '/../economy/UnifiedBalanceRuntimeState.php';
require_once __DIR__ . '/../economy/CompensationService.php';

final class Mvp22_2MemoryStorage implements StorageTransactionInterface
{
    public function __construct(public array $data) {}

    public function transaction(callable $callback): mixed
    {
        $working = $this->data;
        $result = $callback($working);
        $this->data = $working;
        return $result;
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->data);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$db = new PdoDatabaseConnection($pdo);

$db->execute('CREATE TABLE mgw_users (
    mgw_id TEXT PRIMARY KEY,
    nickname TEXT NULL
)');

(require __DIR__ . '/../database/migrations/20260717_0005_create_balances_ledger_reservations.php')->up($db);
(require __DIR__ . '/../database/migrations/20260925_0062_create_compensations.php')->up($db);

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
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
};

$mgwId = 'MGW-0000000000000001';
$legacyUserId = '101';
$accountRef = 'mgw:' . $mgwId;
$db->execute('INSERT INTO mgw_users (mgw_id,nickname) VALUES (:mgw_id,:nickname)', [
    'mgw_id'=>$mgwId,
    'nickname'=>'Comp Tester',
]);

$ledger = new LedgerWriteService($db);
$ledger->postAvailableDelta([
    'operation_key'=>'mvp22-2:seed',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>$legacyUserId,
    'asset_code'=>'mgw_coin',
    'available_delta'=>100000,
    'category'=>'test_seed',
    'source_type'=>'test',
    'source_ref'=>'mvp22.2',
]);

$original = $ledger->postAvailableDelta([
    'operation_key'=>'purchase:compensation-fixture',
    'account_ref'=>$accountRef,
    'mgw_id'=>$mgwId,
    'legacy_user_id'=>$legacyUserId,
    'asset_code'=>'mgw_coin',
    'available_delta'=>-12000,
    'category'=>'store_purchase',
    'source_type'=>'store',
    'source_ref'=>'purchase-fixture',
]);

$runtime = new Mvp22_2MemoryStorage([
    'users'=>[
        $legacyUserId=>[
            'id'=>$legacyUserId,
            'mgw_id'=>$mgwId,
            UnifiedBalanceRuntimeState::FIELD=>88000,
        ],
    ],
    'transactions'=>[],
]);
$service = new CompensationService($db, $ledger, $runtime);
$limits = $service->limits();
$assertSame(50000, $limits['large_amount_threshold'], 'Large compensation threshold must stay explicit.');
$assertSame(250000, $limits['max_amount'], 'Maximum compensation amount must stay bounded.');

$lookup = $service->lookupOperation('purchase:compensation-fixture');
$assertSame((string)$original['entry_id'], $lookup['entry_id'], 'Lookup must resolve an exact canonical ledger operation.');
$assertSame(88000, $lookup['current_available_amount'], 'Lookup must expose current canonical balance.');
$assertSame('Comp Tester', $lookup['nickname'], 'Lookup must expose the canonical player nickname.');

$small = $service->requestCompensation(
    'purchase:compensation-fixture',
    5000,
    'Возврат за ошибочное списание',
    'telegram:admin-1',
    'request-small-1'
);
$assertSame('applied', $small['status'], 'Small compensation must apply immediately.');
$assertSame(false, $small['requires_second_confirmation'], 'Small compensation must not require second confirmation.');
$assertSame(88000, $small['available_before'], 'Small compensation ledger before must be exact.');
$assertSame(93000, $small['available_after'], 'Small compensation must increase available balance through ledger.');
$assertTrue(str_starts_with((string)$small['ledger_entry_id'], 'led_'), 'Applied compensation must retain its canonical ledger entry.');
$assertSame(93000, $runtime->data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD], 'Small compensation must project the ledger result to runtime balance.');
$assertSame(1, count($runtime->data['transactions']), 'Small compensation must create one runtime audit transaction.');

$ledgerCountAfterSmall = (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$smallRetry = $service->requestCompensation(
    (string)$original['entry_id'],
    5000,
    'Возврат за ошибочное списание',
    'telegram:admin-1',
    'request-small-1'
);
$assertSame('applied', $smallRetry['status'], 'Request-token retry must return the same applied compensation.');
$assertSame($ledgerCountAfterSmall, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Request retry must not duplicate ledger delta.');
$assertSame(1, count($runtime->data['transactions']), 'Request retry must not duplicate runtime compensation.');

$large = $service->requestCompensation(
    'purchase:compensation-fixture',
    50000,
    'Крупная компенсация после проверки',
    'telegram:admin-1',
    'request-large-1'
);
$assertSame('pending_confirmation', $large['status'], 'Large compensation must stop before balance mutation.');
$assertSame(true, $large['requires_second_confirmation'], 'Large compensation must require a second confirmation.');
$assertSame(93000, $ledger->getBalance($accountRef, 'mgw_coin')['available_amount'], 'Pending large compensation must not change balance.');
$assertSame(93000, $runtime->data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD], 'Pending large compensation must not change runtime balance.');

$confirmed = $service->confirm((string)$large['compensation_id'], 'telegram:admin-1');
$assertSame('applied', $confirmed['status'], 'Second confirmation must apply the compensation.');
$assertSame(93000, $confirmed['available_before'], 'Large compensation must preserve ledger before amount.');
$assertSame(143000, $confirmed['available_after'], 'Large compensation must increase canonical balance exactly once.');
$assertSame(143000, $runtime->data['users'][$legacyUserId][UnifiedBalanceRuntimeState::FIELD], 'Large compensation must project to runtime balance after confirmation.');
$assertSame(2, count($runtime->data['transactions']), 'Two applied compensations must have two runtime audit rows.');

$countAfterLarge = (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries');
$confirmRetry = $service->confirm((string)$large['compensation_id'], 'telegram:admin-1');
$assertSame('applied', $confirmRetry['status'], 'Confirmation retry must be idempotent.');
$assertSame($countAfterLarge, (int)$db->fetchValue('SELECT COUNT(*) FROM mgw_ledger_entries'), 'Confirmation retry must not duplicate ledger entry.');
$assertSame(2, count($runtime->data['transactions']), 'Confirmation retry must not duplicate runtime transaction.');

$lookupAfter = $service->lookupOperation((string)$original['entry_id']);
$assertSame(55000, $lookupAfter['applied_compensation_total'], 'Original operation must expose total applied compensations.');

$history = $service->history(10);
$assertSame(2, count($history), 'Compensation history must keep both audit records.');
$assertSame('applied', $history[0]['status'], 'Latest compensation audit must be applied.');

$assertThrows(
    fn() => $service->requestCompensation(
        (string)$original['entry_id'],
        250001,
        'Превышение лимита',
        'telegram:admin-1',
        'request-too-large'
    ),
    'Compensation above the maximum must be rejected.'
);
$assertThrows(
    fn() => $service->requestCompensation(
        (string)$original['entry_id'],
        100,
        '',
        'telegram:admin-1',
        'request-no-reason'
    ),
    'Compensation without a reason must be rejected.'
);
$assertThrows(
    fn() => $service->requestCompensation(
        (string)$small['ledger_entry_id'],
        100,
        'Нельзя компенсировать компенсацию',
        'telegram:admin-1',
        'request-chain'
    ),
    'Compensation-on-compensation must be rejected.'
);

$integrity = (new LedgerIntegrityVerifier($db))->verifyAccountAsset($accountRef, 'mgw_coin');
$assertSame(true, $integrity['ok'], 'Compensation workflow must preserve ledger hash/arithmetic integrity.');
$assertSame(143000, $integrity['balance']['available_amount'], 'Ledger verifier must see the exact final balance.');

$directMutation = $db->fetchValue(
    "SELECT COUNT(*) FROM mgw_ledger_entries WHERE category='admin_compensation' AND source_type='admin_compensation'"
);
$assertSame(2, (int)$directMutation, 'Exactly two accepted compensations must exist as canonical ledger entries.');

fwrite(STDOUT, "MVP-22.2 compensation workflow OK ($assertions assertions, sqlite).\n");
