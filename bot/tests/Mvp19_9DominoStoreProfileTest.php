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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.9 Domino test requires pdo_sqlite.');

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
     WHERE c.item_family = 'game_domino' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$expectedIds = [
    'game-domino-table-felt','game-domino-table-midnight','game-domino-table-walnut','game-domino-table-neon',
    'game-domino-tiles-ivory','game-domino-tiles-ebony','game-domino-tiles-marble','game-domino-tiles-neon',
    'game-domino-effect-precision-drop','game-domino-effect-stock-pulse','game-domino-effect-chain-finale',
];
$assertSame(11, count($rows), 'Domino Store must expose exactly eleven individual cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Domino identities and ordering must stay stable');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Domino prices must use the established game-cosmetic grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Domino cosmetics must stay in Games');
$assertSame(['domino'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Domino cosmetics must use the domino subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('domino', (string)($metadata['game_type'] ?? ''), 'Every Domino item must project game_type=domino');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Domino item must use theme, elements, or effect layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    if ($layer === 'effect') $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
}
$assertSame(4, count($byLayer['theme']), 'Domino must have four tables');
$assertSame(4, count($byLayer['elements']), 'Domino must have four tile sets');
$assertSame(3, count($byLayer['effect']), 'Domino must have three effects');
$assertSame(['game_domino_theme'], array_values(array_unique($byLayer['theme'])), 'Domino tables must share one theme slot');
$assertSame(['game_domino_elements'], array_values(array_unique($byLayer['elements'])), 'Domino tiles must share one elements slot');
$assertSame(['game_domino_effect'], array_values(array_unique($byLayer['effect'])), 'Domino effects must share one effect slot');
$assertSame(['precision-drop'=>'play','stock-pulse'=>'draw','chain-finale'=>'finish'], $events, 'Domino preview effects must keep their intended event semantics');

$bundleCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'domino'");
$assertSame(0, $bundleCount, 'MVP-19.9 Domino must not create a bundle');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-9-domino-user', 'browser_dev', ['username'=>'domino-store'], 'mvp19-9-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$domino = $snapshot['games']['catalogs']['domino'] ?? null;
$assertTrue(is_array($domino), 'Store snapshot must expose a Domino catalogue');
$assertSame(4, count($domino['themes'] ?? []), 'Store snapshot must expose four Domino tables');
$assertSame(4, count($domino['elements'] ?? []), 'Store snapshot must expose four Domino tile sets');
$assertSame(3, count($domino['effects'] ?? []), 'Store snapshot must expose three Domino effects');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Domino purchases must never auto-equip');

$tableQuote = $store->quote($mgwId, 'domino-table-neon');
$assertSame(12000, (int)$tableQuote['price_coins'], 'Neon Domino table must cost 12,000 coins');
$tablePurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-domino-user', [
    'request_token'=>'store:mvp19-9-domino-table-0001', 'offer_id'=>'domino-table-neon',
    'price_coins'=>$tableQuote['price_coins'], 'item_ids'=>$tableQuote['item_ids'],
]);
$assertSame(false, $tablePurchase['auto_equipped'], 'Buying a Domino table must not auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_domino_theme'] ?? null, 'Bought table must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-domino-table-neon');
$assertSame('game-domino-table-neon', $inventory->snapshot($mgwId)['equipped']['game_domino_theme'] ?? null, 'Explicit table equip must use game_domino_theme');

$tilesQuote = $store->quote($mgwId, 'domino-tiles-marble');
$tilesPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-domino-user', [
    'request_token'=>'store:mvp19-9-domino-tiles-0002', 'offer_id'=>'domino-tiles-marble',
    'price_coins'=>$tilesQuote['price_coins'], 'item_ids'=>$tilesQuote['item_ids'],
]);
$assertSame(false, $tilesPurchase['auto_equipped'], 'Buying Domino tiles must not auto-equip');
$store->equipGameItem($mgwId, 'game-domino-tiles-marble');
$assertSame('game-domino-tiles-marble', $inventory->snapshot($mgwId)['equipped']['game_domino_elements'] ?? null, 'Explicit tile equip must use game_domino_elements');

foreach ([['precision-drop',2500,'0003'],['stock-pulse',5000,'0004'],['chain-finale',7500,'0005']] as [$variant,$price,$token]) {
    $quote = $store->quote($mgwId, 'domino-effect-' . $variant);
    $assertSame($price, (int)$quote['price_coins'], 'Domino effect price must stay stable for ' . $variant);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-domino-user', [
        'request_token'=>'store:mvp19-9-domino-effect-' . $token, 'offer_id'=>'domino-effect-' . $variant,
        'price_coins'=>$quote['price_coins'], 'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Buying Domino effects must not auto-equip');
}
$store->equipGameItem($mgwId, 'game-domino-effect-precision-drop');
$store->equipGameItem($mgwId, 'game-domino-effect-stock-pulse');
$assertSame('game-domino-effect-stock-pulse', $inventory->snapshot($mgwId)['equipped']['game_domino_effect'] ?? null, 'Equipping a second Domino effect must replace the first in the same slot');
$inventory->unequip($mgwId, 'game_domino_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_domino_effect'] ?? null, 'Domino effect slot must support explicit unequip');

