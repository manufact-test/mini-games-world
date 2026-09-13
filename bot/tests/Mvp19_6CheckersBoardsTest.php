<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog/CosmeticStoreService.php';

use MiniGamesWorld\Catalog\CosmeticStoreService;

$root = dirname(__DIR__, 2);
$tempRoot = sys_get_temp_dir() . '/mgw-mvp19-6-checkers-' . bin2hex(random_bytes(4));
if (!mkdir($tempRoot, 0777, true) && !is_dir($tempRoot)) {
    throw new RuntimeException('Unable to create temp root');
}

$database = $tempRoot . '/test.sqlite';
$pdo = new PDO('sqlite:' . $database);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE user_wallets (mgw_user_id TEXT PRIMARY KEY, balance INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE user_inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, mgw_user_id TEXT NOT NULL, item_id TEXT NOT NULL, item_type TEXT NOT NULL, is_equipped INTEGER NOT NULL DEFAULT 0, metadata_json TEXT, acquired_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(mgw_user_id, item_id))');
$pdo->exec('CREATE TABLE economy_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, mgw_user_id TEXT NOT NULL, delta INTEGER NOT NULL, reason TEXT NOT NULL, ref_type TEXT NOT NULL, ref_id TEXT NOT NULL, metadata_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(mgw_user_id, ref_type, ref_id))');

