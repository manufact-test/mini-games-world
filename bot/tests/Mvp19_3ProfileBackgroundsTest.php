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
     WHERE c.item_family = 'background' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(4, count($rows), 'Background launch catalogue must contain exactly four active items');
$assertSame([2000,4000,7500,12500], array_map('intval', array_column($rows, 'price_coins')), 'Background prices must match canonical MVP-19.3 tiers');
$assertSame(['profile_background'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'All backgrounds must share one mutually exclusive profile slot');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'item_type'))), 'Backgrounds must remain profile products');
$assertSame(['background'], array_values(array_unique(array_column($rows, 'item_family'))), 'Background family must remain isolated');
$assertSame([0,0,0,0], array_map('intval', array_column($rows, 'starter_grant')), 'Paid backgrounds must never be starter granted');
$assertSame(['profile'], array_values(array_unique(array_column($rows, 'category'))), 'Background offers must stay in Store Profile category');
$assertSame(['background'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Background offers must use their own Store subcategory');
$assertSame(['background-01','background-02','background-03','background-04'], array_column($rows, 'offer_id'), 'Background offer ids must remain deterministic');

$metadata = array_map(static fn(array $row): array => json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $rows);
$assertSame(['Сумерки','Север','Неон','Бездна'], array_column($metadata, 'display_name'), 'Background display names must remain deterministic');
$assertSame(['normal','rare','epic','legendary'], array_column($metadata, 'tier'), 'Background tier order must remain deterministic');
$assertSame([2000,4000,7500,12500], array_map('intval', array_column($metadata, 'price_coins')), 'Presentation metadata must match server offer prices');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-profile-backgrounds-user', 'browser_dev', ['username'=>'profile-backgrounds'], 'mvp19-3-profile-backgrounds-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$backgroundCatalog = array_values(array_filter((array)($snapshot['catalog'] ?? []), static fn(array $item): bool => ($item['item_family'] ?? '') === 'background'));
$assertSame(4, count($backgroundCatalog), 'Profile inventory catalogue must expose all four background products');
$assertSame(null, $snapshot['equipped']['profile_background'] ?? null, 'Fresh account must not have a paid background selected');

$quote = $store->quote($mgwId, 'background-01');
$assertSame(2000, (int)$quote['price_coins'], 'First background quote must remain 2000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-background-user', [
    'request_token' => 'store:mvp19-3-profile-background-0001',
    'offer_id' => 'background-01',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Background purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Purchased background must remain inactive until explicit equip');
$inventory->equip($mgwId, 'profile-background-01');
$assertSame('profile-background-01', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Explicit background equip must use ProductInventoryService');

$secondQuote = $store->quote($mgwId, 'background-02');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-profile-background-user', [
    'request_token' => 'store:mvp19-3-profile-background-0002',
    'offer_id' => 'background-02',
    'price_coins' => $secondQuote['price_coins'],
    'item_ids' => $secondQuote['item_ids'],
]);
$assertSame('profile-background-01', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Buying another background must not replace current equipment');
$inventory->equip($mgwId, 'profile-background-02');
$assertSame('profile-background-02', $inventory->snapshot($mgwId)['equipped']['profile_background'] ?? null, 'Choosing another background must replace previous background in same slot');
$inventory->unequip($mgwId, 'profile_background');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_background']), 'Background slot must allow default no-background state after unequip');
$assertStoreError(static fn() => $store->quote($mgwId, 'background-01'), 'already_owned', 'Owned background must reject duplicate purchase without compensation');

$endpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$backgroundSource = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-backgrounds.js');
$backgroundCss = (string)file_get_contents($root . '/app/assets/css/components/mgw-profile-backgrounds.css');
$cleanEntry = (string)file_get_contents($root . '/app/assets/js/production-clean-entry-v110.js');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($endpoint, 'function mgw_store_profile_background') && str_contains($endpoint, "'profile_background'"), 'Store mutation endpoint must whitelist canonical profile background family and slot');
$assertTrue(str_contains($backgroundSource, "const BACKGROUND_SLOT = 'profile_background'") && str_contains($backgroundSource, 'api.cosmeticStorePurchase') && str_contains($backgroundSource, 'api.cosmeticStoreEquip') && str_contains($backgroundSource, 'api.cosmeticStoreUnequip'), 'Background UX must use canonical Store purchase and inventory equip transport');
$assertTrue(str_contains($backgroundSource, 'data-profile-background-store-section') && str_contains($backgroundSource, 'data-profile-background-collection'), 'Background UX must render Store discovery and Profile owned collection surfaces');
$assertTrue(str_contains($backgroundSource, "getElementById('screen-profile')") && str_contains($backgroundSource, 'dataset.profileBackgroundItemId'), 'Equipped background must project only onto the canonical Profile surface');
$assertTrue(str_contains($backgroundSource, "document.addEventListener('mgw:app-ready'") && !str_contains($backgroundSource, 'void ensureBackgroundSnapshot();\n  };\n  if (document.readyState'), 'Background fallback hydration must wait for authoritative app-ready startup state');
$assertTrue(str_contains($backgroundCss, '#screen-profile[data-profile-background-item-id]') && str_contains($backgroundCss, 'profile-background-04'), 'One CSS owner must define all four Profile background presentations');
$assertTrue(str_contains($backgroundCss, '@media (prefers-reduced-motion:reduce)'), 'Profile backgrounds must remain reduced-motion safe');
$assertTrue(str_contains($cleanEntry, 'initMgwProfileBackgrounds'), 'Active clean entry must initialize Profile backgrounds');
$assertTrue(str_contains($mainCss, 'mgw-profile-backgrounds.css') && str_contains($manifest, 'mvp19_3_14=profile-backgrounds'), 'Active delivery graph must publish the Profile backgrounds build');

fwrite(STDOUT, "MVP-19.3 profile backgrounds passed ({$assertions} assertions).\n");
