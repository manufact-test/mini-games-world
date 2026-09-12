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

if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('MVP-19.6 Checkers Store test requires pdo_sqlite.');

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
$assertSame(count(glob($databaseDir . '/migrations/*.php') ?: []), (int)$migration['executed_count'], 'Fixture must apply every current additive migration');

$rows = $database->fetchAll(
    "SELECT c.item_id, c.item_family, c.equip_slot, c.metadata_json, o.offer_id, o.price_coins, o.category, o.subcategory
     FROM mgw_product_catalog c
     INNER JOIN mgw_product_offers o ON o.item_id = c.item_id AND o.offer_type = 'item'
     WHERE c.item_family = 'game_checkers' AND c.catalog_status = 'active' AND o.offer_status = 'active'
     ORDER BY o.sort_order ASC"
);
$expectedIds = [
    'game-checkers-board-wood',
    'game-checkers-board-dark',
    'game-checkers-board-marble',
    'game-checkers-board-neon',
    'game-checkers-pieces-wood',
    'game-checkers-pieces-marble',
    'game-checkers-pieces-metal',
    'game-checkers-pieces-neon',
    'game-checkers-effect-move',
    'game-checkers-effect-capture',
    'game-checkers-effect-promotion',
];
$assertSame(11, count($rows), 'MVP-19.6 Store must expose eleven individual Checkers cosmetics');
$assertSame($expectedIds, array_column($rows, 'item_id'), 'Checkers catalogue identities and ordering must stay canonical');
$assertSame([3000,5000,8000,12000,3000,6000,9000,12500,2500,5000,7500], array_map('intval', array_column($rows, 'price_coins')), 'Checkers prices must use the approved grids');
$assertSame(['games'], array_values(array_unique(array_column($rows, 'category'))), 'Checkers items must remain in Games');
$assertSame(['checkers'], array_values(array_unique(array_column($rows, 'subcategory'))), 'Checkers items must use the Checkers subcategory');

