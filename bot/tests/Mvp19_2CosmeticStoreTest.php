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

$assertSame(9, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_status = 'active' AND offer_type = 'item' AND category = 'profile' AND subcategory = 'avatars'"), 'Active Store must expose exactly nine paid avatar offers');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_status = 'active' AND offer_type = 'item' AND price_coins = 250"), 'Rare tier must contain exactly three 250-coin avatars');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_status = 'active' AND offer_type = 'item' AND price_coins = 300"), 'Elite tier must contain exactly three 300-coin avatars');
$assertSame(3, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_status = 'active' AND offer_type = 'item' AND price_coins = 400"), 'Legendary tier must contain exactly three 400-coin avatars');
$assertSame('retired', (string)$database->fetchValue("SELECT offer_status FROM mgw_product_offers WHERE offer_id = 'avatar-bundle-5'"), 'Superseded five-avatar bundle must remain retired');

$accountService = new AccountIdentityService($database, 3600);
$account = $accountService->resolveProviderIdentity('development', 'mvp19-2-user', 'browser_dev', ['username' => 'store-test'], 'mvp19-2-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(3, count($inventory->snapshot($mgwId)['owned']), 'Store test account must begin with exactly three starter avatars');
$snapshot = $store->snapshot($mgwId, 3000, [
    ['id'=>'coins_5000','coins'=>5000,'price_eur_cents'=>499,'enabled'=>true],
]);
$assertSame(['coins','profile','games','bundles'], array_column($snapshot['tabs'], 'id'), 'Store v2 must expose only the four purchasable canonical tabs in order');
$assertSame(9, count($snapshot['profile']['avatars']), 'Profile Store tab must expose all nine paid avatar offers');
$assertSame(true, $snapshot['games']['available'] ?? false, 'Games Store tab must expose the active cosmetics framework pilot');
$assertSame(4, count($snapshot['games']['catalogs']['tictactoe']['themes'] ?? []), 'Tic Tac Toe pilot must expose four field themes');
$assertSame(4, count($snapshot['games']['catalogs']['tictactoe']['elements'] ?? []), 'Tic Tac Toe pilot must expose four mark sets');
$assertSame(3, count($snapshot['games']['catalogs']['tictactoe']['effects'] ?? []), 'Tic Tac Toe pilot must expose three effects');
$assertSame(3, count($snapshot['inventory']['items']), 'Inventory must expose the three starter ownership rows before purchases');
$assertSame(null, $snapshot['bundles']['avatar_bundle'], 'Retired avatar bundle must not be exposed as an active Store offer');
$assertSame(34000, $snapshot['bundles']['tictactoe_bundle']['price_coins'] ?? null, 'Tic Tac Toe premium bundle must preserve the canonical 34,000 price');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'], 'Purchase must never auto-equip');
$assertSame(false, $snapshot['coins']['billing_available'], 'Real billing callbacks must remain outside MVP-19.2');

$singleQuote = $store->quote($mgwId, 'avatar-01');
$assertSame(250, $singleQuote['price_coins'], 'Rare avatar quote must be exactly 250 coins');
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
$assertSame(2750, $user['balance'], 'Rare avatar runtime debit must subtract exactly 250 coins');
$assertSame('debited', $prepared['intent']['status'], 'Runtime purchase must remain pending until durable ownership is written');

$replay = $runtime->prepare($runtimeStorage->data, $user, $singleQuote, 'store:test-single-0001');
$assertSame(true, $replay['replayed_runtime'], 'Same request token must replay the existing runtime intent');
$assertSame(2750, $user['balance'], 'Exact runtime replay must not debit balance twice');
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

$refundQuote = $store->quote($mgwId, 'avatar-04');
$user =& $runtimeStorage->data['users']['legacy-store-user'];
$refundPrepared = $runtime->prepare($runtimeStorage->data, $user, $refundQuote, 'store:test-refund-0003');
$assertSame(2450, $user['balance'], 'Elite purchase intent must debit exactly 300 coins');
$assertSame(true, $runtime->refund('legacy-store-user', 'store:test-refund-0003'), 'Pending intent must support one safe compensating refund');
$assertSame(2750, $runtimeStorage->data['users']['legacy-store-user']['balance'], 'Compensating refund must restore the exact debited amount');
$assertSame(false, $runtime->refund('legacy-store-user', 'store:test-refund-0003'), 'Compensating refund must be idempotent');
$assertSame(false, $inventory->isOwned($mgwId, 'store-avatar-04'), 'Refund without fulfillment must not invent ownership');

$legendaryQuote = $store->quote($mgwId, 'avatar-07');
$assertSame(400, $legendaryQuote['price_coins'], 'Legendary avatar quote must be exactly 400 coins');
$user =& $runtimeStorage->data['users']['legacy-store-user'];
$legendaryPrepared = $runtime->prepare($runtimeStorage->data, $user, $legendaryQuote, 'store:test-legendary-0004');
$assertSame(2350, $user['balance'], 'Legendary avatar runtime debit must subtract exactly 400 coins');
$legendaryPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-store-user', $legendaryPrepared['intent']);
$runtime->markCompleted('legacy-store-user', 'store:test-legendary-0004');
$assertSame(400, $legendaryPurchase['price_coins'], 'Durable legendary purchase audit must preserve the approved price');
$assertSame(true, $inventory->isOwned($mgwId, 'store-avatar-07'), 'Legendary purchase must create permanent ownership');
$assertSame(2, (int)$database->fetchValue('SELECT COUNT(*) FROM mgw_cosmetic_purchases WHERE mgw_id = :mgw_id', ['mgw_id'=>$mgwId]), 'Two completed avatar purchases must produce exactly two durable audit rows');
$assertSame('starter-default-01', $inventory->snapshot($mgwId)['equipped']['profile_avatar'] ?? null, 'Purchases must never auto-equip a new avatar');

$assertStoreError(static fn() => $store->quote($mgwId, 'avatar-bundle-5'), 'offer_unavailable', 'Retired five-avatar bundle must not be purchasable');

$gameQuote = $store->quote($mgwId, 'ttt-field-dark');
$gameRuntimeStorage = new Mvp19_2MemoryStorage([
    'users' => [
        'legacy-game-store-user' => [
            'id' => 'legacy-game-store-user',
            'mgw_id' => $mgwId,
            'mgw_account_ref' => 'mgw:' . $mgwId,
            'balance' => 20000,
        ],
    ],
    'transactions' => [],
]);
$gameRuntime = new CosmeticStoreRuntimePurchaseService($gameRuntimeStorage);
$gameUser =& $gameRuntimeStorage->data['users']['legacy-game-store-user'];
$gamePrepared = $gameRuntime->prepare($gameRuntimeStorage->data, $gameUser, $gameQuote, 'store:test-game-cosmetic-0005');
$assertSame(['game-ttt-field-dark'], $gamePrepared['intent']['item_ids'], 'Runtime purchase preparation must accept canonical game cosmetic item IDs');
$assertSame(15000, $gameUser['balance'], 'Game cosmetic runtime debit must preserve the quoted Store price');

$finalSnapshot = $store->snapshot($mgwId, 2350, []);
$assertSame(9, count($finalSnapshot['profile']['avatars']), 'Store snapshot must continue exposing all nine active paid avatar offers');
$assertSame(null, $finalSnapshot['bundles']['avatar_bundle'], 'Retired bundle must remain absent from active Store snapshot');
$assertSame(5, count($finalSnapshot['inventory']['items']), 'Inventory Store tab must expose three starters plus the two completed paid purchases');
$ownedOffers = [];
foreach ($finalSnapshot['profile']['avatars'] as $offer) {
    if (!empty($offer['already_owned'])) $ownedOffers[] = (string)$offer['offer_id'];
}
sort($ownedOffers, SORT_STRING);
$assertSame(['avatar-01','avatar-07'], $ownedOffers, 'Store snapshot must mark only completed paid purchases as owned');

fwrite(STDOUT, "PASS: MVP-19.2 Store purchase mechanics on active MVP-19.3 avatar economy ({$assertions} assertions)\n");
