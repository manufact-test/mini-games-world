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
$assertSame(2, count($rows), 'Victory Effects slice must expose Spark Burst and Firework Salvo only');
$byId = [];
foreach ($rows as $row) $byId[(string)$row['item_id']] = $row;

$spark = $byId['profile-victory-effect-01'] ?? null;
$salvo = $byId['profile-victory-effect-02'] ?? null;
$assertTrue(is_array($spark), 'Spark Burst must remain present');
$assertTrue(is_array($salvo), 'Firework Salvo must be present');

$assertSame('victory-effect-01', (string)$spark['offer_id'], 'Spark Burst offer id must stay canonical');
$assertSame(5000, (int)$spark['price_coins'], 'Spark Burst must stay 5000 coins');
$sparkMeta = json_decode((string)$spark['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Искровой залп', (string)($sparkMeta['display_name'] ?? ''), 'Spark Burst name must stay frozen');
$assertSame('spark-burst', (string)($sparkMeta['variant'] ?? ''), 'Spark Burst variant must stay frozen');
$assertSame(2200, (int)($sparkMeta['duration_ms'] ?? 0), 'Spark Burst duration must stay 2.2 seconds');

$assertSame('victory-effect-02', (string)$salvo['offer_id'], 'Firework Salvo offer id must stay canonical');
$assertSame(8500, (int)$salvo['price_coins'], 'Firework Salvo must cost 8500 coins');
$assertSame('profile', (string)$salvo['item_type'], 'Victory Effects remain Profile cosmetics');
$assertSame('victory_effect', (string)$salvo['item_family'], 'Victory Effect family must stay isolated');
$assertSame('profile_victory_effect', (string)$salvo['equip_slot'], 'Both Victory Effects use the same canonical slot');
$assertSame(0, (int)$salvo['starter_grant'], 'Victory Effects must never be starter-granted');
$assertSame('profile', (string)$salvo['category'], 'Firework Salvo offer stays in Profile Store');
$assertSame('victory_effect', (string)$salvo['subcategory'], 'Firework Salvo subcategory must be stable');
$salvoMeta = json_decode((string)$salvo['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Салют победителя', (string)($salvoMeta['display_name'] ?? ''), 'Approved Firework Salvo name must be seeded');
$assertSame('firework-salvo', (string)($salvoMeta['variant'] ?? ''), 'Firework Salvo variant must be deterministic');
$assertSame(2900, (int)($salvoMeta['duration_ms'] ?? 0), 'Firework Salvo target duration must be 2.9 seconds');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-victory-salvo-user', 'browser_dev', ['username'=>'victory-salvo'], 'mvp19-3-victory-salvo-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Fresh account must have no Victory Effect selected');
$quote = $store->quote($mgwId, 'victory-effect-02');
$assertSame(8500, (int)$quote['price_coins'], 'Firework Salvo quote must cost 8500 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-victory-salvo-user', [
    'request_token' => 'store:mvp19-3-victory-salvo-0001',
    'offer_id' => 'victory-effect-02',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying Firework Salvo must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Purchase must not silently select Firework Salvo');
$inventory->equip($mgwId, 'profile-victory-effect-02');
$assertSame('profile-victory-effect-02', $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Explicit Firework Salvo equip must use canonical inventory');
$inventory->unequip($mgwId, 'profile_victory_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_victory_effect']), 'Victory Effect slot must support explicit remove');

$storeEndpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$responseProjection = (string)file_get_contents($root . '/bot/helpers/response.php');
$selector = (string)file_get_contents($root . '/app/assets/js/profile/mgw-victory-effect-selector.js');
$ui = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects.js');
$wrapper = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects-card-parity.js');
$sparkCss = (string)file_get_contents($root . '/app/assets/css/production-v109-victory-effects-spark-burst.css');
$salvoCss = (string)file_get_contents($root . '/app/assets/css/production-v112-victory-effects-firework-salvo.css');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$gameScreen = (string)file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($storeEndpoint, 'function mgw_store_profile_victory_effect') && str_contains($storeEndpoint, "'profile_victory_effect'"), 'Canonical Store endpoint must keep the Victory Effect slot generic');
$assertTrue(str_contains($responseProjection, 'victory_effect_item_id') && str_contains($responseProjection, "e.equip_slot = \\'profile_victory_effect\\'"), 'Public game identity must project the equipped winner Victory Effect');
$assertTrue(str_contains($selector, "'profile-victory-effect-02'") && str_contains($selector, "String(game.status || '') !== 'finished'") && str_contains($selector, 'winner?.victory_effect_item_id'), 'Merged winner selector must recognize Firework Salvo and stay finished-game-only');
$assertTrue(str_contains($ui, "const FIREWORK_SALVO_ID = 'profile-victory-effect-02'") && str_contains($ui, "variant:'firework-salvo'") && str_contains($ui, 'duration:2900'), 'Victory runtime must register Firework Salvo at 2.9 seconds');
$assertTrue(str_contains($ui, 'api.cosmeticStorePurchase') && str_contains($ui, 'api.cosmeticStoreEquip') && str_contains($ui, 'api.cosmeticStoreUnequip'), 'Firework Salvo must reuse canonical purchase/equip owners');
$assertTrue(str_contains($ui, 'selectWinnerVictoryEffect(game)') && str_contains($ui, '#resultSummary[data-result-game-id]'), 'Live Victory Effects must reuse the winner selector after result surface exists');
$assertTrue(str_contains($ui, 'playedGames') && str_contains($ui, 'mgw-victory-effect-skip') && str_contains($ui, 'Math.min(4000, Math.max(2000'), 'Victory Effects must be once-per-game, skippable and bounded to 2–4 seconds');
$assertTrue(!str_contains($ui, 'gameAction(') && !str_contains($ui, '.webp') && !str_contains($ui, '.png') && !str_contains($ui, '.jpg'), 'Victory presentation must not own game actions or add heavy raster art');
$assertTrue(str_contains($ui, "fireworkBurstMarkup('salvo-left',22") && str_contains($ui, "fireworkBurstMarkup('salvo-right',22") && str_contains($ui, "fireworkBurstMarkup('salvo-center',30") && str_contains($ui, 'fireworkConfettiMarkup(24)') && str_contains($ui, 'fireworkStarfieldMarkup(18)'), 'Firework Salvo must be a staged three-firework show, not a recolored Spark Burst');
$assertTrue(str_contains($ui, 'victoryStageMarkup(spec.variant)') && str_contains($ui, "variant === 'firework-salvo' ? fireworkSalvoStageMarkup() : sparkBurstStageMarkup()"), 'Store/Profile/sheet/live must share the variant-specific scene owner');
$assertTrue(str_contains($sparkCss, 'mgwVictorySparkRay'), 'Accepted Spark Burst CSS must remain intact');
$assertTrue(str_contains($salvoCss, 'mgwVictorySalvoTrailLeft') && str_contains($salvoCss, 'mgwVictorySalvoRayCenter') && str_contains($salvoCss, '@media(prefers-reduced-motion:reduce)'), 'Firework Salvo CSS must include launch trails, central firework and reduced-motion handling');
$assertTrue(str_contains($salvoCss, '[data-victory-effect-variant="firework-salvo"]') && str_contains($salvoCss, 'animation-iteration-count:1!important'), 'Live Firework Salvo must run once while previews can loop');
$assertTrue(str_contains($salvoCss, "content:'Салют победителя'!important"), 'Profile Firework Salvo card must override the legacy Spark-only pseudo-name safely');
$assertTrue(str_contains($wrapper, 'mgw-profile-victory-effects.js?v=3&mvp19_3=firework-salvo') && str_contains($wrapper, 'production-v112-victory-effects-firework-salvo.css?v=1'), 'Wrapper must cache-publish the Firework Salvo base module and CSS');
$assertTrue(str_contains($manifest, 'mgw-profile-victory-effects-card-parity.js?v=9&mvp19_3=firework-salvo') && str_contains($manifest, 'victory=firework-salvo&visual_repair=9'), 'Active v110 manifest must publish Firework Salvo with a fresh cache identity');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileVictoryEffects"), 'Shared runtime must continue initializing Victory Effects after app-ready');
$assertTrue(!str_contains($gameScreen, 'victory_effect_item_id') && !str_contains($gameScreen, 'mgw-victory-effect'), 'Frozen result/game owner must not absorb Victory presentation logic');

fwrite(STDOUT, "MVP-19.3 Victory Effects 01/02 passed ({$assertions} assertions).\n");
