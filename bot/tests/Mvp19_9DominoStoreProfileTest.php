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
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Domino prices must stay stable');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Domino cosmetics must stay in Games');
$assertSame(['domino'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Domino cosmetics must stay in domino subcategory');

$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('domino', (string)($metadata['game_type'] ?? ''), 'Every Domino item must project game_type=domino');
    if (($metadata['layer'] ?? '') === 'effect') $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
}
$assertSame(['precision-drop'=>'play','stock-pulse'=>'draw','chain-finale'=>'finish'], $events, 'Domino event semantics must remain stable');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'domino'"), 'Domino must not create a bundle');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-9-domino-user', 'browser_dev', ['username'=>'domino-store'], 'mvp19-9-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$storeService = new CosmeticStoreService($database);
$snapshot = $storeService->snapshot($mgwId, 100000, []);
$domino = $snapshot['games']['catalogs']['domino'] ?? null;
$assertTrue(is_array($domino), 'Store snapshot must expose a Domino catalogue');
$assertSame(4, count($domino['themes'] ?? []), 'Store snapshot must expose four Domino tables');
$assertSame(4, count($domino['elements'] ?? []), 'Store snapshot must expose four Domino tile sets');
$assertSame(3, count($domino['effects'] ?? []), 'Store snapshot must expose three Domino effects');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Domino purchases must not auto-equip');

$effectQuote = $storeService->quote($mgwId, 'domino-effect-precision-drop');
$effectPurchase = $storeService->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-domino-user', [
    'request_token'=>'store:mvp19-9-domino-effect-v15-0001',
    'offer_id'=>'domino-effect-precision-drop',
    'price_coins'=>$effectQuote['price_coins'],
    'item_ids'=>$effectQuote['item_ids'],
]);
$assertSame(false, $effectPurchase['auto_equipped'], 'Buying a Domino effect must not auto-equip');
$storeService->equipGameItem($mgwId, 'game-domino-effect-precision-drop');
$assertSame('game-domino-effect-precision-drop', $inventory->snapshot($mgwId)['equipped']['game_domino_effect'] ?? null, 'Domino effect must equip into its dedicated slot');
$inventory->unequip($mgwId, 'game_domino_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_domino_effect'] ?? null, 'Domino effect slot must support explicit unequip');

$baseStore = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$storeModule = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-domino-store-v1.js');
$storeOwner = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$selectorOwner = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-reversi-store-v1.js');
$storeCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-cosmetics-v1.css');
$cardCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-card-fill-live-pips-v5.css');
$effectCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-effects-scene-v9.css');
$previewComponentCss = (string)file_get_contents($root . '/app/assets/css/games/domino/store-effects-preview-component-v44.css');
$profileCss = (string)file_get_contents($root . '/app/assets/css/screens/profile-domino-store-parity-v1.css');
$profileModule = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-domino-parity.js');
$profileHardRatio = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-domino-hard-ratio-v1.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$activeStore = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$activeProfile = (string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '');
$baseStoreTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog'] ?? '');
$dominoSource = (string)($manifest['imports']['./assets/js/screens/store-screen-domino-store-v1.js?v=1&mvp19_9=store-profile-preview-8x5-v1'] ?? '');
$dominoCachedSource = (string)($manifest['imports']['./assets/js/screens/store-screen-domino-store-v1.js?v=8&mvp19_9=domino-native-render-v8'] ?? '');
$dominoProfile = (string)($manifest['imports']['./assets/js/profile/mgw-profile-domino-parity.js?v=1&mvp19_9=store-profile-parity-8x5-v1'] ?? '');

