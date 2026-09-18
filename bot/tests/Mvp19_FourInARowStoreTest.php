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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('Four in a Row Store test requires pdo_sqlite.');

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
     WHERE c.item_family = 'game_four_in_a_row' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);

$expectedIds = [
    'game-four-field-blue','game-four-field-dark','game-four-field-metal','game-four-field-neon',
    'game-four-discs-classic','game-four-discs-3d','game-four-discs-metal','game-four-discs-neon',
    'game-four-effect-drop','game-four-effect-four','game-four-effect-victory-wave',
];
$assertSame(11, count($rows), 'Four in a Row Store must expose exactly eleven individual cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Four in a Row identities and ordering must stay canonical');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Four in a Row prices must use the approved grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Four in a Row cosmetics must stay in Games');
$assertSame(['four_in_a_row'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Four in a Row cosmetics must use four_in_a_row subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('four_in_a_row', (string)($metadata['game_type'] ?? ''), 'Every Four in a Row item must project game_type=four_in_a_row');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Four in a Row item must use theme, elements, or effect layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    if ($layer === 'effect') {
        $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
        $assertSame('animated_preview', (string)($metadata['preview_mode'] ?? ''), 'Accepted Four effects must publish animated Store/Profile preview mode');
    }
}
$assertSame(4, count($byLayer['theme']), 'Four in a Row must have four fields');
$assertSame(4, count($byLayer['elements']), 'Four in a Row must have four disc sets');
$assertSame(3, count($byLayer['effect']), 'Four in a Row must have three effects');
$assertSame(['game_four_in_a_row_theme'], array_values(array_unique($byLayer['theme'])), 'Fields must share one theme slot');
$assertSame(['game_four_in_a_row_elements'], array_values(array_unique($byLayer['elements'])), 'Discs must share one elements slot');
$assertSame(['game_four_in_a_row_effect'], array_values(array_unique($byLayer['effect'])), 'Effects must share one effect slot');
$assertSame(['drop'=>'drop','four'=>'placement_pulse','victory-wave'=>'victory_wave'], $events, 'Effect metadata must expose drop, mid-game pulse, and victory-wave semantics');

$bundleCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'four_in_a_row'");
$assertSame(0, $bundleCount, 'Four in a Row Phase 1 must not create a bundle');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'four-store-user', 'browser_dev', ['username'=>'four-store'], 'four-store-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$four = $snapshot['games']['catalogs']['four_in_a_row'] ?? null;
$assertTrue(is_array($four), 'Store snapshot must expose a Four in a Row catalogue');
$assertSame('4 в ряд', (string)($four['title'] ?? ''), 'Store snapshot must expose player-facing title');
$assertSame(4, count($four['themes'] ?? []), 'Store snapshot must expose four fields');
$assertSame(4, count($four['elements'] ?? []), 'Store snapshot must expose four disc sets');
$assertSame(3, count($four['effects'] ?? []), 'Store snapshot must expose three effects');
$pulseOffer = null;
foreach (($four['effects'] ?? []) as $effectOffer) {
    if ((string)($effectOffer['metadata']['variant'] ?? '') === 'four') $pulseOffer = $effectOffer;
}
$assertTrue(is_array($pulseOffer), 'Store snapshot must expose effect 2');
$assertSame('Энергетический импульс', (string)($pulseOffer['display_name'] ?? ''), 'Effect 2 must use the mid-game pulse player-facing name');
$assertSame('placement_pulse', (string)($pulseOffer['metadata']['event'] ?? ''), 'Effect 2 must be a normal-placement event, not a victory event');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Four in a Row purchases must never auto-equip');

$fieldQuote = $store->quote($mgwId, 'four-field-neon');
$assertSame(12000, (int)$fieldQuote['price_coins'], 'Neon field must cost 12,000 coins');
$fieldPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-four-user', [
    'request_token'=>'store:four-field-0001',
    'offer_id'=>'four-field-neon',
    'price_coins'=>$fieldQuote['price_coins'],
    'item_ids'=>$fieldQuote['item_ids'],
]);
$assertSame(false, $fieldPurchase['auto_equipped'], 'Buying a field must not auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_four_in_a_row_theme'] ?? null, 'Bought field must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-four-field-neon');
$assertSame('game-four-field-neon', $inventory->snapshot($mgwId)['equipped']['game_four_in_a_row_theme'] ?? null, 'Explicit field equip must use theme slot');

