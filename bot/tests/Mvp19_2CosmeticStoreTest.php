<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dbDir = $root . '/bot/database';
require_once $dbDir . '/DatabaseConnectionInterface.php';
require_once $dbDir . '/DatabaseExceptionClassifier.php';
require_once $dbDir . '/PdoDatabaseConnection.php';
require_once $dbDir . '/DatabaseMigrationInterface.php';
require_once $dbDir . '/MigrationRepository.php';
require_once $dbDir . '/MigrationRunner.php';
require_once $root . '/bot/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/bot/catalog/CosmeticStoreService.php';
require_once $root . '/bot/catalog/CosmeticStoreRuntimePurchaseService.php';

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_mvp19_2_' . $sequence;
    }
}

final class Mvp19_2MemoryStorage implements StorageTransactionInterface
{
    public function __construct(public array $data) {}

    public function transaction(callable $callback): mixed
    {
        $copy = $this->data;
        $result = $callback($copy);
        $this->data = $copy;
        return $result;
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->data);
    }
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
$assertStoreError = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (CosmeticStoreException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no store error was thrown');
};

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.2 Store test requires pdo_sqlite.');
$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $dbDir . '/migrations');
$migration = $runner->migrate(false);
$expectedMigrations = count(glob($dbDir . '/migrations/*.php') ?: []);
$assertSame($expectedMigrations, (int)$migration['executed_count'], 'Store fixture must apply the current additive migration set');

