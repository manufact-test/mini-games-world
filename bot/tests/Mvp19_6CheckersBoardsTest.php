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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.6 Checkers boards test requires pdo_sqlite.');

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
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every current additive migration');

$rows = $database->fetchAll(
    "SELECT c.item_id, c.item_family, c.equip_slot, c.metadata_json, o.offer_id, o.price_coins, o.category, o.subcategory
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'game_checkers' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$assertSame(4, count($rows), 'Bounded MVP-19.6 launch must expose exactly four Checkers products');
$assertSame([
    'game-checkers-board-wood',
    'game-checkers-board-dark',
    'game-checkers-board-marble',
    'game-checkers-board-neon',
], array_column($rows, 'item_id'), 'Checkers board item identities must stay canonical and ordered');
$assertSame([3000,5000,8000,12000], array_map('intval', array_column($rows, 'price_coins')), 'Checkers board prices must use the canonical board grid');
$assertSame(['game_checkers_theme'], array_values(array_unique(array_column($rows, 'equip_slot'))), 'All Checkers boards must share one theme equip slot');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Checkers cosmetics must remain in the Games Store category');
$assertSame(['checkers'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Checkers cosmetics must use the Checkers subcategory');

foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('checkers', (string)($metadata['game_type'] ?? ''), 'Every Checkers board must project game_type=checkers');
    $assertSame('theme', (string)($metadata['layer'] ?? ''), 'First bounded family must contain board themes only');
}
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE category = 'games' AND subcategory = 'checkers' AND offer_type = 'bundle' AND offer_status = 'active'"), 'Checkers bundle must not publish before all families exist');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_family = 'game_checkers' AND json_extract(metadata_json, '$.layer') IN ('elements','effect')"), 'Pieces and effects must stay unpublished in the board-only slice');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-6-checkers-user', 'browser_dev', ['username'=>'checkers-boards'], 'mvp19-6-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $store->snapshot($mgwId, 100000, []);
$checkers = $snapshot['games']['catalogs']['checkers'] ?? null;
$assertTrue(is_array($checkers), 'Store snapshot must expose a Checkers catalogue');
$assertSame(4, count($checkers['themes'] ?? []), 'Store snapshot must expose all four Checkers boards');
$assertSame(0, count($checkers['elements'] ?? []), 'Store must not expose Checkers pieces before their bounded family');
$assertSame(0, count($checkers['effects'] ?? []), 'Store must not expose Checkers effects before their bounded family');

$quote = $store->quote($mgwId, 'checkers-board-neon');
$assertSame(12000, (int)$quote['price_coins'], 'Neon Checkers board quote must stay 12,000 coins');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-checkers-neon-0001',
    'offer_id' => 'checkers-board-neon',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Buying a Checkers board must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Purchased Checkers board must remain inactive until explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-board-neon');
$assertSame('game-checkers-board-neon', $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Explicit Checkers board equip must use the canonical theme slot');
$inventory->unequip($mgwId, 'game_checkers_theme');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Checkers board may be removed back to the accepted base presentation');

$baseRenderer = (string)file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$baseCss = (string)file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$liveWrapper = (string)file_get_contents($root . '/app/assets/js/checkers-cosmetics/renderer-board-themes.js');
$cosmeticsCss = (string)file_get_contents($root . '/app/assets/css/games/checkers/cosmetics.css');
$storeWrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-wrapper.js');
$profileWrapper = (string)file_get_contents($root . '/app/assets/js/profile/mgw-profile-checkers-parity.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
$assertSame('e362239b1388a1f752d2d0e67ae69a7cc9207926', $gitBlobSha($baseRenderer), 'Accepted Checkers renderer must remain byte-identical');
$assertSame('12d2f211c48c1f49793744315c004fd33afc5bcf', $gitBlobSha($baseCss), 'Accepted Checkers layout CSS must remain byte-identical');
$assertTrue(str_contains($liveWrapper, "game_checkers_theme") && str_contains($liveWrapper, "game-checkers-board-"), 'Live wrapper must consume the canonical equipped Checkers theme');
$assertTrue(str_contains($liveWrapper, "renderer.js?v=57&base=mvp16-accepted"), 'Live cosmetics must compose around the accepted Checkers renderer');
$assertTrue(!str_contains($liveWrapper, 'gameAction(') && !str_contains($liveWrapper, 'time_left') && !str_contains($liveWrapper, 'turn_started_at'), 'Checkers cosmetics wrapper must never own mechanics or timers');
foreach (['wood','dark','marble','neon'] as $variant) {
    $assertTrue(str_contains($cosmeticsCss, 'data-checkers-theme="' . $variant . '"'), 'Live CSS must contain Checkers board variant ' . $variant);
    $assertTrue(str_contains($cosmeticsCss, 'data-cosmetic-variant="' . $variant . '"'), 'Preview CSS must contain Checkers board variant ' . $variant);
}
$assertTrue(!str_contains($cosmeticsCss, '@keyframes'), 'Static Checkers board themes must not add perpetual animation');
$assertTrue(str_contains($storeWrapper, 'store-v2-mini-checkers-board') && str_contains($storeWrapper, "group.hidden = true"), 'Store wrapper must render 8x8 Checkers previews and hide unimplemented empty families');
$assertTrue(str_contains($storeWrapper, 'checkers-store-head-piece') && !str_contains($storeWrapper, "textContent = '●'") && !str_contains($storeWrapper, "textContent = '○'"), 'Checkers Store header must use recognisable checker pieces, not generic dot glyphs');
$assertTrue(str_contains($cosmeticsCss, 'width:min(100%,96px)') && str_contains($cosmeticsCss, 'height:142px') && str_contains($cosmeticsCss, 'width:120px'), 'Checkers Store card and purchase previews must use bounded square-safe board geometry');
$assertTrue(!str_contains($storeWrapper, 'MutationObserver') && !str_contains($storeWrapper, 'setInterval'), 'Store Checkers parity must stay bounded without permanent observers or polling');
$assertTrue(str_contains($profileWrapper, 'store-v2-mini-checkers-board') && str_contains($profileWrapper, "textContent = 'Доски'"), 'Profile must mirror the Checkers board preview and family name');
$assertTrue(!str_contains($profileWrapper, 'MutationObserver') && !str_contains($profileWrapper, 'setInterval'), 'Profile Checkers parity must stay bounded without permanent observers or polling');

$storeTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$profileTarget = (string)($manifest['imports']['./assets/js/screens/profile-screen-v110.js?v=1108'] ?? '');
$checkersTarget = (string)($manifest['imports']['./assets/js/games/checkers/renderer.js?v=57'] ?? '');
$assertTrue(str_contains($storeTarget, 'store-screen-checkers-wrapper.js') && str_contains($storeTarget, 'mvp19_6=store-corrective'), 'Active Store graph must select the corrected Checkers Store wrapper');
$assertTrue(str_contains($profileTarget, 'mvp19_6=checkers-board-parity-v1'), 'Active Profile graph must select Checkers board parity');
$assertTrue(str_contains($checkersTarget, 'checkers-cosmetics/renderer-board-themes.js') && str_contains($checkersTarget, 'mvp19_6=board-themes'), 'Active Checkers graph must select the board-theme wrapper');

fwrite(STDOUT, "PASS: MVP-19.6 Checkers board cosmetics ({$assertions} assertions)\n");