$discQuote = $store->quote($mgwId, 'four-discs-metal');
$discPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-four-user', [
    'request_token'=>'store:four-discs-0002',
    'offer_id'=>'four-discs-metal',
    'price_coins'=>$discQuote['price_coins'],
    'item_ids'=>$discQuote['item_ids'],
]);
$assertSame(false, $discPurchase['auto_equipped'], 'Buying discs must not auto-equip');
$store->equipGameItem($mgwId, 'game-four-discs-metal');
$assertSame('game-four-discs-metal', $inventory->snapshot($mgwId)['equipped']['game_four_in_a_row_elements'] ?? null, 'Explicit disc equip must use elements slot');

foreach ([['drop',2500,'0003'],['four',5000,'0004'],['victory-wave',7500,'0005']] as [$variant,$price,$token]) {
    $quote = $store->quote($mgwId, 'four-effect-' . $variant);
    $assertSame($price, (int)$quote['price_coins'], 'Effect price must stay canonical for ' . $variant);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-four-user', [
        'request_token'=>'store:four-effect-' . $token,
        'offer_id'=>'four-effect-' . $variant,
        'price_coins'=>$quote['price_coins'],
        'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Buying effects must not auto-equip');
}
$store->equipGameItem($mgwId, 'game-four-effect-drop');
$store->equipGameItem($mgwId, 'game-four-effect-four');
$assertSame('game-four-effect-four', $inventory->snapshot($mgwId)['equipped']['game_four_in_a_row_effect'] ?? null, 'Equipping a second effect must replace the first');
$inventory->unequip($mgwId, 'game_four_in_a_row_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_four_in_a_row_effect'] ?? null, 'Effect slot must support explicit unequip');

$wrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-four-in-a-row-store-v1.js');
$css = (string)file_get_contents($root . '/app/assets/css/games/four-in-a-row/store-cosmetics-v1.css');
$outer = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$renderer = (string)file_get_contents($root . '/app/assets/js/games/four-in-a-row/renderer.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');

$assertTrue(str_contains($outer, "store-screen-four-in-a-row-store-v1.js?v=11&four_store=live-previews-v3&geometry=7x6&fx=victory-test-exact-v3&effect2=random-chain-v4&victory=overdrive-v3&export=profile-preview-v4&copy=compact-v3"), 'Active Store wrapper must install the accepted animated Four preview presentation');
$activeStore = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$wrapperVersionMatch = [];
$assertTrue(preg_match('~store-screen-checkers-board-source-wrapper\\.js\\?v=(\\d+)~', $activeStore, $wrapperVersionMatch) === 1 && (int)$wrapperVersionMatch[1] >= 21, 'Active Store graph must publish the Four animated-preview cache identity');
$assertTrue(str_contains($activeStore, 'four_store=live-previews-v3') && str_contains($activeStore, 'four_effect2=random-chain-v4') && str_contains($activeStore, 'four_victory=overdrive-v3') && str_contains($activeStore, 'copy=compact-v3'), 'Active Store graph must publish accepted preview and player-copy identities');
$launchMatch = [];
$assertTrue(preg_match('~/app/v110\.php\\?v=(\\d+)~', $launch, $launchMatch) === 1 && (int)$launchMatch[1] >= 1213 && str_contains($launch, 'four_store=live-previews-v3') && str_contains($launch, 'four_profile=live-previews-v3'), 'Telegram launch must publish the Four Store/Profile animated-preview graph');