$assertTrue(str_contains($baseStore, "if (gameType === 'domino')") && str_contains($baseStore, 'dominoPreviewMarkup(safeLayer, safeVariant)'), 'Base Store must keep native Domino rendering');
$assertTrue(str_contains($baseStore, 'Яркий акцент в момент точного хода') && str_contains($baseStore, 'Эффектный выход костяшки из запаса') && str_contains($baseStore, 'Финал с каскадом падающих костяшек'), 'Base Store must keep short player-facing copy');
$assertTrue(str_contains($storeModule, 'native:v12:motion-v45') && str_contains($storeModule, 'data-mgw-domino-preview-component="v44"') && str_contains($storeModule, 'sceneReady'), 'Store must publish fresh v44 preview identity and reject stale scene markup');
$assertTrue(str_contains($storeModule, 'ensureEffectStyles();') && str_contains($storeModule, 'ensureLiveParityStyles();') && str_contains($storeModule, 'domino-premium-effects-v15-proportions') && str_contains($storeModule, 'domino-preview-component-v44'), 'Domino scene owner must load base geometry plus the isolated v44 component');
$assertTrue(!str_contains($storeModule, 'mgw-domino-v13-impact') && !str_contains($storeModule, 'mgw-domino-v13-draw') && !str_contains($storeModule, 'mgw-domino-v13-cascade'), 'Effect preview markup must not reuse legacy v13 scene classes');
$assertTrue(str_contains($storeModule, 'mgw-domino-live-v44-stage') && str_contains($storeModule, 'mgw-domino-v44-precision-sparks') && str_contains($storeModule, 'mgw-domino-v44-stock-sparks') && str_contains($storeModule, 'mgw-domino-v44-finale-sweep'), 'All three effect previews must use the isolated v44 component');
$assertTrue(str_contains($storeModule, 'data-mgw-domino-preview-particles="8"') && str_contains($storeModule, 'data-mgw-domino-preview-particles="12"') && str_contains($storeModule, 'data-mgw-domino-preview-particles="6"'), 'Shared Store/Purchase/Profile markup must expose accepted live particle counts');
$assertTrue(!str_contains($storeModule, 'mgw-domino-fx-trail') && !str_contains($storeModule, 'mgw-domino-fx-burst') && !str_contains($storeModule, 'mgw-domino-fx-halo'), 'Rejected v12 effect markup must remain removed');
$assertTrue(str_contains($effectCss, '[data-cosmetic-layer="effect"]') && str_contains($effectCss, 'aspect-ratio:8 / 5!important'), 'Effect cards and purchase sheets must share one 8:5 surface');
$assertTrue(str_contains($effectCss, '.mgw-domino-fx-stage') && str_contains($effectCss, 'inset:0!important') && str_contains($effectCss, 'height:100%!important'), 'v15 scene stage must keep explicit non-zero WebView geometry');
$assertTrue(str_contains($effectCss, 'width:29.5%!important') && str_contains($effectCss, 'height:23.6%!important'), 'Precision pieces must keep corrected 2:1 geometry');
$assertTrue(str_contains($effectCss, 'width:31%!important') && str_contains($effectCss, 'height:24.8%!important'), 'Stock moving piece must keep corrected 2:1 geometry');
$assertTrue(str_contains($effectCss, 'width:13.5%!important') && str_contains($effectCss, 'height:43.2%!important'), 'Finale standing pieces must keep corrected 1:2 geometry');
$assertTrue(str_contains($effectCss, 'mgw-domino-v15-precision-flight') && str_contains($effectCss, 'mgw-domino-v15-stock-flight') && str_contains($effectCss, 'mgw-domino-v15-cascade-tile'), 'Accepted base preview geometry remains beneath live parity');
$assertTrue(str_contains($previewComponentCss, 'does not reuse the old v13/v15/v18 effect') && str_contains($previewComponentCss, 'mgw-domino-live-v44-stage'), 'v44 preview must be isolated from legacy effect CSS');
$assertTrue(str_contains($previewComponentCss, 'height:.62px!important') && str_contains($previewComponentCss, 'mgw-domino-v44-precision-shard') && str_contains($previewComponentCss, 'mgw-domino-v44-stock-spark') && str_contains($previewComponentCss, 'mgw-domino-v44-finale-sweep'), 'v44 preview must preserve the accepted live visual motifs');
$assertTrue(!str_contains($profileCss, 'animation:none!important'), 'Profile CSS must not freeze Domino effect previews');
$assertTrue(!str_contains($previewComponentCss, 'left:var(--sx)!important') && !str_contains($previewComponentCss, 'top:var(--sy)!important'), 'v45 must not lock particle coordinates against keyframes');
$assertTrue(!str_contains($previewComponentCss, 'transform:rotate(27deg) scaleX(0)!important'), 'v45 must not lock Stock beam transform against keyframes');
$assertTrue(!str_contains($previewComponentCss, 'transform:translateX(-44%) skewX(-4deg)!important'), 'v45 must not lock Finale sweep transform against keyframes');
$assertTrue(str_contains($previewComponentCss, 'motion corrective v45'), 'Preview CSS must publish the v45 motion corrective identity');
$assertTrue(str_contains($baseStore, "store-screen-domino-store-v1.js?v=12&mvp19_9=domino-preview-motion-v45"), 'Base Store must directly import v44 instead of relying on wrapper repair');
$assertTrue(!str_contains($effectCss, 'mgw-domino-v12-') && !str_contains($effectCss, 'repeating-conic-gradient') && !str_contains($effectCss, 'mix-blend-mode:screen'), 'Rejected v12/rainbow/light-show language must remain absent');
$assertTrue(str_contains($storeOwner, "store-screen-domino-store-v1.js?v=12&mvp19_9=domino-preview-motion-v45") && str_contains($storeOwner, "store-screen-domino-effects-v9.js?v=10&mvp19_9=domino-preview-motion-v45"), 'Store owner must wire fresh v44 preview markup over accepted base geometry');
$assertTrue(str_contains($selectorOwner, 'selector.scrollLeft = left;') && !str_contains($selectorOwner, "behavior:'smooth'"), 'Accepted no-jump Store selector must remain intact');
$assertTrue(str_contains($profileModule, "dominoPreviewMarkup } from '../screens/store-screen-domino-store-v1.js?v=12&mvp19_9=domino-preview-motion-v45'") && str_contains($profileModule, 'store-effects-scene-v9.css?v=7&mvp19_9=domino-premium-effects-v15-proportions') && str_contains($profileModule, 'store-effects-preview-component-v44.css?v=2&mvp19_9=domino-preview-motion-v45'), 'Profile must reuse the exact Store v44 preview source and component CSS');
$assertTrue(!str_contains($profileHardRatio, 'getBoundingClientRect') && !str_contains($profileHardRatio, 'setTimeout'), 'Profile must remain free of imperative geometry retries');
$assertTrue(str_contains($storeCss, 'aspect-ratio:8 / 5!important') && str_contains($cardCss, 'width:3px!important'), 'Accepted static Domino geometry/pips must remain frozen');
$assertTrue(str_contains($activeStore, 'domino_effects=component-v44-motion-v45') && str_contains($activeStore, 'domino_preview=component-v44-motion-v45') && str_contains($activeStore, 'domino_base=native-render-v2'), 'Active Store graph must publish v44 previews without changing the accepted base owner');
$assertTrue(str_contains($baseStoreTarget, 'store-screen.js?v=50'), 'Base Store target must remain on the accepted cache-safe native base');
$assertTrue(str_contains($activeProfile, 'domino_effects=component-v44-motion-v45') && str_contains($activeProfile, 'domino_preview=shared-component-v44-motion-v45'), 'Active Profile graph must publish v44 parity');
$assertTrue(str_contains($dominoSource, 'store-screen-domino-store-v1.js?v=11') && str_contains($dominoCachedSource, 'store-screen-domino-store-v1.js?v=11') && str_contains($dominoProfile, 'mgw-profile-domino-parity.js?v=12'), 'Import map must cache-bust Store, cached base import and Profile to v44 sources');
$launchMatch = [];
$assertTrue(preg_match('~/app/v110\.php\?v=(\d+)~', $launch, $launchMatch) === 1 && (int)$launchMatch[1] >= 1170, 'Telegram entry must publish the v15 graph');

$assertTrue(!str_contains($storeModule, 'renderDominoSurface(') && !str_contains($profileModule, 'renderDominoSurface('), 'Store/Profile work must not wire live Domino before preview acceptance');

echo "MVP-19.9 Domino Store/Purchase/Profile v44/v45 preview motion contract passed ({$assertions} assertions).\n";
