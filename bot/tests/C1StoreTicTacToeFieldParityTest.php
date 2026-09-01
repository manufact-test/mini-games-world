<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cosmetics = file_get_contents($repoRoot . '/app/assets/css/games/tictactoe/cosmetics.css');
$storeCss = file_get_contents($repoRoot . '/app/assets/css/screens/store-v2.css');
$mainCss = file_get_contents($repoRoot . '/app/assets/css/main.css');
$renderer = file_get_contents($repoRoot . '/app/assets/js/games/tictactoe/renderer.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';

$assert(is_string($cosmetics) && str_contains($cosmetics, 'single visual owner for field-theme appearance in both gameplay and Store'), 'Tic Tac Toe cosmetics CSS must declare the shared field visual owner.');
$assert(is_string($renderer) && str_contains($renderer, "container.dataset.tttTheme = variantFor(viewer, 'game_tictactoe_theme', 'field');"), 'Runtime must keep projecting the equipped field theme through the canonical renderer.');

foreach (['classic','dark','glass','neon'] as $variant) {
    $runtimeSelector = "#gameBoard[data-game-type=\"tictactoe\"][data-ttt-theme=\"{$variant}\"]";
    $storeSelector = ".store-v2-game-preview[data-cosmetic-layer=\"theme\"][data-cosmetic-variant=\"{$variant}\"] .store-v2-mini-board";
    $assert(str_contains((string)$cosmetics, $runtimeSelector), "Shared cosmetics owner must include runtime {$variant} field selector.");
    $assert(str_contains((string)$cosmetics, $storeSelector), "Shared cosmetics owner must include Store {$variant} field selector.");
    $assert(!str_contains((string)$storeCss, ".store-v2-game-preview[data-cosmetic-variant=\"{$variant}\"]{"), "Store CSS must not own independent {$variant} theme artwork.");
}

$assert(str_contains((string)$storeCss, '.store-v2-game-preview[data-cosmetic-layer="theme"]{overflow:visible;border-color:transparent;background:transparent;box-shadow:none}'), 'Store theme preview wrapper must stay neutral and expose only the real field surface.');
$assert(str_contains((string)$storeCss, '.store-v2-mini-board{') && str_contains((string)$storeCss, 'background:transparent;box-shadow:none}'), 'Store mini-board geometry must not recreate theme appearance.');
$assert(str_contains((string)$mainCss, "./games/tictactoe/cosmetics.css?v=2&mvp19_4=pilot&c1=shared-field"), 'Active main CSS must cache-bust the shared Tic Tac Toe field owner.');
$assert(str_contains((string)$mainCss, "./screens/store-v2.css?v=6&mvp19=store-v2&corrective=2&mvp19_4=game-cosmetics-polish-v3&c1=field-parity"), 'Active main CSS must cache-bust the Store geometry cleanup.');
$assert(str_contains((string)($manifest['assets']['main_css'] ?? ''), '&c1=ttt-field-parity'), 'Active version manifest must publish the C1 Store field parity CSS identity.');

fwrite(STDOUT, "C1StoreTicTacToeFieldParityTest: {$assertions} assertions passed\n");