$byLayer = ['theme'=>[], 'elements'=>[], 'effect'=>[]];
$events = [];
foreach ($rows as $row) {
    $metadata = json_decode((string)$row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
    $assertSame('checkers', (string)($metadata['game_type'] ?? ''), 'Every Checkers item must project game_type=checkers');
    $layer = (string)($metadata['layer'] ?? '');
    $assertTrue(isset($byLayer[$layer]), 'Checkers item must use an approved cosmetic layer');
    $byLayer[$layer][] = (string)$row['equip_slot'];
    if ($layer === 'effect') $events[(string)($metadata['variant'] ?? '')] = (string)($metadata['event'] ?? '');
}
$assertSame(4, count($byLayer['theme']), 'Checkers Store must contain four boards');
$assertSame(4, count($byLayer['elements']), 'Checkers Store must contain four piece sets');
$assertSame(3, count($byLayer['effect']), 'Checkers Store must contain three effects');
$assertSame(['game_checkers_theme'], array_values(array_unique($byLayer['theme'])), 'Factual board slot must remain game_checkers_theme');
$assertSame(['game_checkers_elements'], array_values(array_unique($byLayer['elements'])), 'All Checkers piece sets must share one elements slot');
$assertSame(['game_checkers_effect'], array_values(array_unique($byLayer['effect'])), 'All Checkers effects must share one effect slot');
$assertSame(['move'=>'move','capture'=>'capture','promotion'=>'promotion'], $events, 'Checkers effects must bind move, capture, and promotion events');

$bundleRows = $database->fetchAll("SELECT offer_id, price_coins, members_json FROM mgw_product_offers WHERE offer_id = 'checkers-premium-bundle' AND offer_status = 'active'");
$assertSame(1, count($bundleRows), 'Checkers premium bundle must be active exactly once');
$assertSame(34000, (int)$bundleRows[0]['price_coins'], 'Checkers premium bundle must cost 34,000 coins');
$assertSame([
    'game-checkers-board-neon',
    'game-checkers-pieces-neon',
    'game-checkers-effect-move',
    'game-checkers-effect-capture',
    'game-checkers-effect-promotion',
], json_decode((string)$bundleRows[0]['members_json'], true, 32, JSON_THROW_ON_ERROR), 'Checkers bundle must use the established premium pattern: top board, top pieces, all three effects');

$accounts = new AccountIdentityService($database, 3600);
$account = $accounts->resolveProviderIdentity('development', 'mvp19-6-checkers-user', 'browser_dev', ['username'=>'checkers-store'], 'mvp19-6-session');
$mgwId = (string)$account['mgw_id'];
$inventory = new ProductInventoryService($database);
$store = new CosmeticStoreService($database);

$snapshot = $store->snapshot($mgwId, 100000, []);
$checkers = $snapshot['games']['catalogs']['checkers'] ?? null;
$assertTrue(is_array($checkers), 'Store snapshot must expose a Checkers catalogue');
$assertSame('Шашки', (string)($checkers['title'] ?? ''), 'Checkers catalogue must use the Russian identity');
$assertSame(4, count($checkers['themes'] ?? []), 'Store snapshot must expose four Checkers boards');
$assertSame(4, count($checkers['elements'] ?? []), 'Store snapshot must expose four Checkers piece sets');
$assertSame(3, count($checkers['effects'] ?? []), 'Store snapshot must expose three Checkers effects');
$bundle = $snapshot['bundles']['checkers_bundle'] ?? null;
$assertTrue(is_array($bundle), 'Store snapshot must expose the Checkers premium bundle');
$assertSame('checkers', (string)($bundle['game_type'] ?? ''), 'Checkers bundle must identify its game');
$assertSame('Неоновый комплект шашек', (string)($bundle['display_name'] ?? ''), 'Checkers bundle must expose its Store title');
$assertSame(2, count($snapshot['bundles']['game_bundles'] ?? []), 'Game bundles must expose TTT and Checkers without regressing the existing bundle');
$assertSame(false, $snapshot['purchase_rules']['auto_equip'] ?? true, 'Purchase must never auto-equip');

$neonBoardQuote = $store->quote($mgwId, 'checkers-board-neon');
$assertSame(12000, (int)$neonBoardQuote['price_coins'], 'Neon Checkers board quote must stay 12,000 coins');
$neonBoardPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-checkers-neon-0001',
    'offer_id' => 'checkers-board-neon',
    'price_coins' => $neonBoardQuote['price_coins'],
    'item_ids' => $neonBoardQuote['item_ids'],
]);
$assertSame(false, $neonBoardPurchase['auto_equipped'], 'Buying a Checkers board must never auto-equip it');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Purchased Checkers board must remain inactive until explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-board-neon');
$assertSame('game-checkers-board-neon', $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Explicit board equip must use the factual Checkers theme slot');

$bundleQuote = $store->quote($mgwId, 'checkers-premium-bundle');
$assertSame(27500, (int)$bundleQuote['price_coins'], 'Owning the 12,000 neon board must reduce the bundle to the remaining 27,500 separate value');
$assertSame(34000, (int)$bundleQuote['full_price_coins'], 'Partial bundle quote must retain the 34,000 full price');
$assertSame(4, count($bundleQuote['item_ids']), 'Partial bundle quote must exclude the already-owned neon board');
$assertTrue(!in_array('game-checkers-board-neon', $bundleQuote['item_ids'], true), 'Bundle must never attempt duplicate ownership');
$assertStoreError(static fn() => $store->quote($mgwId, 'checkers-board-neon'), 'already_owned', 'Already-owned board must be duplicate-protected');

$woodPiecesQuote = $store->quote($mgwId, 'checkers-pieces-wood');
$woodPiecesPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-pieces-wood-0002',
    'offer_id' => 'checkers-pieces-wood',
    'price_coins' => $woodPiecesQuote['price_coins'],
    'item_ids' => $woodPiecesQuote['item_ids'],
]);
$assertSame(false, $woodPiecesPurchase['auto_equipped'], 'Buying Checkers pieces must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_elements'] ?? null, 'Purchased piece set must remain inactive until explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-pieces-wood');
$assertSame('game-checkers-pieces-wood', $inventory->snapshot($mgwId)['equipped']['game_checkers_elements'] ?? null, 'Piece set must equip into the shared elements slot');

