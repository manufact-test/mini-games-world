<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$databaseDir = $root . '/bot/database';
require_once $databaseDir . '/DatabaseConnectionInterface.php';
require_once $databaseDir . '/DatabaseExceptionClassifier.php';
require_once $databaseDir . '/PdoDatabaseConnection.php';
require_once $databaseDir . '/DatabaseMigrationInterface.php';
require_once $databaseDir . '/MigrationRepository.php';
require_once $databaseDir . '/MigrationRunner.php';
require_once $root . '/bot/accounts/MgwIdGenerator.php';
require_once $root . '/bot/accounts/MgwIdentityPolicy.php';
require_once $root . '/bot/catalog/ProductInventoryService.php';
require_once $root . '/bot/accounts/AccountIdentityService.php';
require_once $root . '/bot/catalog/CosmeticStoreService.php';

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 name-color test requires pdo_sqlite.');

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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every additive migration');

$rows = $database->fetchAll(
    "SELECT c.item_id, c.item_type, c.item_family, c.equip_slot, c.starter_grant, c.metadata_json,
            o.offer_id, o.price_coins, o.category, o.subcategory
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'name_color' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(3, count($rows), 'Name-color launch catalogue must contain exactly three active items');
$assertSame([500,1000,2500], array_map('intval', array_column($rows, 'price_coins')), 'Name-color prices must match canonical normal/rare/gradient tiers');
$assertSame(['profile_name_color'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'All name colors must share one mutually exclusive profile slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Name colors must remain profile products');
$assertSame(['name_color'], array_values(array_unique(array_column($rows, 'item_family'))), 'Name colors must remain isolated from avatars and game cosmetics');
$assertSame([0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Paid name colors must never be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Name-color offers must stay in the Store Profile category');
$assertSame(['name_color'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Name-color offers must use their own Store subcategory');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame(['normal','rare','gradient'], array_column($metadata, 'tier'), 'Name-color metadata must preserve canonical tier order');
$assertSame(['sky','gold','aurora'], array_column($metadata, 'variant'), 'Name-color visual variants must remain deterministic');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-name-colors-user', 'browser_dev', ['username'=>'name-colors'], 'mvp19-3-name-colors-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $store->snapshot($mgwId, 100000, []);
$assertSame(3, count($snapshot['profile']['name_colors'] ?? []), 'Store Profile tab must expose all three name colors');
$assertSame(null, $snapshot['inventory']['equipped']['profile_name_color'] ?? null, 'Fresh account must not have a paid name color selected');

$quote = $store->quote($mgwId, 'name-color-sky');
$assertSame(500, (int)$quote['price_coins'], 'Normal name-color quote must remain 500 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-name-color-user', [
    'request_token' => 'store:mvp19-3-name-color-0001',
    'offer_id' => 'name-color-sky',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Name-color purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_name_color'] ?? null, 'Purchased name color must remain inactive until explicit equip');
$inventory->equip($mgwId, 'profile-name-color-sky');
$assertSame('profile-name-color-sky', $inventory->snapshot($mgwId)['equipped']['profile_name_color'] ?? null, 'Explicit equip must use ProductInventoryService');

$goldQuote = $store->quote($mgwId, 'name-color-gold');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-name-color-user', [
    'request_token' => 'store:mvp19-3-name-color-0002',
    'offer_id' => 'name-color-gold',
    'price_coins' => $goldQuote['price_coins'],
    'item_ids' => $goldQuote['item_ids'],
]);
$assertSame('profile-name-color-sky', $inventory->snapshot($mgwId)['equipped']['profile_name_color'] ?? null, 'Buying another color must not replace current equipment');
$inventory->equip($mgwId, 'profile-name-color-gold');
$assertSame('profile-name-color-gold', $inventory->snapshot($mgwId)['equipped']['profile_name_color'] ?? null, 'Choosing another color must replace the previous item in the same slot');
$inventory->unequip($mgwId, 'profile_name_color');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_name_color']), 'Name-color slot must allow a plain-name state after unequip');
$assertStoreError(static fn() => $store->quote($mgwId, 'name-color-sky'), 'already_owned', 'Owned name color must reject duplicate purchase without compensation');

$endpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$response = (string)file_get_contents($root . '/bot/helpers/response.php');
$storeSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$profileSource = (string)file_get_contents($root . '/app/assets/js/screens/profile-screen-v110.js');
$presentationSource = (string)file_get_contents($root . '/app/assets/js/profile/mgw-avatar-presentation.js');
$nameColorCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-name-colors.css');
$manifestSource = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($endpoint, "item_family'] ?? '') === 'name_color'") && str_contains($endpoint, "'profile_name_color'"), 'Store mutation endpoint must whitelist only the canonical profile name-color family and slot');
$assertTrue(str_contains($response, "e.equip_slot = \\'profile_name_color\\'") && str_contains($response, "\$player['name_color_item_id']"), 'Shared game identity projection must expose equipped name color for human players');
$assertTrue(str_contains($presentationSource, 'player?.name_color_item_id') && str_contains($presentationSource, 'name.dataset.nameColorItemId'), 'Shared player-card presentation must consume the canonical name-color projection');
$assertTrue(str_contains($storeSource, 'storeState?.profile?.name_colors') && str_contains($storeSource, 'data-name-color-item-id'), 'Store Profile tab must render server-owned name-color offers using the shared visual attribute');
$assertTrue(str_contains($profileSource, 'ownedNameColorItems()') && str_contains($profileSource, "api.cosmeticStoreUnequip('profile_name_color')"), 'Profile collection must expose owned-only name colors and canonical equip/unequip transport');
$assertTrue(!str_contains($profileSource, 'cosmeticStorePurchase'), 'Profile must never become a purchase owner');
$assertTrue(str_contains($nameColorCss, '[data-name-color-item-id="profile-name-color-sky"]') && str_contains($nameColorCss, '[data-name-color-item-id="profile-name-color-aurora"]'), 'Store, Profile and game player cards must share one name-color CSS owner');
$assertTrue(str_contains($manifestSource, 'mvp19_3_4=name-colors'), 'Active v110 delivery graph must carry a fresh name-color cache identity');

fwrite(STDOUT, "MVP-19.3 profile name colors passed ({$assertions} assertions).\n");