$assertSame = static function ($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertTrue = static function (bool $value, string $label): void {
    if (!$value) {
        throw new RuntimeException($label);
    }
};

$store = new CosmeticStoreService($pdo);
$reflection = new ReflectionClass($store);
$inventoryProperty = $reflection->getProperty('inventory');
$inventoryProperty->setAccessible(true);
$inventory = $inventoryProperty->getValue($store);

$mgwId = 'mvp19-6-checkers-user';
$pdo->prepare('INSERT INTO user_wallets (mgw_user_id, balance) VALUES (?, ?)')->execute([$mgwId, 100000]);

$catalog = $store->catalog();
$offers = [];
foreach ($catalog as $offer) {
    $offers[(string)($offer['offer_id'] ?? '')] = $offer;
}

$expectedOffers = [
    'checkers-board-wood' => 5000,
    'checkers-board-dark' => 7500,
    'checkers-board-marble' => 10000,
    'checkers-board-neon' => 15000,
    'checkers-pieces-wood' => 2500,
    'checkers-pieces-marble' => 5000,
    'checkers-pieces-metal' => 7500,
    'checkers-pieces-neon' => 10000,
    'checkers-effect-move' => 2500,
    'checkers-effect-capture' => 5000,
    'checkers-effect-promotion' => 7500,
    'checkers-bundle-complete' => 34000,
];
foreach ($expectedOffers as $offerId => $price) {
    $assertTrue(isset($offers[$offerId]), 'Missing Checkers offer ' . $offerId);
    $assertSame($price, (int)($offers[$offerId]['price_coins'] ?? 0), 'Canonical Checkers price mismatch for ' . $offerId);
}

$expectedBoardItems = [
    'checkers-board-wood' => 'game-checkers-board-wood',
    'checkers-board-dark' => 'game-checkers-board-dark',
    'checkers-board-marble' => 'game-checkers-board-marble',
    'checkers-board-neon' => 'game-checkers-board-neon',
];
foreach ($expectedBoardItems as $offerId => $itemId) {
    $assertSame([$itemId], array_values($offers[$offerId]['item_ids'] ?? []), 'Board offer must own exactly one canonical item for ' . $offerId);
}

$expectedPieceItems = [
    'checkers-pieces-wood' => 'game-checkers-pieces-wood',
    'checkers-pieces-marble' => 'game-checkers-pieces-marble',
    'checkers-pieces-metal' => 'game-checkers-pieces-metal',
    'checkers-pieces-neon' => 'game-checkers-pieces-neon',
];
foreach ($expectedPieceItems as $offerId => $itemId) {
    $assertSame([$itemId], array_values($offers[$offerId]['item_ids'] ?? []), 'Piece offer must own exactly one canonical item for ' . $offerId);
}

$expectedEffectItems = [
    'checkers-effect-move' => 'game-checkers-effect-move',
    'checkers-effect-capture' => 'game-checkers-effect-capture',
    'checkers-effect-promotion' => 'game-checkers-effect-promotion',
];
foreach ($expectedEffectItems as $offerId => $itemId) {
    $assertSame([$itemId], array_values($offers[$offerId]['item_ids'] ?? []), 'Effect offer must own exactly one canonical item for ' . $offerId);
}

$bundleItems = array_values($offers['checkers-bundle-complete']['item_ids'] ?? []);
$assertSame([
    'game-checkers-board-neon',
    'game-checkers-pieces-neon',
    'game-checkers-effect-move',
    'game-checkers-effect-capture',
    'game-checkers-effect-promotion',
], $bundleItems, '34k Checkers bundle must contain exactly the accepted premium board, pieces and three effects');

$boardQuote = $store->quote($mgwId, 'checkers-board-neon');
$assertSame(15000, (int)$boardQuote['price_coins'], 'Neon board quote must stay canonical');
$boardPurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-board-0001',
    'offer_id' => 'checkers-board-neon',
    'price_coins' => $boardQuote['price_coins'],
    'item_ids' => $boardQuote['item_ids'],
]);
$assertSame(false, $boardPurchase['auto_equipped'], 'Buying Checkers board must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Purchased board must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-board-neon');
$assertSame('game-checkers-board-neon', $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Checkers board must equip in the board slot');
$inventory->unequip($mgwId, 'game_checkers_theme');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_theme'] ?? null, 'Checkers board slot must support explicit unequip');

$pieceQuote = $store->quote($mgwId, 'checkers-pieces-neon');
$assertSame(10000, (int)$pieceQuote['price_coins'], 'Neon pieces quote must stay canonical');
$piecePurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-pieces-0002',
    'offer_id' => 'checkers-pieces-neon',
    'price_coins' => $pieceQuote['price_coins'],
    'item_ids' => $pieceQuote['item_ids'],
]);
$assertSame(false, $piecePurchase['auto_equipped'], 'Buying Checkers pieces must never auto-equip');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_elements'] ?? null, 'Purchased pieces must remain inactive before explicit equip');
$store->equipGameItem($mgwId, 'game-checkers-pieces-neon');
$assertSame('game-checkers-pieces-neon', $inventory->snapshot($mgwId)['equipped']['game_checkers_elements'] ?? null, 'Checkers pieces must equip in the elements slot');
$inventory->unequip($mgwId, 'game_checkers_elements');
$assertSame(null, $inventory->snapshot($mgwId)['equipped']['game_checkers_elements'] ?? null, 'Checkers elements slot must support explicit unequip');

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
$assertTrue(str_contains($liveWrapper, 'game_checkers_theme') && !str_contains($liveWrapper, 'game_checkers_elements'), 'Live board-theme wrapper must remain bounded to board projection plus presentation-only paid-effect geometry state');
$assertTrue(str_contains($liveWrapper, 'game_checkers_effect') && str_contains($liveWrapper, 'mgwCheckersPaidEffect'), 'Paid-effect slot may only gate visual selection geometry and must remain isolated from mechanics');
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
$assertTrue(str_contains($correctiveCss, 'width:min(100%,132px)') && str_contains($correctiveCss, 'max-width:220px') && str_contains($correctiveCss, 'transform:none'), 'Board cards and purchase sheets must show complete large 8x8 boards without decorative crop');
$assertTrue(str_contains($correctiveCss, '::before,') && str_contains($correctiveCss, '::after{display:none!important}'), 'Checkers media frames must remove the decorative top strip from the manual review');
$assertTrue(str_contains($correctiveCss, 'max-width:116px') && str_contains($correctiveCss, 'width:50px'), 'Accepted piece preview geometry must remain fully inside the card');
$assertTrue(str_contains($storeWrapper, 'startPassiveEffectPreview') && str_contains($storeWrapper, "preview.classList.add('is-previewing')"), 'Every rendered Checkers effect preview must explicitly start its passive one-shot presentation');
$assertTrue(str_contains($correctiveCss, 'mgw-checkers-store-v3-move-piece') && str_contains($correctiveCss, 'mgw-checkers-store-v3-capture-burst') && str_contains($correctiveCss, 'mgw-checkers-store-v3-promotion-crown'), 'Move, capture and promotion must each own a visible finite Store animation');
$assertTrue(str_contains($correctiveCss, '@media(prefers-reduced-motion:reduce)') && !str_contains($correctiveCss, 'infinite'), 'Store effects must respect reduced motion and never loop infinitely');
$assertTrue(str_contains($correctiveCss, '.checkers-fx-play{display:none!important}'), 'Store effects must not show a manual Profile-style play button');
$assertTrue(!str_contains($storeWrapper, 'installCheckersEffectPreviewIntents') && !str_contains($storeWrapper, 'playCheckersEffectPreview') && !str_contains($storeWrapper, 'setInterval') && !str_contains($storeWrapper, 'MutationObserver'), 'Store effect preview must stay passive and bounded without click playback, observers, or polling');
$assertTrue(str_contains($storeWrapper, 'injectCheckersBundleIntoGame') && str_contains($storeWrapper, 'upgradeCheckersBundleVisuals') && str_contains($storeWrapper, 'checkersBundleVisualContents') && str_contains($storeWrapper, 'bridgeInlineBundlePurchase'), 'Checkers 34k bundle must be visible inside Games -> Checkers and use the canonical purchase owner');
$assertTrue(str_contains($correctiveCss, 'mgw-checkers-bundle-complete') && str_contains($correctiveCss, 'mgw-checkers-bundle-board') && str_contains($correctiveCss, 'mgw-checkers-bundle-pieces') && str_contains($correctiveCss, 'mgw-checkers-bundle-effects'), 'Bundle art must visibly contain the neon board, neon pieces and all three effects');

