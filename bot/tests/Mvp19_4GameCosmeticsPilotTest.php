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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.4 pilot test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertStoreError = static function (callable $callback, string $reason, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (CosmeticStoreException $error) {
        if ($error->reason === $reason) return;
        throw new RuntimeException($message . ': unexpected reason ' . $error->reason);
    }
    throw new RuntimeException($message . ': no store error was thrown');
};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Pilot fixture must apply every additive migration');

$assertSame(11, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_type = 'game' AND item_family = 'game_tictactoe' AND catalog_status = 'active'"), 'Pilot catalogue must contain eleven permanent Tic Tac Toe items');
$assertSame(11, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'item' AND category = 'games' AND subcategory = 'tictactoe' AND offer_status = 'active'"), 'Games Store must expose eleven individual pilot offers');

$themePrices = array_map('intval', array_column($database->fetchAll(
    "SELECT o.price_coins FROM mgw_product_offers o
     INNER JOIN mgw_product_catalog c ON c.item_id = o.item_id
     WHERE o.category = 'games' AND o.subcategory = 'tictactoe'
       AND json_extract(c.metadata_json, '$.layer') = 'theme'
     ORDER BY o.sort_order"
), 'price_coins'));
$elementPrices = array_map('intval', array_column($database->fetchAll(
    "SELECT o.price_coins FROM mgw_product_offers o
     INNER JOIN mgw_product_catalog c ON c.item_id = o.item_id
     WHERE o.category = 'games' AND o.subcategory = 'tictactoe'
       AND json_extract(c.metadata_json, '$.layer') = 'elements'
     ORDER BY o.sort_order"
), 'price_coins'));
$effectPrices = array_map('intval', array_column($database->fetchAll(
    "SELECT o.price_coins FROM mgw_product_offers o
     INNER JOIN mgw_product_catalog c ON c.item_id = o.item_id
     WHERE o.category = 'games' AND o.subcategory = 'tictactoe'
       AND json_extract(c.metadata_json, '$.layer') = 'effect'
     ORDER BY o.sort_order"
), 'price_coins'));
$assertSame([3000,5000,8000,12000], $themePrices, 'Theme prices must match the common canonical grid');
$assertSame([3000,6000,9000,12500], $elementPrices, 'Element prices must match the common canonical grid');
$assertSame([2500,5000,7500], $effectPrices, 'Effect prices must match the common canonical grid');
$strikeMetadata = json_decode((string)$database->fetchValue("SELECT metadata_json FROM mgw_product_catalog WHERE item_id = 'game-ttt-effect-strike'"), true, 32, JSON_THROW_ON_ERROR);
$assertSame('Перечёркивание', (string)($strikeMetadata['display_name'] ?? ''), 'The strike effect must use a clear Russian catalogue title');

$bundleRow = $database->fetchAll("SELECT price_coins, members_json FROM mgw_product_offers WHERE offer_id = 'ttt-premium-bundle' AND offer_status = 'active'")[0] ?? null;
$assertTrue(is_array($bundleRow), 'Premium pilot bundle must be active');
$bundleMembers = json_decode((string)$bundleRow['members_json'], true, 32, JSON_THROW_ON_ERROR);
sort($bundleMembers, SORT_STRING);
$expectedBundleMembers = [
    'game-ttt-effect-sign',
    'game-ttt-effect-strike',
    'game-ttt-effect-winning-line',
    'game-ttt-field-neon',
    'game-ttt-marks-neon',
];
sort($expectedBundleMembers, SORT_STRING);
$assertSame($expectedBundleMembers, $bundleMembers, 'Premium bundle must contain the neon field, neon marks and all three effects');
$assertSame(34000, (int)$bundleRow['price_coins'], 'Premium bundle price must be exactly 34,000');
$assertSame(39500, array_sum([12000,12500,2500,5000,7500]), 'Premium members must preserve the historical 39,500 separate sum');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-4-pilot-user', 'browser_dev', ['username'=>'game-cosmetics'], 'mvp19-4-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$assertSame(3, count($inventory->snapshot($mgwId)['owned']), 'Game cosmetics must not be silently starter-granted');
$snapshot = $store->snapshot($mgwId, 100000, []);
$assertSame(['coins','profile','games','bundles'], array_column($snapshot['tabs'], 'id'), 'Store must keep only the four canonical buyable tabs');
$assertSame(4, count($snapshot['games']['catalogs']['tictactoe']['themes'] ?? []), 'Pilot snapshot must expose four themes');
$assertSame(4, count($snapshot['games']['catalogs']['tictactoe']['elements'] ?? []), 'Pilot snapshot must expose four element sets');
$assertSame(3, count($snapshot['games']['catalogs']['tictactoe']['effects'] ?? []), 'Pilot snapshot must expose three effects');
$assertSame(34000, $store->quote($mgwId, 'ttt-premium-bundle')['price_coins'], 'Complete premium bundle quote must be 34,000');
$assertStoreError(static fn() => $store->equipGameItem($mgwId, 'game-ttt-field-dark'), 'item_not_owned', 'Unowned game cosmetics must not be equippable');

$darkQuote = $store->quote($mgwId, 'ttt-field-dark');
$darkPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-pilot-user', [
    'request_token' => 'store:mvp19-4-dark-0001',
    'offer_id' => 'ttt-field-dark',
    'price_coins' => $darkQuote['price_coins'],
    'item_ids' => $darkQuote['item_ids'],
]);
$assertSame(false, $darkPurchase['auto_equipped'], 'A game cosmetic purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'Purchased theme must remain inactive until explicit equip');
$store->equipGameItem($mgwId, 'game-ttt-field-dark');
$assertSame('game-ttt-field-dark', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'Explicit equip must use the generic inventory slot owner');

$neonQuote = $store->quote($mgwId, 'ttt-field-neon');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-pilot-user', [
    'request_token' => 'store:mvp19-4-neon-0002',
    'offer_id' => 'ttt-field-neon',
    'price_coins' => $neonQuote['price_coins'],
    'item_ids' => $neonQuote['item_ids'],
]);
$assertSame('game-ttt-field-dark', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'A second theme purchase must not replace the selected theme');

