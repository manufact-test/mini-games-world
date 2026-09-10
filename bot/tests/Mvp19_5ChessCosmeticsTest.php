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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.5 Chess cosmetics test requires pdo_sqlite.');

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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys = ON');
$database = new PdoDatabaseConnection($pdo);
$runner = new MigrationRunner($database, $databaseDir . '/migrations');
$migration = $runner->migrate(false);
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every additive migration');

$assertSame(11, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_catalog WHERE item_type = 'game' AND item_family = 'game_chess' AND catalog_status = 'active'"), 'Chess catalogue must contain eleven permanent items');
$assertSame(11, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'item' AND category = 'games' AND subcategory = 'chess' AND offer_status = 'active'"), 'Store must expose eleven Chess item offers');
$assertSame(0, (int)$database->fetchValue("SELECT COUNT(*) FROM mgw_product_offers WHERE offer_type = 'bundle' AND subcategory = 'chess'"), 'Chess premium bundle composition must remain unpublished until the product-owner gate');

$pricesForLayer = static function (string $layer) use ($database): array {
    return array_map('intval', array_column($database->fetchAll(
        "SELECT o.price_coins FROM mgw_product_offers o
         INNER JOIN mgw_product_catalog c ON c.item_id = o.item_id
         WHERE o.category = 'games' AND o.subcategory = 'chess'
           AND json_extract(c.metadata_json, '$.layer') = :layer
         ORDER BY o.sort_order",
        ['layer'=>$layer]
    ), 'price_coins'));
};
$assertSame([3000,5000,8000,12000], $pricesForLayer('theme'), 'Chess board prices must match the canonical grid');
$assertSame([3000,6000,9000,12500], $pricesForLayer('elements'), 'Chess piece prices must match the canonical grid');
$assertSame([2500,5000,7500], $pricesForLayer('effect'), 'Chess effect prices must match the canonical grid');

$effectRows = $database->fetchAll("SELECT item_id, equip_slot, metadata_json FROM mgw_product_catalog WHERE item_family = 'game_chess' AND equip_slot = 'game_chess_effect' ORDER BY item_id");
$assertSame(3, count($effectRows), 'All Chess effects must share one mutually exclusive slot');
$events = [];
foreach ($effectRows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $events[] = (string)($metadata['event'] ?? '');
    $assertSame('chess', (string)($metadata['game_type'] ?? ''), 'Every Chess effect must identify its game');
}
sort($events, SORT_STRING);
$assertSame(['capture','check','move'], $events, 'Chess effects must cover move, capture and check');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-5-chess-user', 'browser_dev', ['username'=>'chess-cosmetics'], 'mvp19-5-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);
$snapshot = $store->snapshot($mgwId, 100000, []);
$assertSame(4, count($snapshot['games']['catalogs']['chess']['themes'] ?? []), 'Store snapshot must expose four Chess boards');
$assertSame(4, count($snapshot['games']['catalogs']['chess']['elements'] ?? []), 'Store snapshot must expose four Chess piece sets');
$assertSame(3, count($snapshot['games']['catalogs']['chess']['effects'] ?? []), 'Store snapshot must expose three Chess effects');
$assertSame(4, count($snapshot['games']['catalogs']['tictactoe']['themes'] ?? []), 'Generalizing Store catalogs must preserve the accepted Tic Tac Toe pilot');

$quote = $store->quote($mgwId, 'chess-board-neon');
$purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-chess-user', [
    'request_token' => 'store:mvp19-5-chess-neon-0001',
    'offer_id' => 'chess-board-neon',
    'price_coins' => $quote['price_coins'],
    'item_ids' => $quote['item_ids'],
]);
$assertSame(false, $purchase['auto_equipped'], 'Chess purchase must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_chess_theme'] ?? null, 'Purchased Chess board must remain inactive until explicit equip');
$store->equipGameItem($mgwId, 'game-chess-board-neon');
$assertSame('game-chess-board-neon', $inventory->snapshot($mgwId)['equipped']['game_chess_theme'] ?? null, 'Chess board must use the generic inventory equip owner');

