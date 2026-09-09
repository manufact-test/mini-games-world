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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.3 Victory Spark Burst test requires pdo_sqlite.');

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
     WHERE c.item_family = 'victory_effect' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(1, count($rows), 'First Victory Effect slice must expose only Spark Burst');
$row = $rows[0];
$assertSame('profile-victory-effect-01', (string)$row['item_id'], 'Spark Burst technical item id must stay canonical');
$assertSame('victory-effect-01', (string)$row['offer_id'], 'Spark Burst offer id must stay canonical');
$assertSame(5000, (int)$row['price_coins'], 'Spark Burst must cost 5000 coins');
$assertSame('profile', (string)$row['item_type'], 'Victory Effects must remain Profile cosmetics');
$assertSame('victory_effect', (string)$row['item_family'], 'Victory Effect family must be isolated');
$assertSame('profile_victory_effect', (string)$row['equip_slot'], 'Victory Effects must own one dedicated Profile slot');
$assertSame(0, (int)$row['starter_grant'], 'Victory Effects must never be starter-granted');
$assertSame('profile', (string)$row['category'], 'Victory Effect offer must stay in Profile Store');
$assertSame('victory_effect', (string)$row['subcategory'], 'Victory Effect offer subcategory must be stable');

$metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Искровой залп', (string)($metadata['display_name'] ?? ''), 'Approved Spark Burst display name must be seeded');
$assertSame('spark-burst', (string)($metadata['variant'] ?? ''), 'Approved Spark Burst variant must be deterministic');
$assertSame(2200, (int)($metadata['duration_ms'] ?? 0), 'Spark Burst target duration must be 2.2 seconds');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-victory-effect-user', 'browser_dev', ['username'=>'victory-effect'], 'mvp19-3-victory-effect-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $inventory->snapshot($mgwId);
$assertSame(null, $snapshot['equipped']['profile_victory_effect'] ?? null, 'Fresh account must have no Victory Effect selected');
$quote = $store->quote($mgwId, 'victory-effect-01');
$assertSame(5000, (int)$quote['price_coins'], 'Spark Burst quote must cost 5000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-victory-effect-user', [
    'request_token' => 'store:mvp19-3-victory-effect-0001',
    'offer_id' => 'victory-effect-01',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying Spark Burst must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Purchase must not silently select Spark Burst');
$inventory->equip($mgwId, 'profile-victory-effect-01');
$assertSame('profile-victory-effect-01', $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Explicit Victory Effect equip must use canonical inventory');
$inventory->unequip($mgwId, 'profile_victory_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_victory_effect']), 'Victory Effect slot must support explicit remove');

$storeEndpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$responseProjection = (string)file_get_contents($root . '/bot/helpers/response.php');
$selector = (string)file_get_contents($root . '/app/assets/js/profile/mgw-victory-effect-selector.js');
$ui = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects.js');
$css = (string)file_get_contents($root . '/app/assets/css/production-v109-victory-effects-spark-burst.css');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$gameScreen = (string)file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($storeEndpoint, 'function mgw_store_profile_victory_effect') && str_contains($storeEndpoint, "'profile_victory_effect'"), 'Canonical Store endpoint must recognize the Victory Effect profile slot');
$assertTrue(str_contains($responseProjection, 'victory_effect_item_id') && str_contains($responseProjection, "e.equip_slot = \\'profile_victory_effect\\'"), 'Public game identity must project the equipped winner Victory Effect');
$assertTrue(str_contains($selector, "String(game.status || '') !== 'finished'") && str_contains($selector, 'winner?.victory_effect_item_id'), 'Merged winner selector must remain authoritative and finished-game-only');
$assertTrue(str_contains($ui, "const VICTORY_EFFECT_SLOT = 'profile_victory_effect'") && str_contains($ui, 'api.cosmeticStorePurchase') && str_contains($ui, 'api.cosmeticStoreEquip'), 'Victory Effect UI must reuse canonical Store and inventory owners');
$assertTrue(str_contains($ui, 'selectWinnerVictoryEffect(game)') && str_contains($ui, '#resultSummary[data-result-game-id]'), 'Live Spark Burst must consume the merged winner selector only after the result surface exists');
$assertTrue(str_contains($ui, '#sheet [aria-busy="true"],#sheet button:disabled'), 'Optimistic/pending result must not trigger a false Victory Effect');
$assertTrue(str_contains($ui, 'playedGames') && str_contains($ui, 'mgw-victory-effect-skip') && str_contains($ui, 'Math.min(4000, Math.max(2000'), 'Spark Burst must be once-per-game, skippable and bounded to 2-4 seconds');
$assertTrue(!str_contains($ui, 'gameAction(') && !str_contains($ui, '.webp') && !str_contains($ui, '.png') && !str_contains($ui, '.jpg'), 'Victory presentation must not own game actions or depend on heavy raster art');
$assertTrue(str_contains($css, 'pointer-events:none') && str_contains($css, '@media(prefers-reduced-motion:reduce)') && str_contains($css, 'mgwVictorySparkRay'), 'Spark Burst CSS must stay nonblocking, reduced-motion safe and particle-driven');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileVictoryEffects") && str_contains($watcher, 'mgw-profile-victory-effects.js?v=1&mvp19_3=victory-effects'), 'Shared runtime must initialize Victory Effects after app-ready');
$assertTrue(!str_contains($gameScreen, 'victory_effect_item_id') && !str_contains($gameScreen, 'mgw-victory-effect'), 'Frozen result/game owner must not absorb Victory presentation logic');
$assertTrue(str_contains($manifest, 'mgw-profile-victory-effects.js?v=1&mvp19_3=spark-burst-v1') && str_contains($manifest, 'victory_effects=spark-burst-v1'), 'Active v110 manifest must cache-publish Spark Burst runtime');

fwrite(STDOUT, "MVP-19.3 Victory Spark Burst passed ({$assertions} assertions).\n");
