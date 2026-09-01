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
$assertSame([2500,5000,7500], $effectPrices, 'Effect prices must remain unchanged');

$effectRows = $database->fetchAll(
    "SELECT item_id, equip_slot, metadata_json
     FROM mgw_product_catalog
     WHERE item_id IN ('game-ttt-effect-sign','game-ttt-effect-winning-line','game-ttt-effect-strike')
     ORDER BY item_id ASC"
);
$assertSame(3, count($effectRows), 'All three purchased effect identities must remain in the catalogue');
$effectMetadata = [];
foreach ($effectRows as $row) {
    $itemId = (string)$row['item_id'];
    $assertSame('game_tictactoe_effect', (string)$row['equip_slot'], 'Every Tic Tac Toe effect must use one mutually exclusive slot');
    $effectMetadata[$itemId] = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
}
$assertSame('Импульс знака', (string)($effectMetadata['game-ttt-effect-sign']['display_name'] ?? ''), 'Sign effect name must remain user-facing');
$assertSame('impact', (string)($effectMetadata['game-ttt-effect-sign']['variant'] ?? ''), 'Sign effect must use impact variant');
$assertSame('Искры хода', (string)($effectMetadata['game-ttt-effect-winning-line']['display_name'] ?? ''), 'Historical winning-line purchase must become Sparks without losing ownership identity');
$assertSame('sparks', (string)($effectMetadata['game-ttt-effect-winning-line']['variant'] ?? ''), 'Historical winning-line item must use sparks variant');
$assertSame('Импульс хода', (string)($effectMetadata['game-ttt-effect-strike']['display_name'] ?? ''), 'Historical strike purchase must remain Move Pulse');
$assertSame('wave', (string)($effectMetadata['game-ttt-effect-strike']['variant'] ?? ''), 'Move Pulse must use wave variant');
foreach ($effectMetadata as $metadata) {
    $assertSame('move', (string)($metadata['event'] ?? ''), 'Every effect must fire during a move');
}

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
$assertSame($expectedBundleMembers, $bundleMembers, 'Premium bundle must preserve all previously purchasable item identities');
$assertSame(34000, (int)$bundleRow['price_coins'], 'Premium bundle price must remain 34,000');

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
$assertSame(34000, $store->quote($mgwId, 'ttt-premium-bundle')['price_coins'], 'Complete premium bundle quote must remain 34,000');
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
$assertSame('game-ttt-field-dark', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'Explicit theme equip must use the generic inventory slot owner');

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
$assertSame('game-ttt-field-dark', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_theme'] ?? null, 'Bundle fulfillment must preserve explicit theme equipment');

$store->equipGameItem($mgwId, 'game-ttt-marks-neon');
$store->equipGameItem($mgwId, 'game-ttt-effect-sign');
$assertSame('game-ttt-effect-sign', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_effect'] ?? null, 'First effect must occupy the common effect slot');
$store->equipGameItem($mgwId, 'game-ttt-effect-winning-line');
$assertSame('game-ttt-effect-winning-line', $inventory->snapshot($mgwId)['equipped']['game_tictactoe_effect'] ?? null, 'Choosing Sparks must replace the previously selected effect');
$store->equipGameItem($mgwId, 'game-ttt-effect-strike');
$equipped = $inventory->snapshot($mgwId)['equipped'];
$assertSame('game-ttt-marks-neon', $equipped['game_tictactoe_elements'] ?? null, 'Element set must retain its independent reusable equip slot');
$assertSame('game-ttt-effect-strike', $equipped['game_tictactoe_effect'] ?? null, 'Choosing Move Pulse must replace every other effect');
$assertTrue(!isset($equipped['game_tictactoe_effect_sign']), 'Legacy sign effect slot must not be present');
$assertTrue(!isset($equipped['game_tictactoe_effect_winning_line']), 'Legacy winning-line effect slot must not be present');
$assertTrue(!isset($equipped['game_tictactoe_effect_strike_through']), 'Legacy strike-through effect slot must not be present');
$inventory->unequip($mgwId, 'game_tictactoe_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['game_tictactoe_effect']), 'Generic inventory unequip must allow the user to run with no effect selected');
$assertStoreError(static fn() => $store->quote($mgwId, 'ttt-premium-bundle'), 'already_owned', 'Completed premium collection must reject duplicate purchase');

$responseSource = (string)file_get_contents($root . '/bot/helpers/response.php');
$rendererSource = (string)file_get_contents($root . '/app/assets/js/games/tictactoe/renderer.js');
$storeSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$storeCss = (string)file_get_contents($root . '/app/assets/css/screens/store-v2.css');
$cosmeticsCss = (string)file_get_contents($root . '/app/assets/css/games/tictactoe/cosmetics.css');
$mainCss = (string)file_get_contents($root . '/app/assets/css/main.css');
$apiSource = (string)file_get_contents($root . '/app/assets/js/api/client.js');
$endpointSource = (string)file_get_contents($root . '/bot/cosmetic-store.php');

$assertTrue(str_contains($responseSource, "c.item_type = \\'game\\'") && str_contains($responseSource, "\$player['game_cosmetics']"), 'Game response must project canonical equipped cosmetics for both players');
$assertTrue(str_contains($rendererSource, 'player?.game_cosmetics?.slots') && str_contains($rendererSource, 'slots.game_tictactoe_effect'), 'Renderer must consume the canonical single effect slot');
$assertTrue(str_contains($rendererSource, 'ttt-effect-mark') && str_contains($rendererSource, 'ttt-fx-impact') && str_contains($rendererSource, 'ttt-fx-sparks') && str_contains($rendererSource, 'ttt-fx-wave'), 'Renderer must attach every effect to the changed mark');
$assertTrue(!str_contains($rendererSource, 'findWinningCells') && !str_contains($rendererSource, 'ttt-winning-cell'), 'No effect may depend on a post-game winner presentation');
$assertTrue(!str_contains($rendererSource, 'api.gameAction') && !str_contains($rendererSource, 'time_left'), 'Cosmetic renderer must not become an action or timer owner');
$assertTrue(str_contains($cosmeticsCss, 'tttFxImpact') && str_contains($cosmeticsCss, 'tttFxSparksBurst') && str_contains($cosmeticsCss, 'tttFxWaveRing'), 'All three move effects must have distinct shared animations');
$assertTrue(!str_contains($cosmeticsCss, 'storeTttWinningLine') && !str_contains($cosmeticsCss, 'text-decoration:line-through'), 'Legacy Store-only winning/strike presentation must be retired');
$assertTrue(str_contains($storeSource, 'Один выбранный эффект срабатывает при каждом ходе') && str_contains($storeSource, 'data-store-v2-unequip'), 'Store must explain exclusivity and provide a remove action');
$assertTrue(str_contains($storeSource, 'ttt-mark ttt-effect-mark ttt-fx-${safeVariant}') && str_contains($storeSource, '>Снять</button>'), 'Store preview and selection controls must use runtime effect classes and a real remove button');
$assertTrue(str_contains($apiSource, 'cosmeticStoreUnequip') && str_contains($endpointSource, "if (\$action === 'unequip')"), 'Unequip must be wired from client through the Store endpoint');
$assertTrue(str_contains($storeCss, 'grid-template-columns:116px minmax(0,1fr)') && str_contains($storeCss, 'min-height:39px'), 'Mobile game offers must retain readable card geometry and usable actions');
$assertTrue(str_contains($mainCss, 'store-v2.css?v=5') && str_contains($mainCss, 'game-cosmetics-polish-v3'), 'Active CSS graph must preserve the accepted Store visual owner');

fwrite(STDOUT, "PASS: MVP-19.4 game cosmetics framework + C2.1 single move-effect slot ({$assertions} assertions)\n");