$storeModule = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-domino-store-v1.js');
$storeOwner = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$storeCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-cosmetics-v1.css');
$cardCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-card-fill-live-pips-v5.css');
$effectCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-effects-scene-v9.css');
$profileModule = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-domino-parity.js');
$profileOwner = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-chess-layout-v2.js');
$profileHardRatio = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-domino-hard-ratio-v1.js');
$profileCss = (string)file_get_contents($root . '/app/assets/css/screens/profile-domino-store-parity-v1.css');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$activeStore = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$activeProfile = (string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '');
$assertTrue(str_contains($activeStore, 'store-screen-checkers-board-source-wrapper.js?v=8') && str_contains($activeStore, 'domino_preview=deterministic-v9') && str_contains($activeStore, 'domino_effects=scene-v9'), 'Active Store import must preserve the accepted owner and publish deterministic Domino v9');
$assertTrue(str_contains($activeProfile, 'mgw-profile-chess-layout-v2.js?v=20') && str_contains($activeProfile, 'mvp19_9=domino-profile-parity-v2') && str_contains($activeProfile, 'domino_card_runtime=css-8x5-v2'), 'Active Profile import must preserve the accepted owner and publish CSS-owned Domino parity');
$assertTrue(str_contains($storeOwner, "from './store-screen-domino-store-v1.js?v=5&mvp19_9=domino-deterministic-rerender-v5'") && str_contains($storeOwner, 'installDominoStorePresentation();') && str_contains($storeOwner, 'upgradeDominoStorePresentation();'), 'Accepted Store owner must install and refresh deterministic Domino presentation');
$assertTrue(!str_contains($storeOwner, 'installDominoStoreRerenderStabilityV1') && !file_exists($root . '/app/assets/js/screens/store-screen-domino-rerender-stability-v1.js'), 'Obsolete Domino MutationObserver repair must be removed from the active graph');
$assertTrue(str_contains($profileOwner, "from './mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1'") && str_contains($profileOwner, 'initProfileDominoParity();') && str_contains($profileOwner, 'initProfileDominoHardRatio();'), 'Accepted Profile owner must keep its existing extension points');
$assertTrue(str_contains($profileModule, "dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=5&mvp19_9=domino-deterministic-rerender-v5'"), 'Profile must reuse the exact active Store Domino preview primitive');
$assertTrue(str_contains($profileModule, 'store-card-fill-live-pips-v5.css?v=2&mvp19_9=domino-card-fill-live-pips-v6') && str_contains($profileModule, 'store-effects-scene-v9.css?v=1&mvp19_9=domino-store-effects-scene-v9'), 'Profile must load the exact Store pip and effect styles');
$assertTrue(str_contains($storeCss, 'aspect-ratio:8 / 5!important') && str_contains($profileCss, 'aspect-ratio:8 / 5!important'), 'Store and Profile must explicitly share the 8:5 Domino geometry');
$assertTrue(str_contains($cardCss, 'width:3px!important') && str_contains($cardCss, 'border-radius:50%!important'), 'Shared visible Domino pips must remain small centered circles');
$assertTrue(!str_contains($profileHardRatio, 'setImportant(') && !str_contains($profileHardRatio, 'setTimeout') && !str_contains($profileHardRatio, 'getBoundingClientRect'), 'Profile must not retain an imperative second geometry owner');
$assertTrue(str_contains($profileCss, 'overflow-x:auto!important'), 'Profile game tabs must remain horizontally scrollable');
foreach (['precision-drop','stock-pulse','chain-finale'] as $variant) $assertTrue(str_contains($storeModule, $variant) && str_contains($storeCss, 'effect-' . $variant), 'Store must own the Domino preview concept for ' . $variant);
$assertTrue(str_contains($effectCss, '@media (prefers-reduced-motion:reduce)') && str_contains($effectCss, 'aspect-ratio:47 / 24!important'), 'Domino v9 effect scenes must retain fixed tile geometry and reduced-motion fallback');
$assertTrue(!str_contains($storeModule, 'renderDominoSurface(') && !str_contains($profileModule, 'renderDominoSurface('), 'Store/Profile work must not wire live Domino before preview acceptance');
$launchMatch = [];
$assertTrue(preg_match('~/app/v110\.php\?v=(\d+)~', $launch, $launchMatch) === 1 && (int)$launchMatch[1] >= 1164, 'Telegram launch must publish the deterministic Domino Store/Profile graph');

echo "MVP-19.9 Domino Store/Profile deterministic contract passed ({$assertions} assertions).\n";