$moveQuote = $store->quote($mgwId, 'chess-effect-move');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-chess-user', [
    'request_token' => 'store:mvp19-5-chess-move-0002',
    'offer_id' => 'chess-effect-move',
    'price_coins' => $moveQuote['price_coins'],
    'item_ids' => $moveQuote['item_ids'],
]);
$checkQuote = $store->quote($mgwId, 'chess-effect-check');
$store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-chess-user', [
    'request_token' => 'store:mvp19-5-chess-check-0003',
    'offer_id' => 'chess-effect-check',
    'price_coins' => $checkQuote['price_coins'],
    'item_ids' => $checkQuote['item_ids'],
]);
$store->equipGameItem($mgwId, 'game-chess-effect-move');
$store->equipGameItem($mgwId, 'game-chess-effect-check');
$assertSame('game-chess-effect-check', $inventory->snapshot($mgwId)['equipped']['game_chess_effect'] ?? null, 'Choosing a Chess effect must replace the previous effect in the single slot');
$inventory->unequip($mgwId, 'game_chess_effect');
$assertTrue(!isset($inventory->snapshot($mgwId)['equipped']['game_chess_effect']), 'Chess effect must support explicit remove');

$rendererSource = (string)file_get_contents($root . '/app/assets/js/games/chess/renderer.js');
$storeSource = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$cssSource = (string)file_get_contents($root . '/app/assets/css/games/chess/cosmetics.css');
$cssEntrySource = (string)file_get_contents($root . '/app/assets/css/main-mvp19-5-chess.css');
$serviceSource = (string)file_get_contents($root . '/bot/catalog/CosmeticStoreService.php');
$manifestSource = (string)file_get_contents($root . '/app/runtime/client/version-manifest.php');
$assertTrue(str_contains($serviceSource, '$gameCatalogs') && str_contains($serviceSource, "'chess' => 'Шахматы'"), 'Store service must expose game catalogs generically instead of adding a parallel Chess store');
$assertTrue(str_contains($storeSource, 'activeGameCatalog') && str_contains($storeSource, "gameType === 'chess'"), 'Store UI must use the shared Games tab with Chess selection');
$assertTrue(str_contains($rendererSource, "game_chess_theme") && str_contains($rendererSource, "game_chess_elements") && str_contains($rendererSource, "game_chess_effect"), 'Chess renderer must consume the three canonical cosmetic slots');
$assertTrue(str_contains($rendererSource, 'player?.game_cosmetics?.slots'), 'Chess renderer must consume public owner-specific projection');
$assertTrue(!str_contains($rendererSource, 'api.gameAction') && !str_contains($rendererSource, 'time_left'), 'Chess cosmetics must not own game actions or timers');
$assertTrue(str_contains($cssSource, 'data-chess-theme="wood"') && str_contains($cssSource, 'data-chess-theme="tournament-dark"') && str_contains($cssSource, 'data-chess-theme="marble"') && str_contains($cssSource, 'data-chess-theme="neon"'), 'All four Chess board themes need distinct presentation');
$assertTrue(str_contains($cssSource, 'data-chess-piece-style="wood"') && str_contains($cssSource, 'data-chess-piece-style="marble"') && str_contains($cssSource, 'data-chess-piece-style="metal"') && str_contains($cssSource, 'data-chess-piece-style="neon"'), 'All four Chess piece sets need distinct presentation');
$assertTrue(str_contains($cssSource, 'chessFxMoveRing') && str_contains($cssSource, 'chessFxCaptureFlash') && str_contains($cssSource, 'chessFxCheckRing'), 'All three Chess effects need distinct motion');
$assertTrue(str_contains($cssSource, '@media(prefers-reduced-motion:reduce)'), 'Chess cosmetics must remain reduced-motion safe');
$assertTrue(str_contains($cssEntrySource, "./main.css?v=191") && str_contains($cssEntrySource, "./games/chess/cosmetics.css?v=1"), 'Chess CSS entry must compose accepted global CSS with the isolated cosmetic module');
$assertTrue(str_contains($manifestSource, "./assets/js/games/chess/renderer.js?v=69&mvp19_5=cosmetics") && str_contains($manifestSource, "main-mvp19-5-chess.css?v=1"), 'Active v110 manifest must select the Chess renderer and cosmetic stylesheet');

echo "MVP-19.5 Chess cosmetics contract passed ({$assertions} assertions).\n";