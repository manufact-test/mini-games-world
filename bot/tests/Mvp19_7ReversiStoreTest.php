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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.7 Reversi Store test requires pdo_sqlite.');

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
     WHERE c.item_family = 'game_reversi' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$expectedIds = [
    'game-reversi-field-green','game-reversi-field-dark','game-reversi-field-marble','game-reversi-field-neon',
    'game-reversi-pieces-classic','game-reversi-pieces-marble','game-reversi-pieces-metal','game-reversi-pieces-neon',
    'game-reversi-effect-placement','game-reversi-effect-line','game-reversi-effect-mass-flip',
];
$assertSame(11, count($rows), 'Reversi Store must expose exactly eleven individual cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Reversi identities and ordering must stay canonical');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Reversi prices must use the approved grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Reversi cosmetics must stay in Games');
$assertSame(['reversi'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Reversi cosmetics must use the reversi subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('reversi', (string)($metadata['game_type'] ?? ''), 'Every Reversi item must project game_type=reversi');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Reversi item must use theme, elements, or effect layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    if ($layer === 'effect') $events[(string)$metadata['variant']] = (string)($metadata['event'] ?? '');
}
$assertSame(4, count($byLayer['theme']), 'Reversi must have four fields');
$assertSame(4, count($byLayer['elements']), 'Reversi must have four piece sets');
$assertSame(3, count($byLayer['effect']), 'Reversi must have three effects');
$assertSame(['game_reversi_theme'], array_values(array_unique($byLayer['theme'])), 'Reversi fields must share one theme slot');
$assertSame(['game_reversi_elements'], array_values(array_unique($byLayer['elements'])), 'Reversi pieces must share one elements slot');
$assertSame(['game_reversi_effect'], array_values(array_unique($byLayer['effect'])), 'Reversi effects must share one effect slot');
$assertSame(['placement'=>'placement','line'=>'line','mass-flip'=>'mass_flip'], $events, 'Reversi effects must preserve placement, line, and mass flip semantics');

$bundleCount = (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'reversi'");
$assertSame(0, $bundleCount, 'MVP-19.7 Reversi Store must not create a bundle');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-7-reversi-user', 'browser_dev', ['username'=>'reversi-store'], 'mvp19-7-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$reversi = $snapshot['games']['catalogs']['reversi'] ?? null;
$assertTrue(is_array($reversi), 'Store snapshot must expose a Reversi catalogue');
$assertSame(4, count($reversi['themes'] ?? []), 'Store snapshot must expose four Reversi fields');
$assertSame(4, count($reversi['elements'] ?? []), 'Store snapshot must expose four Reversi piece sets');
$assertSame(3, count($reversi['effects'] ?? []), 'Store snapshot must expose three Reversi effects');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Reversi purchases must never auto-equip');
$assertSame(2, count($snapshot['bundles']['game_bundles'] ?? []), 'Reversi Store work must not add or alter game bundles');

$fieldQuote = $store->quote($mgwId, 'reversi-field-neon');
$assertSame(12000, (int)$fieldQuote['price_coins'], 'Neon Reversi field must cost 12,000 coins');
$fieldPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-reversi-user', [
    'request_token'=>'store:mvp19-7-reversi-field-0001', 'offer_id'=>'reversi-field-neon',
    'price_coins'=>$fieldQuote['price_coins'], 'item_ids'=>$fieldQuote['item_ids'],
]);
$assertSame(false, $fieldPurchase['auto_equipped'], 'Buying a Reversi field must not auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_reversi_theme'] ?? null, 'Bought field must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-reversi-field-neon');
$assertSame('game-reversi-field-neon', $inventory->snapshot($mgwId)['equipped']['game_reversi_theme'] ?? null, 'Explicit field equip must use game_reversi_theme');

$pieceQuote = $store->quote($mgwId, 'reversi-pieces-metal');
$piecePurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-reversi-user', [
    'request_token'=>'store:mvp19-7-reversi-pieces-0002', 'offer_id'=>'reversi-pieces-metal',
    'price_coins'=>$pieceQuote['price_coins'], 'item_ids'=>$pieceQuote['item_ids'],
]);
$assertSame(false, $piecePurchase['auto_equipped'], 'Buying Reversi pieces must not auto-equip');
$store->equipGameItem($mgwId, 'game-reversi-pieces-metal');
$assertSame('game-reversi-pieces-metal', $inventory->snapshot($mgwId)['equipped']['game_reversi_elements'] ?? null, 'Explicit piece equip must use game_reversi_elements');

foreach ([['placement',2500,'0003'],['line',5000,'0004'],['mass-flip',7500,'0005']] as [$variant,$price,$token]) {
    $quote = $store->quote($mgwId, 'reversi-effect-' . $variant);
    $assertSame($price, (int)$quote['price_coins'], 'Reversi effect price must stay canonical for ' . $variant);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-reversi-user', [
        'request_token'=>'store:mvp19-7-reversi-effect-' . $token, 'offer_id'=>'reversi-effect-' . $variant,
        'price_coins'=>$quote['price_coins'], 'item_ids'=>$quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Buying Reversi effects must not auto-equip');
}
$store->equipGameItem($mgwId, 'game-reversi-effect-placement');
$store->equipGameItem($mgwId, 'game-reversi-effect-line');
$assertSame('game-reversi-effect-line', $inventory->snapshot($mgwId)['equipped']['game_reversi_effect'] ?? null, 'Equipping a second Reversi effect must replace the first in the same slot');
$inventory->unequip($mgwId, 'game_reversi_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_reversi_effect'] ?? null, 'Reversi effect slot must support explicit unequip');

$wrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-reversi-store-v1.js');
$css = (string)file_get_contents($root . '/app/assets/css/games/reversi/store-cosmetics-v1.css');
$topWrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-board-source-wrapper.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';
$assertTrue(str_contains($topWrapper, 'store-screen-reversi-store-v1.js?v=3'), 'Active accepted Store entrypoint must install the Reversi presentation layer');
$assertTrue(str_contains((string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? ''), 'mvp19_7=reversi-store-v1'), 'Active Store URL must be cache-busted for MVP-19.7');
foreach (['green','dark','marble','neon'] as $variant) $assertTrue(str_contains($css, 'theme-' . $variant) || $variant === 'green', 'Reversi Store CSS must style field ' . $variant);
foreach (['classic','marble','metal','neon'] as $variant) $assertTrue(str_contains($css, 'pieces-' . $variant) || $variant === 'classic', 'Reversi Store CSS must style piece set ' . $variant);
foreach (['placement','line','mass-flip'] as $variant) $assertTrue(str_contains($wrapper, $variant), 'Reversi Store wrapper must own effect preview ' . $variant);
$assertTrue(!str_contains($wrapper, 'gameAction(') && !str_contains($wrapper, 'last_flipped_cells'), 'Store-only Reversi wrapper must not own gameplay mechanics');

echo "MVP-19.7 Reversi Store contract passed ({$assertions} assertions).\n";
