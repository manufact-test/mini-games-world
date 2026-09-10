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
$assertSame(3, count($rows), 'Victory Effects slice must expose all three final effects');
$assertSame('profile-victory-effect-02', (string)$rows[0]['item_id'], 'Firework Salvo must be the 5000 entry tier');
$assertSame('profile-victory-effect-01', (string)$rows[1]['item_id'], 'Spark Burst must be the 8500 middle tier');
$assertSame('profile-victory-effect-03', (string)$rows[2]['item_id'], 'Victory Nova must be the 12500 premium tier');

$byId = [];
foreach ($rows as $row) $byId[(string)$row['item_id']] = $row;
$spark = $byId['profile-victory-effect-01'] ?? null;
$salvo = $byId['profile-victory-effect-02'] ?? null;
$nova = $byId['profile-victory-effect-03'] ?? null;
$assertTrue(is_array($spark) && is_array($salvo) && is_array($nova), 'All three Victory Effects must remain active');

$assertSame('victory-effect-01', (string)$spark['offer_id'], 'Spark Burst offer id stays stable');
$assertSame(8500, (int)$spark['price_coins'], 'Spark Burst stays the 8500 middle tier');
$assertSame(74, (int)$spark['sort_order'], 'Spark Burst stays second');
$sparkMeta = json_decode((string)$spark['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Искровой залп', (string)($sparkMeta['display_name'] ?? ''), 'Spark Burst name stays frozen');
$assertSame('spark-burst', (string)($sparkMeta['variant'] ?? ''), 'Spark Burst visual identity stays frozen');
$assertSame('tier-2', (string)($sparkMeta['tier'] ?? ''), 'Spark Burst stays tier 2');
$assertSame(2200, (int)($sparkMeta['duration_ms'] ?? 0), 'Spark Burst timing stays 2.2 seconds');

$assertSame('victory-effect-02', (string)$salvo['offer_id'], 'Firework Salvo offer id stays stable');
$assertSame(5000, (int)$salvo['price_coins'], 'Firework Salvo stays the 5000 entry tier');
$assertSame(73, (int)$salvo['sort_order'], 'Firework Salvo stays first');
$salvoMeta = json_decode((string)$salvo['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Салют победителя', (string)($salvoMeta['display_name'] ?? ''), 'Firework Salvo name stays frozen');
$assertSame('firework-salvo', (string)($salvoMeta['variant'] ?? ''), 'Firework Salvo visual identity stays frozen');
$assertSame('tier-1', (string)($salvoMeta['tier'] ?? ''), 'Firework Salvo stays tier 1');
$assertSame(2900, (int)($salvoMeta['duration_ms'] ?? 0), 'Firework Salvo timing stays 2.9 seconds');

$assertSame('victory-effect-03', (string)$nova['offer_id'], 'Victory Nova offer id must be canonical');
$assertSame(12500, (int)$nova['price_coins'], 'Victory Nova must cost 12500 coins');
$assertSame(75, (int)$nova['sort_order'], 'Victory Nova must be the third tier');
$assertSame('profile', (string)$nova['item_type'], 'Victory Nova remains a Profile cosmetic');
$assertSame('victory_effect', (string)$nova['item_family'], 'Victory Nova remains in the Victory family');
$assertSame('profile_victory_effect', (string)$nova['equip_slot'], 'Victory Nova uses the existing canonical Victory slot');
$assertSame(0, (int)$nova['starter_grant'], 'Victory Nova must never be starter-granted');
$assertSame('profile', (string)$nova['category'], 'Victory Nova offer stays in Profile Store');
$assertSame('victory_effect', (string)$nova['subcategory'], 'Victory Nova subcategory stays canonical');
$novaMeta = json_decode((string)$nova['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
$assertSame('Победная сверхновая', (string)($novaMeta['display_name'] ?? ''), 'Victory Nova name must be seeded');
$assertSame('victory-nova', (string)($novaMeta['variant'] ?? ''), 'Victory Nova variant must be deterministic');
$assertSame('tier-3', (string)($novaMeta['tier'] ?? ''), 'Victory Nova must be tier 3');
$assertSame(12500, (int)($novaMeta['price_coins'] ?? 0), 'Victory Nova metadata price must match the offer');
$assertSame(3500, (int)($novaMeta['duration_ms'] ?? 0), 'Victory Nova target duration must be 3.5 seconds');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-3-victory-nova-user', 'browser_dev', ['username'=>'victory-nova'], 'mvp19-3-victory-nova-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Fresh account must have no Victory Effect selected');
$quote = $store->quote($mgwId, 'victory-effect-03');
$assertSame(12500, (int)$quote['price_coins'], 'Victory Nova purchase quote must be 12500');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-victory-nova-user', [
    'request_token' => 'store:mvp19-3-victory-nova-0001',
    'offer_id' => 'victory-effect-03',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying Victory Nova must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Purchase must not silently select Victory Nova');
$inventory->equip($mgwId, 'profile-victory-effect-03');
$assertSame('profile-victory-effect-03', $inventory->snapshot($mgwId)['equipped']['profile_victory_effect'] ?? null, 'Explicit Victory Nova equip must use canonical inventory');
$inventory->unequip($mgwId, 'profile_victory_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['profile_victory_effect']), 'Victory Effect slot must support explicit remove');

$storeEndpoint = (string)file_get_contents($root . '/bot/cosmetic-store.php');
$responseProjection = (string)file_get_contents($root . '/bot/helpers/response.php');
$selector = (string)file_get_contents($root . '/app/assets/js/profile/mgw-victory-effect-selector.js');
$ui = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects-v4.js');
$wrapper = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-victory-effects-card-parity.js');
$tierCss = (string)file_get_contents($root . '/app/assets/css/production-v113-victory-effects-tier-swap.css');
$sparkCss = (string)file_get_contents($root . '/app/assets/css/production-v109-victory-effects-spark-burst.css');
$salvoCss = (string)file_get_contents($root . '/app/assets/css/production-v112-victory-effects-firework-salvo.css');
$novaCss = (string)file_get_contents($root . '/app/assets/css/production-v114-victory-effects-victory-nova.css');
$watcher = (string)file_get_contents($root . '/app/assets/js/production-v110-readonly-game-sync.js');
$gameScreen = (string)file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
$manifest = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');

$assertTrue(str_contains($storeEndpoint, 'function mgw_store_profile_victory_effect') && str_contains($storeEndpoint, "'profile_victory_effect'"), 'Canonical Store owner remains generic');
$assertTrue(str_contains($responseProjection, 'victory_effect_item_id'), 'Public finished-game identity still projects the equipped Victory Effect');
$assertTrue(str_contains($selector, "'profile-victory-effect-03'") && str_contains($selector, 'winner?.victory_effect_item_id'), 'Winner selector must recognize Victory Nova');
$assertTrue(str_contains($ui, "const VICTORY_NOVA_ID = 'profile-victory-effect-03'") && str_contains($ui, "variant:'victory-nova'") && str_contains($ui, 'duration:3500'), 'Victory runtime must register Victory Nova at 3.5 seconds');
$assertTrue(str_contains($ui, 'api.cosmeticStorePurchase') && str_contains($ui, 'api.cosmeticStoreEquip') && str_contains($ui, 'api.cosmeticStoreUnequip'), 'Victory Nova must reuse canonical purchase/equip owners');
$assertTrue(str_contains($ui, 'selectWinnerVictoryEffect(game)') && str_contains($ui, '#resultSummary[data-result-game-id]') && str_contains($ui, 'mgw-victory-effect-skip'), 'Victory Nova must reuse the nonblocking winner result presentation');
$assertTrue(str_contains($ui, "novaBurstMarkup('nova-core',32") && str_contains($ui, "novaBurstMarkup('nova-top-left',14") && str_contains($ui, "novaBurstMarkup('nova-top-right',14") && str_contains($ui, "novaBurstMarkup('nova-bottom-left',14") && str_contains($ui, "novaBurstMarkup('nova-bottom-right',14"), 'Victory Nova must use one core plus four satellite bursts');
$assertTrue(str_contains($ui, 'novaCometMarkup()') && str_contains($ui, 'novaGlitterRainMarkup(34)') && str_contains($ui, 'novaStarfieldMarkup(22)') && str_contains($ui, 'novaCrownMarkup()'), 'Victory Nova must include comets, dense glitter, stars and premium final halo');
$assertTrue(str_contains($ui, "if (variant === 'victory-nova') return victoryNovaStageMarkup()"), 'Store/Profile/sheet/live must share the same Victory Nova scene owner');
$assertTrue(!str_contains($ui, 'gameAction(') && !preg_match('/\.(?:webp|png|jpe?g)/i', $ui), 'Victory presentation must not own game actions or add heavy raster art');
$assertTrue(str_contains($sparkCss, 'mgwVictorySparkRay'), 'Accepted Spark Burst visual CSS remains intact');
$assertTrue(str_contains($salvoCss, 'mgwVictorySalvoTrailLeft') && str_contains($salvoCss, 'mgwVictorySalvoRayCenter'), 'Accepted Firework Salvo visual CSS remains intact');
$assertTrue(str_contains($novaCss, 'mgwVictoryNovaIgnition') && str_contains($novaCss, 'mgwVictoryNovaComet') && str_contains($novaCss, 'mgwVictoryNovaCrown') && str_contains($novaCss, '@media(prefers-reduced-motion:reduce)'), 'Victory Nova CSS must contain the premium multi-stage and reduced-motion choreography');
$assertTrue(str_contains($novaCss, 'animation-iteration-count:1!important'), 'Live Victory Nova must run once while previews can loop');
$assertTrue(str_contains($tierCss, 'victory-nova') && str_contains($tierCss, 'order:3'), 'Victory Nova must remain third in Store/Profile order');
$assertTrue(str_contains($wrapper, 'mgw-profile-victory-effects-v4.js?v=1&mvp19_3=victory-nova') && str_contains($wrapper, 'production-v114-victory-effects-victory-nova.css?v=1'), 'Active wrapper must load Victory Nova runtime and CSS');
$assertTrue(str_contains($manifest, 'mgw-profile-victory-effects-card-parity.js?v=11&mvp19_3=victory-nova') && str_contains($manifest, 'victory=victory-nova&visual_repair=11'), 'Manifest must cache-publish Victory Nova');
$assertTrue(str_contains($watcher, "document.addEventListener('mgw:app-ready', initMgwProfileVictoryEffects"), 'Shared runtime must continue initializing Victory Effects');
$assertTrue(!str_contains($gameScreen, 'victory_effect_item_id') && !str_contains($gameScreen, 'mgw-victory-effect'), 'Frozen result/game owner must remain free of Victory presentation logic');

fwrite(STDOUT, "MVP-19.3 Victory Effects 01-03 final contract passed ({$assertions} assertions).\n");
