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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.8 Go Store test requires pdo_sqlite.');

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
     WHERE c.item_family = 'game_go' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$expectedIds = [
    'game-go-board-wood','game-go-board-dark','game-go-board-stone','game-go-board-neon',
    'game-go-stones-classic','game-go-stones-marble','game-go-stones-glass','game-go-stones-neon',
    'game-go-effect-placement','game-go-effect-group-capture','game-go-effect-territory-finish',
];
$assertSame(11, count($rows), 'Go Store must expose exactly eleven individual cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Go identities and ordering must stay canonical');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Go prices must use the approved grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Go cosmetics must stay in Games');
$assertSame(['go'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Go cosmetics must use the go subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('go', (string)($metadata['game_type'] ?? ''), 'Every Go item must project game_type=go');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Go item must use theme, elements, or effect layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    if ($layer === 'effect') $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
}
$assertSame(4, count($byLayer['theme']), 'Go must have four boards');
$assertSame(4, count($byLayer['elements']), 'Go must have four stone sets');
$assertSame(3, count($byLayer['effect']), 'Go must have three effects');
$assertSame(['game_go_theme'], array_values(array_unique($byLayer['theme'])), 'Go boards must share one theme slot');
$assertSame(['game_go_elements'], array_values(array_unique($byLayer['elements'])), 'Go stones must share one elements slot');
$assertSame(['game_go_effect'], array_values(array_unique($byLayer['effect'])), 'Go effects must share one effect slot');
$assertSame(['placement'=>'placement','group-capture'=>'group_capture','territory-finish'=>'territory_finish'], $events, 'Go effects must preserve placement, group capture, and territory finish semantics');

$bundleCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'go'");
$assertSame(0, $bundleCount, 'MVP-19.8 Go Store must not create a bundle');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-8-go-user', 'browser_dev', ['username'=>'go-store'], 'mvp19-8-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$go = $snapshot['games']['catalogs']['go'] ?? null;
$assertTrue(is_array($go), 'Store snapshot must expose a Go catalogue');
$assertSame(4, count($go['themes'] ?? []), 'Store snapshot must expose four Go boards');
$assertSame(4, count($go['elements'] ?? []), 'Store snapshot must expose four Go stone sets');
$assertSame(3, count($go['effects'] ?? []), 'Store snapshot must expose three Go effects');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Go purchases must never auto-equip');
$assertSame(2, count($snapshot['bundles']['game_bundles'] ?? []), 'Go Store work must not add or alter game bundles');

$boardQuote = $store->quote($mgwId, 'go-board-neon');
$assertSame(12000, (int)$boardQuote['price_coins'], 'Neon Go board must cost 12,000 coins');
$boardPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-go-user', [
    'request_token'=>'store:mvp19-8-go-board-0001', 'offer_id'=>'go-board-neon',
    'price_coins'=>$boardQuote['price_coins'], 'item_ids'=>$boardQuote['item_ids'],
]);
$assertSame(false, $boardPurchase['auto_equipped'], 'Buying a Go board must not auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_go_theme'] ?? null, 'Bought board must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-go-board-neon');
$assertSame('game-go-board-neon', $inventory->snapshot($mgwId)['equipped']['game_go_theme'] ?? null, 'Explicit board equip must use game_go_theme');

$stonesQuote = $store->quote($mgwId, 'go-stones-glass');
$stonesPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-go-user', [
    'request_token'=>'store:mvp19-8-go-stones-0002', 'offer_id'=>'go-stones-glass',
    'price_coins'=>$stonesQuote['price_coins'], 'item_ids'=>$stonesQuote['item_ids'],
]);
$assertSame(false, $stonesPurchase['auto_equipped'], 'Buying Go stones must not auto-equip');
$store->equipGameItem($mgwId, 'game-go-stones-glass');
$assertSame('game-go-stones-glass', $inventory->snapshot($mgwId)['equipped']['game_go_elements'] ?? null, 'Explicit stone equip must use game_go_elements');

foreach ([['placement',2500,'0003'],['group-capture',5000,'0004'],['territory-finish',7500,'0005']] as [$variant,$price,$token]) {
    $quote = $store->quote($mgwId, 'go-effect-' . $variant);
    $assertSame($price, (int)$quote['price_coins'], 'Go effect price must stay canonical for ' . $variant);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-go-user', [
        'request_token'=>'store:mvp19-8-go-effect-' . $token, 'offer_id'=>'go-effect-' . $variant,
        'price_coins'=>$quote['price_coins'], 'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Buying Go effects must not auto-equip');
}
$store->equipGameItem($mgwId, 'game-go-effect-placement');
$store->equipGameItem($mgwId, 'game-go-effect-group-capture');
$assertSame('game-go-effect-group-capture', $inventory->snapshot($mgwId)['equipped']['game_go_effect'] ?? null, 'Equipping a second Go effect must replace the first in the same slot');
$inventory->unequip($mgwId, 'game_go_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_go_effect'] ?? null, 'Go effect slot must support explicit unequip');

$wrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-go-store-v1.js');
$css = (string)file_get_contents($root . '/app/assets/css/games/go/store-cosmetics-v1.css');
$topWrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$launch = (string)file_get_contents($root . '/bot/helpers/WebAppLaunchUrl.php');
$assertTrue(str_contains($topWrapper, 'store-screen-go-store-v1.js?v=4&mvp19_8=effects-premium-v2&paid_default=copy-human-v1'), 'Active Store entrypoint must install the fresh Go presentation layer');
$activeStoreTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$wrapperVersionMatch = [];
$assertTrue(preg_match('~store-screen-checkers-board-source-wrapper\\.js\\?v=(\\d+)~', $activeStoreTarget, $wrapperVersionMatch) === 1 && (int)$wrapperVersionMatch[1] >= 7, 'Active Store outer wrapper must stay at or beyond the accepted Go corrective identity');
$assertTrue(str_contains((string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? ''), 'go_effects=premium-v2'), 'Active Store URL must publish premium Go effects');
$launchMatch = [];
$assertTrue(preg_match('~/app/v110\.php\?v=(\d+)~', $launch, $launchMatch) === 1 && (int)$launchMatch[1] >= 1139, 'Telegram launch must remain at or beyond the accepted Go corrective graph');
foreach (['wood','dark','stone','neon'] as $variant) $assertTrue(str_contains($css, 'theme-' . $variant), 'Go Store CSS must style board ' . $variant);
foreach (['classic','marble','glass','neon'] as $variant) $assertTrue(str_contains($css, 'stones-' . $variant), 'Go Store CSS must style stone set ' . $variant);
foreach (['placement','group-capture','territory-finish'] as $variant) $assertTrue(str_contains($wrapper, $variant), 'Go Store wrapper must own effect preview ' . $variant);
$assertTrue(str_contains($css, 'mgw-go-v2-stonefall'), 'Go placement preview must use stonefall rather than the old generic pulse');
$assertTrue(str_contains($css, 'mgw-go-v2-capture-implode'), 'Go capture preview must use implosion rather than the old lift');
$assertTrue(str_contains($css, 'mgw-go-v2-territory-bloom'), 'Go territory preview must use radial bloom rather than the old sweep');
$assertTrue(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'Go Store effects must include reduced-motion fallback');
$assertTrue(!str_contains($wrapper, 'renderGoSurface(') && !str_contains($wrapper, 'last_captured_cells'), 'Store-only Go wrapper must not own gameplay mechanics');

echo "MVP-19.8 Go Store contract passed ({$assertions} assertions).\n";
