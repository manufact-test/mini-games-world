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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Battleship Store test requires pdo_sqlite.');

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
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every current migration');

$rows = $database->fetchAll(
    "SELECT c.item_id, c.equip_slot, c.metadata_json, o.offer_id, o.price_coins, o.category, o.subcategory
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'game_battleship' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$expectedIds = [
    'game-battleship-map-sea','game-battleship-map-dark-military','game-battleship-map-storm','game-battleship-map-neon',
    'game-battleship-fleet-classic','game-battleship-fleet-modern','game-battleship-fleet-armored','game-battleship-fleet-neon',
    'game-battleship-effect-shot','game-battleship-effect-hit','game-battleship-effect-destroy',
];
$assertSame(11, count($rows), 'Battleship Store Phase 1 must expose exactly eleven individual cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Battleship identities and ordering must stay canonical');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Battleship prices must use the approved grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Battleship cosmetics must stay in Games');
$assertSame(['battleship'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Battleship cosmetics must use battleship subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
$metadataByVariant = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('battleship', (string)($metadata['game_type'] ?? ''), 'Every Battleship item must project game_type=battleship');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Battleship item must use theme, elements, or effect layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    $metadataByVariant[$layer . ':' . (string)($metadata['variant'] ?? '')] = $metadata;
    if ($layer === 'effect') $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
}
$assertSame(4, count($byLayer['theme']), 'Battleship must have four maps');
$assertSame(4, count($byLayer['elements']), 'Battleship must have four fleet sets');
$assertSame(3, count($byLayer['effect']), 'Battleship must have three effects');
$assertSame(['game_battleship_theme'], array_values(array_unique($byLayer['theme'])), 'Maps must share one theme slot');
$assertSame(['game_battleship_elements'], array_values(array_unique($byLayer['elements'])), 'Fleet sets must share one elements slot');
$assertSame(['game_battleship_effect'], array_values(array_unique($byLayer['effect'])), 'Effects must share one effect slot');
$assertSame(['shot'=>'shot','hit'=>'hit','destroy'=>'destroy'], $events, 'Effect metadata must expose shot/hit/destroy semantics');
$assertSame('v1', (string)($metadataByVariant['theme:sea']['paid_default_distinct'] ?? ''), 'Paid sea map must explicitly record free-default separation');
$assertSame('v1', (string)($metadataByVariant['elements:classic']['paid_default_distinct'] ?? ''), 'Paid classic fleet must explicitly record free-default separation');

$bundleCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'battleship'");
$assertSame(0, $bundleCount, 'Battleship Phase 1 must not create a bundle before all eight game slices are closed');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'battleship-store-user', 'browser_dev', ['username'=>'battleship-store'], 'battleship-store-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$catalog = $snapshot['games']['catalogs']['battleship'] ?? null;
$assertTrue(is_array($catalog), 'Store snapshot must expose a Battleship catalogue');
$assertSame('Морской бой', (string)($catalog['title'] ?? ''), 'Battleship Store must expose player-facing title');
$assertSame(4, count($catalog['themes'] ?? []), 'Store snapshot must expose four maps');
$assertSame(4, count($catalog['elements'] ?? []), 'Store snapshot must expose four fleet sets');
$assertSame(3, count($catalog['effects'] ?? []), 'Store snapshot must expose three effects');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Battleship purchases must never auto-equip');

$sea = $store->quote($mgwId, 'battleship-map-sea');
$assertSame(3000, (int)$sea['price_coins'], 'Paid sea map must cost 3,000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-battleship-user', [
    'request_token'=>'store:battleship-map-0001',
    'offer_id'=>'battleship-map-sea',
    'price_coins'=>$sea['price_coins'],
    'item_ids'=>$sea['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying a map must not auto-equip');
$store->equipGameItem($mgwId, 'game-battleship-map-sea');
$assertSame('game-battleship-map-sea', $inventory->snapshot($mgwId)['equipped']['game_battleship_theme'] ?? null, 'Explicit map equip must use Battleship theme slot');

$fleet = $store->quote($mgwId, 'battleship-fleet-classic');
$assertSame(3000, (int)$fleet['price_coins'], 'Paid classic fleet must cost 3,000 coins');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-battleship-user', [
    'request_token'=>'store:battleship-fleet-0002',
    'offer_id'=>'battleship-fleet-classic',
    'price_coins'=>$fleet['price_coins'],
    'item_ids'=>$fleet['item_ids'],
]);
$store->equipGameItem($mgwId, 'game-battleship-fleet-classic');
$assertSame('game-battleship-fleet-classic', $inventory->snapshot($mgwId)['equipped']['game_battleship_elements'] ?? null, 'Explicit fleet equip must use Battleship elements slot');

$wrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-battleship-store-v1.js');
$css = (string)file_get_contents($root . '/app/assets/css/games/battleship/store-cosmetics-v1.css');
$baseCss = (string)file_get_contents($root . '/app/assets/css/games/battleship/game.css');
$outer = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$assertTrue(str_contains($baseCss, 'rgba(51,94,162,.52)') && str_contains($baseCss, 'rgba(166,183,209,.96)'), 'Test must audit the actual free Battleship water and ship colors');
$assertTrue(str_contains($css, '.mgw-battleship-preview.map-sea') && str_contains($css, '#0b8ea8') && !str_contains($css, 'background:linear-gradient(145deg,rgba(51,94,162,.52),rgba(27,56,106,.52))'), 'Paid sea map preview must be materially distinct from the free blue map');
$assertTrue(str_contains($css, '.mgw-battleship-preview.fleet-classic') && str_contains($css, '#f3e2b8') && str_contains($css, '#e8bf68'), 'Paid classic fleet must use its own ivory/brass identity instead of the free gray fleet');
foreach (['map-sea','map-dark-military','map-storm','map-neon','fleet-classic','fleet-modern','fleet-armored','fleet-neon','effect-shot','effect-hit','effect-destroy'] as $variantClass) {
    $assertTrue(str_contains($css, '.' . $variantClass), 'Store CSS must style ' . $variantClass);
}
$assertTrue(str_contains($wrapper, 'Array.from({ length:100 }'), 'Battleship previews must preserve real 10x10 geometry');
$assertTrue(str_contains($wrapper, "theme:['Карты'") && str_contains($wrapper, "elements:['Флот'"), 'Battleship Store must use player-facing map/fleet group names');
$assertTrue(str_contains($wrapper, 'mgw-bs-head-vessel') && str_contains($wrapper, 'mgwBsHullSteel') && str_contains($wrapper, '<svg viewBox="0 0 92 48"'), 'Battleship header must use one compact metallic ship icon instead of neon or abstract tiles');
$assertTrue(str_contains($css, 'width:62px') && str_contains($css, 'fill:url(#mgwBsHullSteel)') && !str_contains($css, '.mgw-bs-head-vessel .hull{fill:#173649;stroke:#6af2ff'), 'Header ship must stay smaller and neutral metallic rather than neon');
$assertTrue(str_contains($css, '.mgw-battleship-preview.map-neon .mgw-bs-preview-sweep{') && str_contains($css, 'inset:2%') && str_contains($css, 'border-radius:8px'), 'Neon map outer frame must stay outside the cell circles with tighter corners');
$assertTrue(str_contains($css, 'aspect-ratio:1 / 1!important') && str_contains($css, '.store-v2-confirm-game .store-v2-game-preview[data-game-type="battleship"]'), 'Battleship cards and purchase confirmation must use the same square board geometry');
$assertTrue(str_contains($css, 'width:84%') && str_contains($css, 'aspect-ratio:1 / 1;') && str_contains($css, 'grid-template-rows:repeat(10,minmax(0,1fr))') && str_contains($css, 'transform:translateY(-50%)'), 'Battleship Store/Profile 10x10 previews must use one explicit square grid so no row can be compressed');
$assertTrue(str_contains($css, '.mgw-battleship-preview.fleet-neon') && str_contains($css, '#4d3aaa') && str_contains($css, '#2a205f') && str_contains($css, '#55f5ff') && str_contains($css, 'inset 0 0 0 2px rgba(255,70,223,.34)'), 'Neon Fleet preview must match the LIVE luminous-rim hull without a center dot');
$assertTrue(str_contains($wrapper, 'installHydrationRepair()') && str_contains($wrapper, 'new MutationObserver') && str_contains($wrapper, "target?.closest?.('.store-v2-game-preview[data-game-type=\"battleship\"]')") && str_contains($wrapper, 'globalThis.setTimeout(run, 700)'), 'Battleship Store must repair previews that are replaced by later async hydration.');
$assertTrue(!str_contains($wrapper, 'не повторяет бесплатное') && !str_contains($wrapper, 'не серые стандартные') && str_contains($wrapper, 'Бирюзовая вода, светлый фарватер') && str_contains($wrapper, 'Тёмный тактический радар с военным характером'), 'Battleship Store copy must stay short, human and product-facing without technical free-vs-paid commentary');
$assertTrue(str_contains($wrapper, "shot:'Прицел'") && str_contains($wrapper, "hit:'Попадание'") && str_contains($wrapper, "destroy:'Потопление'"), 'Effect labels must clearly distinguish aim, hit and destroy semantics');
foreach (['@keyframes mgwBsAim','@keyframes mgwBsHitBurst','@keyframes mgwBsDestroyBurst'] as $keyframe) {
    $assertTrue(str_contains($css, $keyframe), 'Store effect preview must animate ' . $keyframe);
}
$assertTrue(str_contains($css, '.effect-shot .mgw-bs-fx-reticle') && str_contains($css, '.effect-hit .mgw-bs-fx-burst') && str_contains($css, '.effect-destroy .mgw-bs-fx-smoke'), 'Shot, hit and destroy previews must use visibly different visual primitives');
$assertTrue(!str_contains($css, '.mgw-battleship-preview.map-neon .mgw-bs-preview-board>span:nth-child(3n)'), 'Neon map must use one coherent grid glow instead of patchy alternating cells');
$assertTrue(str_contains($outer, 'store-screen-battleship-store-v1.js?v=6&mvp19_12=store-preview-parity-v6&header=steel-ship&neon_frame=outer-safe&neon_fleet=tube-v4&preview_geometry=square-grid-v2&hydration=observer-v1&effects=unchanged-v2'), 'Active Store wrapper must install Battleship preview parity v6 with hydration repair');
$activeStore = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$assertTrue(str_contains($activeStore, 'store-screen-checkers-board-source-wrapper.js?v=26') && str_contains($activeStore, 'battleship_store=preview-parity-v6') && str_contains($activeStore, 'battleship_geometry=square') && str_contains($activeStore, 'battleship_header=steel-ship-v1') && str_contains($activeStore, 'battleship_neon_frame=outer-safe-v1') && str_contains($activeStore, 'battleship_neon_fleet=tube-v4') && str_contains($activeStore, 'battleship_preview_geometry=square-grid-v2') && str_contains($activeStore, 'battleship_store_hydration=observer-v1') && str_contains($activeStore, 'battleship_effects=distinct-v2'), 'Active Store graph must bump the parent wrapper cache and publish hydration parity v6');
$assertTrue(str_contains($launch, 'v=1228') && str_contains($launch, 'battleship_store=preview-parity-v6') && str_contains($launch, 'battleship_header=steel-ship-v1') && str_contains($launch, 'battleship_neon_frame=outer-safe-v1') && str_contains($launch, 'battleship_neon_fleet=tube-v4') && str_contains($launch, 'battleship_preview_geometry=square-grid-v2') && str_contains($launch, 'battleship_store_hydration=observer-v1') && str_contains($launch, 'battleship_effects=distinct-v2'), 'Telegram launch must force preview parity v6 with Store hydration repair');

echo "Battleship Store Phase 1 contract passed ({$assertions} assertions).";