$assertSame(6, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_status = 'active'"), 'Launch Store must seed five avatar offers plus one bundle');
$assertSame(5, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'item' AND price_coins = 300"), 'Every paid launch avatar must cost exactly 300 coins');
$assertSame(1200, (int)$database->fetchValue("SELECT price_coins FROM mgw_product_offers WHERE offer_id = 'avatar-bundle-5'"), 'Full avatar bundle must cost exactly 1200 coins');
$assertSame(240, (int)$database->fetchValue("SELECT partial_unit_price_coins FROM mgw_product_offers WHERE offer_id = 'avatar-bundle-5'"), 'Partial avatar bundle must cost 240 per missing avatar');

$accountService = new AccountIdentityService($database, 3600);
$account = $accountService->resolveProviderIdentity('development', 'mvp19-2-user', 'browser_dev', ['username' => 'store-test'], 'mvp19-2-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(3, count($inventory->snapshot($mgwId)['owned']), 'Store test account must begin with exactly three starter avatars');
$snapshot = $store->snapshot($mgwId, 3000, [
    ['id'=>'coins_5000','coins'=>5000,'price_eur_cents'=>499,'enabled'=>true],
]);
$assertSame(['coins','profile','games','bundles','inventory','tournament_rewards'], array_column($snapshot['tabs'], 'id'), 'Store v2 must expose the six canonical tabs in order');
$assertSame(5, count($snapshot['profile']['avatars']), 'Profile Store tab must expose all five paid avatar offers');
$assertSame(3, count($snapshot['inventory']['items']), 'Inventory must expose the three starter ownership rows before purchases');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'], 'Purchase must never auto-equip');
$assertSame(false, $snapshot['coins']['billing_available'], 'Real billing callbacks must remain outside MVP-19.2');

$singleQuote = $store->quote($mgwId, 'avatar-01');
$assertSame(300, $singleQuote['price_coins'], 'Single avatar quote must be exactly 300 coins');
$assertSame(['store-avatar-01'], $singleQuote['item_ids'], 'Single avatar offer must own exactly its canonical item');

$runtimeStorage = new Mvp19_2MemoryStorage([
    'users' => [
        'legacy-store-user' => [
            'id' => 'legacy-store-user',
            'mgw_id' => $mgwId,
            'mgw_account_ref' => 'mgw:' . $mgwId,
            'balance' => 3000,
        ],
    ],
    'transactions' => [],
]);
$runtime = new CosmeticStoreRuntimePurchaseService($runtimeStorage);
$user =& $runtimeStorage->data['users']['legacy-store-user'];
$prepared = $runtime->prepare($runtimeStorage->data, $user, $singleQuote, 'store:test-single-0001');
$assertSame(false, $prepared['replayed_runtime'], 'First request token must create a runtime purchase intent');
$assertSame(2700, $user['balance'], 'Single avatar runtime debit must subtract exactly 300 coins');
$assertSame('debited', $prepared['intent']['status'], 'Runtime purchase must remain pending until durable ownership is written');

$replay = $runtime->prepare($runtimeStorage->data, $user, $singleQuote, 'store:test-single-0001');
$assertSame(true, $replay['replayed_runtime'], 'Same request token must replay the existing runtime intent');
$assertSame(2700, $user['balance'], 'Exact runtime replay must not debit balance twice');
$assertStoreError(
    static function () use ($runtime, &$runtimeStorage, &$user, $singleQuote): void {
        $runtime->prepare($runtimeStorage->data, $user, $singleQuote, 'store:test-overlap-0002');
    },
    'purchase_in_progress',
    'Different token must not double-debit an item while its first purchase is pending'
);

$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-store-user', $prepared['intent']);
$runtime->markCompleted('legacy-store-user', 'store:test-single-0001');
$assertSame(false, $purchase['replayed'], 'First durable fulfillment must not be marked as replay');
$assertSame(true, $inventory->isOwned($mgwId, 'store-avatar-01'), 'Completed purchase must create permanent ownership');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_cosmetic_purchases WHERE mgw_id = :mgw_id', ['mgw_id'=>$mgwId]), 'Completed purchase must create one durable audit row');
$assertSame('starter-default-01', $inventory->snapshot($mgwId)['equipped']['profile_avatar'] ?? null, 'Purchase must not change the active avatar');

$purchaseReplay = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-store-user', $prepared['intent']);
$assertSame(true, $purchaseReplay['replayed'], 'Durable same-token fulfillment must be idempotent');
$assertSame(1, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_cosmetic_purchases WHERE mgw_id = :mgw_id', ['mgw_id'=>$mgwId]), 'Durable replay must not create a second purchase audit row');
$assertStoreError(static fn() => $store->quote($mgwId, 'avatar-01'), 'already_owned', 'Already-owned individual avatar must not be purchasable again');

$refundQuote = $store->quote($mgwId, 'avatar-02');
$user =& $runtimeStorage->data['users']['legacy-store-user'];
$refundPrepared = $runtime->prepare($runtimeStorage->data, $user, $refundQuote, 'store:test-refund-0003');
$assertSame(2400, $user['balance'], 'Second intent must debit before fulfillment');
$assertSame(true, $runtime->refund('legacy-store-user', 'store:test-refund-0003'), 'Pending intent must support one safe compensating refund');
$assertSame(2700, $runtimeStorage->data['users']['legacy-store-user']['balance'], 'Compensating refund must restore the exact debited amount');
$assertSame(false, $runtime->refund('legacy-store-user', 'store:test-refund-0003'), 'Compensating refund must be idempotent');
$assertSame(false, $inventory->isOwned($mgwId, 'store-avatar-02'), 'Refund without fulfillment must not invent ownership');

$bundleQuote = $store->quote($mgwId, 'avatar-bundle-5');
$assertSame(960, $bundleQuote['price_coins'], 'Owning one paid avatar must reduce bundle to 4 × 240 = 960');
$assertSame(4, count($bundleQuote['item_ids']), 'Partial bundle must include only missing avatar IDs');
$user =& $runtimeStorage->data['users']['legacy-store-user'];
$bundlePrepared = $runtime->prepare($runtimeStorage->data, $user, $bundleQuote, 'store:test-bundle-0004');
$assertSame(1740, $user['balance'], 'Partial bundle must debit exactly 960 coins');
$bundlePurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-store-user', $bundlePrepared['intent']);
$runtime->markCompleted('legacy-store-user', 'store:test-bundle-0004');
$assertSame(960, $bundlePurchase['price_coins'], 'Durable partial bundle audit must preserve the dynamic approved price');
$assertSame(8, count($inventory->snapshot($mgwId)['owned']), 'After bundle completion account must own 3 starter + 5 paid avatars');
$assertSame('starter-default-01', $inventory->snapshot($mgwId)['equipped']['profile_avatar'] ?? null, 'Bundle purchase must not auto-equip any new avatar');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_cosmetic_purchases WHERE mgw_id = :mgw_id', ['mgw_id'=>$mgwId]), 'Single + bundle must produce exactly two durable purchase rows');

$finalSnapshot = $store->snapshot($mgwId, 1740, []);
$assertTrue(array_reduce($finalSnapshot['profile']['avatars'], static fn(bool $carry, array $offer): bool => $carry && !empty($offer['already_owned']), true), 'All five avatar offers must become owned after bundle completion');
$assertSame(true, $finalSnapshot['bundles']['avatar_bundle']['already_owned'], 'Full bundle must become owned when all five avatars are present');
$assertSame(0, $finalSnapshot['bundles']['avatar_bundle']['price_coins'], 'Owned bundle must not expose a second purchase price');
$assertSame(8, count($finalSnapshot['inventory']['items']), 'Inventory Store tab must expose all eight owned launch avatars');
$assertStoreError(static fn() => $store->quote($mgwId, 'avatar-bundle-5'), 'already_owned', 'Completed bundle must not be repurchasable');

fwrite(STDOUT, "PASS: MVP-19.2 cosmetic Store foundation ({$assertions} assertions)\n");
