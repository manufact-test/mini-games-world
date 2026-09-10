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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 Victory Effects test requires pdo_sqlite.');

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
            o.offer_id, o.price_coins, o.category, o.subcategory, o.sort_order
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'victory_effect' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(2, count($rows), 'Victory Effects slice must expose exactly the first two effects before Victory Nova');
$assertSame('profile-victory-effect-02', (string)$rows[0]['item_id'], 'Firework Salvo must be the first/cheapest Victory tier');
$assertSame('profile-victory-effect-01', (string)$rows[1]['item_id'], 'Spark Burst must be the second/middle Victory tier');

$byId = [];
foreach ($rows as $row) $byId[(string)$row['item_id']] = $row;
$spark = $byId['profile-victory-effect-01'] ?? null;
$salvo = $byId['profile-victory-effect-02'] ?? null;
$assertTrue(is_array($spark), 'Spark Burst must remain present');
$assertTrue(is_array($salvo), 'Firework Salvo must remain present');

$assertSame('victory-effect-01', (string)$spark['offer_id'], 'Spark Burst offer id stays stable so ownership remains stable');
$assertSame(8500, (int)$spark['price_coins'], 'Spark Burst is now the 8500 middle tier');
$assertSame(74, (int)$spark['sort_order'], 'Spark Burst sort order must be second');
$sparkMeta = json_decode((string)$spark['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Искровой залп', (string)($sparkMeta['display_name'] ?? ''), 'Spark Burst name stays frozen');
$assertSame('spark-burst', (string)($sparkMeta['variant'] ?? ''), 'Spark Burst visual identity stays frozen');
$assertSame('tier-2', (string)($sparkMeta['tier'] ?? ''), 'Spark Burst metadata must be the middle tier');
$assertSame(8500, (int)($sparkMeta['price_coins'] ?? 0), 'Spark Burst metadata price must match the offer');
$assertSame(2200, (int)($sparkMeta['duration_ms'] ?? 0), 'Spark Burst accepted 2.2s timing stays frozen');

$assertSame('victory-effect-02', (string)$salvo['offer_id'], 'Firework Salvo offer id stays stable so ownership remains stable');
$assertSame(5000, (int)$salvo['price_coins'], 'Firework Salvo is now the 5000 entry tier');
$assertSame(73, (int)$salvo['sort_order'], 'Firework Salvo sort order must be first');
$assertSame('profile', (string)$salvo['item_type'], 'Victory Effects remain Profile cosmetics');
$assertSame('victory_effect', (string)$salvo['item_family'], 'Victory Effect family stays isolated');
$assertSame('profile_victory_effect', (string)$salvo['equip_slot'], 'Both Victory Effects use the same canonical slot');
$assertSame(0, (int)$salvo['starter_grant'], 'Victory Effects are never starter-granted');
$salvoMeta = json_decode((string)$salvo['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Салют победителя', (string)($salvoMeta['display_name'] ?? ''), 'Firework Salvo name stays frozen');
$assertSame('firework-salvo', (string)($salvoMeta['variant'] ?? ''), 'Firework Salvo visual identity stays frozen');
$assertSame('tier-1', (string)($salvoMeta['tier'] ?? ''), 'Firework Salvo metadata must be the entry tier');
$assertSame(5000, (int)($salvoMeta['price_coins'] ?? 0), 'Firework Salvo metadata price must match the offer');
$assertSame(2900, (int)($salvoMeta['duration_ms'] ?? 0), 'Firework Salvo accepted 2.9s timing stays frozen');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-victory-tier-swap-user', 'browser_dev', ['username'=>'victory-tier-swap'], 'mvp19-3-victory-tier-swap-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Fresh account must have no Victory Effect selected');
$quote = $store->quote($mgwId, 'victory-effect-02');
$assertSame(5000, (int)$quote['price_coins'], 'Firework Salvo purchase quote must use the new 5000 price');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-victory-tier-swap-user', [
    'request_token' => 'store:mvp19-3-victory-tier-swap-0001',
    'offer_id' => 'victory-effect-02',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying Firework Salvo must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Purchase must not silently select Firework Salvo');
$inventory->equip($mgwId, 'profile-victory-effect-02');
$assertSame('profile-victory-effect-02', $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Explicit Firework Salvo equip must still work after the tier swap');
$inventory->unequip($mgwId, 'profile_victory_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_victory_effect']), 'Victory Effect slot must still support explicit remove');

$storeEndpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$responseProjection = (string)file_get_contents($root . '/bot/helpers/response.php');
$selector = (string)file_get_contents($root . '/app/assets/js/profile/mgw-victory-effect-selector.js');
$ui = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects.js');
$wrapper = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects-card-parity.js');
$tierCss = (string)file_get_contents($root . '/app/assets/css/production-v113-victory-effects-tier-swap.css');
$sparkCss = (string)file_get_contents($root . '/app/assets/css/production-v109-victory-effects-spark-burst.css');
$salvoCss = (string)file_get_contents($root . '/app/assets/css/production-v112-victory-effects-firework-salvo.css');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$gameScreen = (string)file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($storeEndpoint, 'function mgw_store_profile_victory_effect') && str_contains($storeEndpoint, "'profile_victory_effect'"), 'Canonical Store owner remains generic');
$assertTrue(str_contains($responseProjection, 'victory_effect_item_id'), 'Public finished-game identity still projects the equipped Victory Effect');
$assertTrue(str_contains($selector, "'profile-victory-effect-01'") && str_contains($selector, "'profile-victory-effect-02'") && str_contains($selector, 'winner?.victory_effect_item_id'), 'Winner selector must keep both stable item ids');
$assertTrue(str_contains($ui, "variant:'spark-burst'") && str_contains($ui, "variant:'firework-salvo'"), 'Both accepted visual identities remain registered');
$assertTrue(str_contains($ui, 'api.cosmeticStorePurchase') && str_contains($ui, 'api.cosmeticStoreEquip') && str_contains($ui, 'api.cosmeticStoreUnequip'), 'Tier swap must reuse purchase/equip owners');
$assertTrue(str_contains($ui, 'selectWinnerVictoryEffect(game)') && str_contains($ui, 'mgw-victory-effect-skip'), 'Live winner presentation and Skip remain intact');
$assertTrue(str_contains($sparkCss, 'mgwVictorySparkRay'), 'Accepted Spark Burst visual CSS remains intact');
$assertTrue(str_contains($salvoCss, 'mgwVictorySalvoTrailLeft') && str_contains($salvoCss, 'mgwVictorySalvoRayCenter'), 'Accepted Firework Salvo visual CSS remains intact');
$assertTrue(str_contains($tierCss, 'firework-salvo') && str_contains($tierCss, 'order:1') && str_contains($tierCss, 'spark-burst') && str_contains($tierCss, 'order:2'), 'Store/Profile presentation must order Firework Salvo before Spark Burst');
$assertTrue(str_contains($wrapper, 'ensureVictoryTierSwapStylesheet()') && str_contains($wrapper, 'production-v113-victory-effects-tier-swap.css?v=1'), 'Active Victory wrapper must load the tier-order stylesheet');
$assertTrue(str_contains($manifest, 'mgw-profile-victory-effects-card-parity.js?v=10&mvp19_3=tier-swap-01-02') && str_contains($manifest, 'victory=tier-swap-01-02&visual_repair=10'), 'Manifest must cache-publish the tier swap');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileVictoryEffects"), 'Shared runtime must continue initializing Victory Effects');
$assertTrue(!str_contains($gameScreen, 'victory_effect_item_id') && !str_contains($gameScreen, 'mgw-victory-effect'), 'Frozen result/game owner must remain free of Victory presentation logic');

fwrite(STDOUT, "MVP-19.3 Victory Effects swapped tier contract passed ({$assertions} assertions).\n");