$imports = is_array($manifest['imports'] ?? null) ? $manifest['imports'] : [];
$assertTrue(isset($imports['./assets/js/games/checkers/renderer.js?v=57']), 'Accepted Checkers renderer key must remain active in the import map');
$assertTrue(str_contains((string)$imports['./assets/js/games/checkers/renderer.js?v=57'], 'renderer-live-effects-v1.js'), 'Active Checkers route must remain wrapped only by the cosmetic live owner');
$assertTrue(str_contains((string)$imports['./assets/js/games/checkers/renderer.js?v=57'], 'base=accepted-v57'), 'Active Checkers wrapper chain must retain the accepted v57 mechanics owner');

$bundleQuote = $store->quote($mgwId, 'checkers-bundle-complete');
$assertSame(34000, (int)$bundleQuote['price_coins'], 'Checkers complete bundle quote must remain canonical');
$bundlePurchase = $store->fulfill($mgwId, 'mgw:' . $mgwId, 'legacy-checkers-user', [
    'request_token' => 'store:mvp19-6-bundle-0005',
    'offer_id' => 'checkers-bundle-complete',
    'price_coins' => $bundleQuote['price_coins'],
    'item_ids' => $bundleQuote['item_ids'],
]);
$assertSame(false, $bundlePurchase['auto_equipped'], 'Buying the complete Checkers bundle must never auto-equip');
$snapshot = $inventory->snapshot($mgwId);
foreach ($bundleItems as $itemId) {
    $assertTrue(in_array($itemId, array_column($snapshot['items'] ?? [], 'item_id'), true), 'Complete bundle must own item ' . $itemId);
}
$assertSame(null, $snapshot['equipped']['game_checkers_theme'] ?? null, 'Bundle board must remain inactive before explicit equip');
$assertSame(null, $snapshot['equipped']['game_checkers_elements'] ?? null, 'Bundle pieces must remain inactive before explicit equip');
$assertSame(null, $snapshot['equipped']['game_checkers_effect'] ?? null, 'Bundle effects must remain inactive before explicit equip');

echo "MVP-19.6 Checkers boards/pieces/effects catalog and frozen live owner contract passed.\n";
