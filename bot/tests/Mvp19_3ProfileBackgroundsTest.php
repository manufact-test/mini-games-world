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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 profile background test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
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
     WHERE c.item_family = 'background' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(4, count($rows), 'Background launch catalogue must contain exactly four active items');
$assertSame([2000,4000,7500,12500], array_map('intval', array_column($rows, 'price_coins')), 'Background prices must match canonical tiers');
$assertSame(['profile_background'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'Backgrounds must share one profile slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Backgrounds must remain profile products');
$assertSame(['background'], array_values(array_unique(array_column($rows, 'item_family'))), 'Background family must remain isolated');
$assertSame([0,0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Paid backgrounds must never be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Background offers must stay in Profile Store category');
$assertSame(['background'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Background offers must use background subcategory');
$assertSame(['background-01','background-02','background-03','background-04'], array_column($rows, 'offer_id'), 'Background offer ids must remain deterministic');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame(['Сумерки','Север','Неон','Бездна'], array_column($metadata, 'display_name'), 'Background names must remain deterministic');
$assertSame(['normal','rare','epic','legendary'], array_column($metadata, 'tier'), 'Background tiers must remain deterministic');
$assertSame([2000,4000,7500,12500], array_map('intval', array_column($metadata, 'price_coins')), 'Metadata prices must match Store offers');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-backgrounds-user', 'browser_dev', ['username'=>'profile-backgrounds'], 'mvp19-3-profile-backgrounds-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$backgroundCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'background'));
$assertSame(4, count($backgroundCatalog), 'Inventory snapshot must expose all four backgrounds');
$assertSame(null, $snapshot['equipped']['profile_background'] ?? null, 'Fresh account must have no paid background selected');

$quote = $store->quote($mgwId, 'background-01');
$assertSame(2000, (int)$quote['price_coins'], 'First background quote must remain 2000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-background-user', [
    'request_token' => 'store:mvp19-3-profile-background-0001',
    'offer_id' => 'background-01',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Background purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Purchase must not select a background');
$inventory->equip($mgwId, 'profile-background-01');
$assertSame('profile-background-01', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Explicit equip must use ProductInventoryService');

$secondQuote = $store->quote($mgwId, 'background-02');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-background-user', [
    'request_token' => 'store:mvp19-3-profile-background-0002',
    'offer_id' => 'background-02',
    'price_coins' => $secondQuote['price_coins'],
    'item_ids' => $secondQuote['item_ids'],
]);
$assertSame('profile-background-01', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Buying another background must not replace equipment');
$inventory->equip($mgwId, 'profile-background-02');
$assertSame('profile-background-02', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'One slot must replace the previous background');
$inventory->unequip($mgwId, 'profile_background');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_background']), 'Background slot must allow the default no-background state');

$source = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-backgrounds.js');
$css = (string)file_get_contents($root . '/app/assets/css/components/mgw-profile-backgrounds.css');
$assertTrue(str_contains($source, "const BACKGROUND_SLOT = 'profile_background'") && str_contains($source, 'api.cosmeticStorePurchase') && str_contains($source, 'api.cosmeticStoreEquip') && str_contains($source, 'api.cosmeticStoreUnequip'), 'Background UX must use canonical purchase/equip transports');
$assertTrue(str_contains($source, 'data-profile-background-store-section') && str_contains($source, 'data-profile-background-collection'), 'Background UX must own Store discovery and Profile collection surfaces');
$assertTrue(str_contains($source, "document.addEventListener('mgw:app-ready'") && str_contains($source, "getElementById('screen-profile')"), 'Background fallback hydration must wait for app-ready and project to Profile only');
$assertTrue(str_contains($css, '#screen-profile[data-profile-background-item-id]') && str_contains($css, 'profile-background-04'), 'Background CSS must define all four Profile variants');

fwrite(STDOUT, "MVP-19.3 profile backgrounds passed ({$assertions} assertions).\n");