foreach ([['move',2500,'0003'],['capture',5000,'0004']] as [$variant,$price,$tokenSuffix]) {
    $quote = $store->quote($mgwId, 'checkers-effect-' . $variant);
    $assertSame($price, (int)$quote['price_coins'], 'Checkers effect price must stay canonical for ' . $variant);
    $purchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
        'request_token' => 'store:mvp19-6-effect-' . $variant . '-' . $tokenSuffix,
        'offer_id' => 'checkers-effect-' . $variant,
        'price_coins' => $quote['price_coins'],
        'item_ids' => $quote['item_ids'],
    ]);
    $assertSame(false, $purchase['auto_equipped'], 'Buying Checkers effect must never auto-equip ' . $variant);
}
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_effect'] ?? null, 'Purchased effects must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-effect-move');
$assertSame('game-checkers-effect-move', $inventory->snapshot($mgwId)['equipped']['game_checkers_effect'] ?? null, 'Move effect must equip in the shared effect slot');
$store->equipGameItem($mgwId, 'game-checkers-effect-capture');
$assertSame('game-checkers-effect-capture', $inventory->snapshot($mgwId)['equipped']['game_checkers_effect'] ?? null, 'Equipping capture must replace move in the same slot');
$inventory->unequip($mgwId, 'game_checkers_effect');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_effect'] ?? null, 'Checkers effect slot must support explicit unequip');

$baseRenderer = (string)file_get_contents($root . '/app/assets/js/games/checkers/renderer.js');
$baseCss = (string)file_get_contents($root . '/app/assets/css/games/checkers/game.css');
$liveWrapper = (string)file_get_contents($root . '/app/assets/js/checkers-cosmetics/renderer-board-themes.js');
$cosmeticsCss = (string)file_get_contents($root . '/app/assets/css/games/checkers/cosmetics.css');
$correctiveCss = (string)file_get_contents($root . '/app/assets/css/games/checkers/store-visual-corrective-v2.css');
$storeScreen = (string)file_get_contents($root . '/app/assets/js/screens/store-screen.js');
$storeWrapper = (string)file_get_contents($root . '/app/assets/js/screens/store-screen-checkers-wrapper.js');
$manifest = require $root . '/app/runtime/client/version-manifest.php';

$gitBlobSha = static fn(string $content): string => sha1('blob ' . strlen($content) . "\0" . $content);
$assertSame('e362239b1388a1f752d2d0e67ae69a7cc9207926', $gitBlobSha($baseRenderer), 'Accepted Checkers renderer must remain byte-identical');
$assertSame('12d2f211c48c1f49793744315c004fd33afc5bcf', $gitBlobSha($baseCss), 'Accepted Checkers layout CSS must remain byte-identical');
$assertTrue(str_contains($liveWrapper, 'game_checkers_theme') && !str_contains($liveWrapper, 'game_checkers_elements') && !str_contains($liveWrapper, 'game_checkers_effect'), 'Phase A must leave live Checkers bounded to the existing board projection');
$assertTrue(!str_contains($liveWrapper, 'gameAction(') && !str_contains($liveWrapper, 'time_left') && !str_contains($liveWrapper, 'turn_started_at'), 'Checkers cosmetics wrapper must never own mechanics or timers');

