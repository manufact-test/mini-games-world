<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use MiniGamesWorld\Catalog\CosmeticStoreService;
use MiniGamesWorld\Database\MigrationRunner;

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
$runner = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
$runner->migrate();

$service = new CosmeticStoreService($pdo);
$catalog = $service->catalog();
$products = $catalog['products'] ?? [];
$byId = [];
foreach ($products as $product) {
    $byId[(string)($product['id'] ?? '')] = $product;
}

$required = [
    'game-checkers-board-classic' => 4500,
    'game-checkers-board-wood' => 7500,
    'game-checkers-board-marble' => 12000,
    'game-checkers-board-neon' => 18000,
    'game-checkers-pieces-classic' => 4500,
    'game-checkers-pieces-wood' => 7500,
    'game-checkers-pieces-marble' => 12000,
    'game-checkers-pieces-neon' => 18000,
    'game-checkers-effect-move' => 9000,
    'game-checkers-effect-capture' => 12000,
    'game-checkers-effect-promotion' => 15000,
    'game-checkers-bundle-complete' => 34000,
];
foreach ($required as $id => $price) {
    $assertTrue(isset($byId[$id]), 'Missing Checkers product ' . $id);
    $assertTrue((int)($byId[$id]['price'] ?? 0) === $price, 'Unexpected Checkers price for ' . $id);
}

$boardSlots = ['game_checkers_theme','game_checkers_elements','game_checkers_effect'];
foreach ($boardSlots as $slot) {
    $assertTrue(in_array($slot, $catalog['slots'] ?? [], true), 'Missing Checkers slot ' . $slot);
}

$assertTrue(($byId['game-checkers-board-classic']['slot'] ?? null) === 'game_checkers_theme', 'Board must use game_checkers_theme');
$assertTrue(($byId['game-checkers-pieces-neon']['slot'] ?? null) === 'game_checkers_elements', 'Pieces must use game_checkers_elements');
$assertTrue(($byId['game-checkers-effect-promotion']['slot'] ?? null) === 'game_checkers_effect', 'Effects must use game_checkers_effect');

$storeScreen = file_get_contents(dirname(__DIR__, 2) . '/app/assets/js/screens/store-screen.js');
$storeWrapper = file_get_contents(dirname(__DIR__, 2) . '/app/assets/js/screens/store-screen-checkers-wrapper.js');
$correctiveCss = file_get_contents(dirname(__DIR__, 2) . '/app/assets/css/games/checkers/store-complete-v1.css');
$manifest = require dirname(__DIR__, 2) . '/app/runtime/client/version-manifest.php';

$assertTrue(is_string($storeScreen) && $storeScreen !== '', 'Store screen source must be readable');
$assertTrue(is_string($storeWrapper) && $storeWrapper !== '', 'Store Checkers wrapper source must be readable');
$assertTrue(is_string($correctiveCss) && $correctiveCss !== '', 'Checkers Store corrective CSS must be readable');

foreach (['board','pieces','effect'] as $kind) {
    $assertTrue(str_contains($storeScreen, "checkers-{$kind}"), 'Store screen must render Checkers ' . $kind . ' previews');
}
foreach (['classic','wood','marble','neon'] as $variant) {
    $assertTrue(str_contains($storeScreen, 'checkers-preview-' . $variant) || str_contains($storeScreen, 'checkers-board-' . $variant) || str_contains($storeScreen, 'checkers-pieces-' . $variant), 'Store previews must include Checkers ' . $variant);
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
$assertTrue(str_contains($storeScreen, "bundleGameType === 'checkers'") && str_contains($storeScreen, 'store-v2-confirm-game'), 'Purchase confirmation must preserve Checkers-specific previews');

$storeTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=34'] ?? '');
$storeBaseTarget = (string)($manifest['imports']['./assets/js/screens/store-screen.js?v=45&intent_base=1&mvp19_5=chess-catalog'] ?? '');
$checkersTarget = (string)($manifest['imports']['./assets/js/games/checkers/renderer.js?v=57'] ?? '');
$assertTrue(str_contains($storeTarget, 'store-screen-checkers-wrapper.js?v=4') && str_contains($storeTarget, 'mvp19_6=visual-corrective-v3'), 'Active Store graph must select the second Checkers manual-review corrective');
$assertTrue(str_contains($storeBaseTarget, 'mvp19_6=full-checkers-store'), 'Active import graph must preserve the native Store owner under the corrective wrapper');
$assertTrue(
    str_contains($checkersTarget, 'renderer-single-flight-v1.js?v=2')
    && str_contains($checkersTarget, 'mvp19_6=single-flight-dom-v1')
    && str_contains($checkersTarget, 'real_flight=css-cascade-v1')
    && str_contains($checkersTarget, 'renderer-live-effects-v1.js?v=14')
    && str_contains($checkersTarget, 'renderer-board-themes.js?v=12'),
    'Active Checkers graph must preserve single-flight real-piece movement over the accepted board-theme owner'
);

fwrite(STDOUT, "PASS: MVP-19.6 complete Checkers Store ({$assertions} assertions)\n");