foreach (['blue','dark','metal','neon'] as $variant) $assertTrue(str_contains($css, 'theme-' . $variant), 'Store CSS must style field ' . $variant);
foreach (['classic','3d','metal','neon'] as $variant) $assertTrue(str_contains($css, 'pieces-' . $variant), 'Store CSS must style disc set ' . $variant);
$assertTrue(str_contains($css, 'aspect-ratio:auto') && str_contains($css, 'grid-template-rows:repeat(6,auto)'), 'Small Four in a Row cards must let six equal square cell rows define board height without a compressed center row');
$assertTrue(str_contains($css, '.store-v2-game-product[data-store-game-product="four_in_a_row"]') && str_contains($css, '.store-v2-game-preview[data-game-type="four_in_a_row"]') && str_contains($css, 'width:92%'), 'All compact Four in a Row cards must retain the accepted disc sizing');
$assertTrue(str_contains($css, '.mgw-four-disc.pieces-metal.red') && str_contains($css, 'radial-gradient(circle at 31% 23%') && !str_contains($css, 'linear-gradient(145deg,#ffe0e4'), 'Metal discs must keep the accepted rounded material');
$assertTrue(str_contains($css, '.mgw-four-disc.pieces-neon.red') && str_contains($css, '#ff2d8c') && str_contains($css, '#9dff2e'), 'Neon discs must keep bright filled luminous cores');
$assertTrue(str_contains($wrapper, "drop:'Прицел, лазер и эффектное падение фишки.") && str_contains($wrapper, "four:'Молнии разлетаются от каждого вашего хода.") && str_contains($wrapper, "'victory-wave':'Победная четвёрка вспыхивает мощным финалом."), 'Effect descriptions must be written for the player and match the accepted live behavior');
foreach (['Лазерное наведение','Энергетический импульс','Победный овердрайв'] as $name) $assertTrue(str_contains($wrapper, $name), 'Effect previews must expose player-facing name ' . $name);
foreach (['mgw-four-preview-drop-reticle','mgw-four-preview-pulse-svg','mgw-four-preview-victory-prism'] as $primitive) $assertTrue(str_contains($wrapper, $primitive), 'Effect preview markup must include accepted visual primitive ' . $primitive);
foreach (['mgwFourPreviewDropDisc','mgwFourPreviewBoltA','mgwFourPreviewVictoryPrism','mgwFourPreviewShard'] as $keyframe) $assertTrue(str_contains($css, '@keyframes ' . $keyframe), 'Store/Profile preview CSS must animate ' . $keyframe);
$assertTrue(str_contains($wrapper, 'Array.from({ length:42 }') && str_contains($css, 'grid-template-rows:repeat(6,minmax(0,1fr))') && str_contains($css, 'aspect-ratio:7 / 6'), 'Effect previews must use the real 7x6 Four board geometry');
$assertTrue(str_contains($css, 'width:17.5%') && str_contains($css, 'width:11.8%'), 'Drop reticle and falling disc must stay cell-scaled instead of covering the preview');
$assertTrue(str_contains($wrapper, 'viewBox="0 0 7 6"'), 'Pulse and Victory previews must be anchored to board-local coordinates');
$assertTrue(str_contains($wrapper, '[22,23,24,25]') && str_contains($wrapper, 'M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5'), 'Victory preview must use the exact centered four produced by the accepted staging test button');
$assertTrue(str_contains($css, 'left:42.857%') && str_contains($css, '.effect-victory-wave .mgw-four-fx-board-shell') && str_contains($css, 'overflow:hidden'), 'Victory preview prism/blades/shards must share the accepted test center and stay clipped to the board');
$assertTrue(str_contains($css, '.s1,') && str_contains($css, '.s4,') && str_contains($css, '.s7,') && str_contains($css, '.s10{background:#ffe172'), 'Victory preview must preserve the live gold shard sequence');
$assertTrue(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'Animated Four previews must remain reduced-motion safe');
$assertTrue(str_contains($wrapper, "mgwFourPreviewMode = layer === 'effect' ? 'animated-live-parity' : 'static'"), 'Effect previews must publish animated live-parity mode');
$assertTrue(str_contains($wrapper, 'upgradePurchaseCopy(root)') && str_contains($wrapper, '.store-v2-confirm-copy strong'), 'Purchase confirmation must reuse the animated preview and player-facing effect name');
$assertTrue(!str_contains($wrapper, 'renderFourInARowSurface') && !str_contains($wrapper, 'last_move') && !str_contains($wrapper, 'winning_cells'), 'Store/Profile previews must remain presentation-only and never own live mechanics');
$assertTrue(str_contains($renderer, 'winning_cells') && str_contains($renderer, 'last_move'), 'Accepted live renderer remains authoritative for real move and victory triggers');

echo "Four in a Row animated Store/Profile preview contract passed ({$assertions} assertions).";