foreach (['wood','dark','marble','neon'] as $variant) {
    $assertTrue(str_contains($cosmeticsCss, 'data-checkers-theme="' . $variant . '"'), 'Live CSS must preserve Checkers board variant ' . $variant);
}
foreach (['wood','marble','metal','neon'] as $variant) {
    $assertTrue(str_contains($cosmeticsCss, 'data-cosmetic-layer="elements"][data-cosmetic-variant="' . $variant . '"]'), 'Store CSS must preserve Checkers piece set ' . $variant);
}
foreach (['move','capture','promotion'] as $variant) {
    $assertTrue(str_contains($storeScreen, 'checkers-store-fx-${variant}') || str_contains($storeScreen, 'checkers-store-fx-' . $variant) || str_contains($storeScreen, 'checkers-store-fx-${safeVariant}'), 'Store preview renderer must own Checkers effect scenes');
    $assertTrue(str_contains($correctiveCss, 'checkers-store-fx-' . $variant), 'Corrective CSS must style Checkers effect ' . $variant);
}
$assertTrue(str_contains($storeScreen, 'checkersMiniBoardMarkup') && str_contains($storeScreen, 'Array.from({ length:64 }'), 'Store boards must remain full 8x8 previews');
$assertTrue(str_contains($correctiveCss, 'max-width:122px') && str_contains($correctiveCss, 'transform:none') && str_contains($correctiveCss, 'width:100%'), 'Corrective board preview must fit the card without decorative crop');
$assertTrue(str_contains($correctiveCss, 'max-width:116px') && str_contains($correctiveCss, 'width:50px'), 'Corrective piece preview must fit all discs inside the card');
$assertTrue(str_contains($correctiveCss, 'mgw-checkers-store-auto-move-piece') && str_contains($correctiveCss, '@media(prefers-reduced-motion:reduce)') && !str_contains($correctiveCss, 'infinite'), 'Store effects must autoplay once with reduced-motion safety and no infinite animation');
$assertTrue(str_contains($correctiveCss, '.checkers-fx-play{display:none!important}'), 'Store effects must not show a manual Profile-style play button');
$assertTrue(!str_contains($storeWrapper, 'installCheckersEffectPreviewIntents') && !str_contains($storeWrapper, 'playCheckersEffectPreview') && !str_contains($storeWrapper, 'setInterval') && !str_contains($storeWrapper, 'MutationObserver'), 'Store effect preview must be passive and bounded without click playback, observers, or polling');
$assertTrue(str_contains($storeWrapper, 'injectCheckersBundleIntoGame') && str_contains($storeWrapper, 'data-mgw-checkers-inline-bundle') && str_contains($storeWrapper, 'bridgeInlineBundlePurchase'), 'Checkers 34k bundle must be visible inside Games -> Checkers and bridge to the canonical purchase owner');
$assertTrue(str_contains($storeScreen, "bundleGameType === 'checkers'") && str_contains($storeScreen, 'store-v2-confirm-game'), 'Purchase confirmation must preserve Checkers-specific previews');

$storeTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$storeBaseTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog'] ?? '');
$checkersTarget = (string)($manifest['imports']['./assets/js/games/checkers/renderer.js?v=57'] ?? '');
$assertTrue(str_contains($storeTarget, 'store-screen-checkers-wrapper.js') && str_contains($storeTarget, 'mvp19_6=visual-corrective-v2'), 'Active Store graph must select the Checkers visual corrective wrapper');
$assertTrue(str_contains($storeBaseTarget, 'mvp19_6=full-checkers-store'), 'Active import graph must preserve the native Store owner under the corrective wrapper');
$assertTrue(str_contains($checkersTarget, 'renderer-board-themes.js') && str_contains($checkersTarget, 'mvp19_6=board-themes'), 'Phase A must keep live Checkers on the existing board-only wrapper');

fwrite(STDOUT, "PASS: MVP-19.6 complete Checkers Store ({$assertions} assertions)\n");