$partialBundle = $store->quote($mgwId, 'ttt-premium-bundle');
$assertSame(27500, $partialBundle['price_coins'], 'Owned premium field must be removed from the bundle without duplicate compensation');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-pilot-user', [
    'request_token' => 'store:mvp19-4-bundle-0003',
    'offer_id' => 'ttt-premium-bundle',
    'price_coins' => $partialBundle['price_coins'],
    'item_ids' => $partialBundle['item_ids'],
]);
$assertSame('game-ttt-field-dark', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'Bundle fulfillment must also preserve explicit equipment');

foreach ([
    'game-ttt-marks-neon',
    'game-ttt-effect-sign',
    'game-ttt-effect-winning-line',
    'game-ttt-effect-strike',
] as $itemId) {
    $store->equipGameItem($mgwId, $itemId);
}
$equipped = $inventory->snapshot($mgwId)['equipped'];
$assertSame('game-ttt-marks-neon', $equipped['game_tictactoe_elements'] ?? null, 'Element set must have its own reusable equip slot');
$assertSame('game-ttt-effect-sign', $equipped['game_tictactoe_effect_sign'] ?? null, 'Sign effect must have an independent event slot');
$assertSame('game-ttt-effect-winning-line', $equipped['game_tictactoe_effect_winning_line'] ?? null, 'Winning-line effect must have an independent event slot');
$assertSame('game-ttt-effect-strike', $equipped['game_tictactoe_effect_strike_through'] ?? null, 'Strike-through effect must have an independent event slot');
$assertStoreError(static fn() => $store->quote($mgwId, 'ttt-premium-bundle'), 'already_owned', 'Completed premium collection must reject duplicate purchase');

$responseSource = (string)file_get_contents($root . '/bot/helpers/response.php');
$rendererSource = (string)file_get_contents($root . '/app/assets/js/games/tictactoe/renderer.js');
$storeSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$storeCss = (string)file_get_contents($root . '/app/assets/css/screens/store-v2.css');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$assertTrue(str_contains($responseSource, "c.item_type = \\'game\\'") && str_contains($responseSource, "\$player['game_cosmetics']"), 'Game response must project canonical equipped cosmetics for both players');
$assertTrue(str_contains($rendererSource, 'player?.game_cosmetics?.slots') && str_contains($rendererSource, 'game_tictactoe_effect_winning_line'), 'Pilot renderer must consume public cosmetics without touching rules');
$assertTrue(!str_contains($rendererSource, 'api.gameAction') && !str_contains($rendererSource, 'time_left'), 'Cosmetic renderer must not become an action or timer owner');
$assertTrue(str_contains($storeSource, 'Оформление игры') && str_contains($storeSource, 'Купить</span><b>${price} коинов'), 'Games Store must explain the collection and include the exact purchase price in every action');
$assertTrue(str_contains($storeSource, 'Фон и сетка игрового поля') && str_contains($storeSource, 'Внешний вид крестиков и ноликов'), 'Games Store must explain what each cosmetic group changes');
$assertTrue(!str_contains($storeSource, 'сразу показываются в матче') && !str_contains($storeSource, '${items.length} варианта'), 'Games Store must not repeat self-evident match copy or decorative offer counters');
$assertTrue(str_contains($storeSource, 'store-v2-effect-x') && str_contains($storeSource, "safeVariant === 'sign' ? '<b aria-hidden=\"true\">+</b>' : ''"), 'Effect cards must render one straight CSS X and reserve the plus badge for the sign effect');
$assertTrue(str_contains($storeCss, 'grid-template-columns:116px minmax(0,1fr)') && str_contains($storeCss, 'min-height:39px'), 'Mobile game offers must render as readable preview cards with usable actions');
$assertTrue(str_contains($storeCss, '.store-v2-effect-x::before,.store-v2-effect-x::after'), 'Effect X must use symmetrical CSS geometry instead of a font glyph');
$assertTrue(str_contains($mainCss, 'store-v2.css?v=5') && str_contains($mainCss, 'game-cosmetics-polish-v3'), 'Active CSS graph must cache-bust the polished game catalogue instead of reusing the previous Store stylesheet');

fwrite(STDOUT, "PASS: MVP-19.4 game cosmetics framework and Tic Tac Toe pilot ({$assertions} assertions)\n");
