<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Missing source: ' . $path);
    return $value;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$v110 = $read('app/v110.php');
$mainCss = $read('app/assets/css/main.css');
$tokens = $read('app/assets/css/base/tokens.css');
$skin = $read('app/assets/css/shield-king.css');
$preloader = $read('app/assets/css/components/preloader.css');
$cards = $read('app/assets/css/components/cards.css');
$gameCardCopy = $read('app/assets/js/games/game-card-copy.js');
$visuals = $read('app/assets/js/components/shield-king-visuals.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');
$phaseB = $read('app/assets/js/production-v110-acceptance-runtime.js');

$assert(str_contains($v110, "X-MGW-Design-System: shield-king-v1"), 'The accepted v110 route must expose the Shield King design identity.');
$assert(str_contains($v110, "./assets/css/main.css?v=124&sk=1"), 'The shared Shield King CSS bundle must have an immutable fresh URL.');
$assert(str_contains($v110, "./assets/js/main-v110.js?v=1137&ux=1&sk=1"), 'The accepted runtime graph must keep v1137 ownership while cache-busting visual dependencies.');

foreach ([
    '--sk-bg-app:#080B12',
    '--sk-bg-card:#17121F',
    '--sk-bg-card-secondary:#231942',
    '--sk-brand-primary:#6A4CFF',
    '--sk-brand-violet:#A65FF7',
    '--sk-brand-gold:#FFD45C',
    '--sk-brand-silver:#E6E8EF',
    '--sk-success:#48D6A5',
] as $token) {
    $assert(str_contains($tokens, $token), 'Missing Shield King semantic token: ' . $token);
}

$assert(str_contains($mainCss, "@import url('./shield-king.css?v=124&sk=1');"), 'The shared visual layer must load after existing screen/game CSS.');
$assert(str_contains($preloader, "mgw-mark.svg"), 'App startup must use the shared accepted MGW crown/shield mark.');
$assert(!str_contains($preloader, 'rgba(46,230,166,.75)'), 'App startup must not keep broad legacy green branding.');
$assert(str_contains($skin, 'Phase B presentation skin.'), 'Phase B styling must be explicitly visual-only.');
$assert(str_contains($skin, '.mgw-phase-b-countdown[data-stage="prepare"]:before'), 'Shield King must skin the accepted prepare state.');
$assert(str_contains($skin, "mgw-mark.svg"), 'Phase B preparation must use the shared MGW mark.');
$assert(str_contains($skin, 'var(--sk-success)'), 'Semantic green must remain available for authoritative ready/success only.');

$assert(str_contains($shell, "initShieldKingVisuals();"), 'The shared shell must initialize visual-only icon binding.');
$assert(str_contains($visuals, "notificationsOpen"), 'Notification utility icon must use the accepted compact icon system.');
$assert(str_contains($visuals, "moreMenuOpen"), 'More-menu utility icon must use the accepted compact icon system.');
$assert(str_contains($visuals, "game-rules-button"), 'Rules controls must use the accepted compact icon system.');
$assert(str_contains($visuals, "balance-card .balance-label"), 'Balance labels must use the accepted economy icon system.');

foreach ([
    'tictactoe' => 'game-tic-tac-toe.svg',
    'four_in_a_row' => 'game-four-in-a-row.svg',
    'battleship' => 'game-battleship.svg',
    'checkers' => 'game-checkers.svg',
    'reversi' => 'game-reversi.svg',
    'chess' => 'game-chess.svg',
    'go' => 'game-go.svg',
    'domino' => 'game-domino.svg',
] as $game => $asset) {
    $assert(str_contains($gameCardCopy, $game) && str_contains($gameCardCopy, $asset), 'Missing accepted game-card icon mapping for ' . $game);
    $assert(is_file($root . '/app/assets/icons/shield-king/games/' . $asset), 'Missing accepted game icon file: ' . $asset);
}

$assert(str_contains($cards, 'width:56px;height:56px'), 'Existing shared game icon slot geometry must remain 56x56.');
$assert(str_contains($cards, 'object-fit:contain'), 'All eight game assets must share one contained rendering rule.');

foreach ([
    'app/assets/css/shield-king.css',
    'app/assets/js/components/shield-king-visuals.js',
    'app/assets/js/games/game-card-copy.js',
    'app/assets/icons/shield-king/mgw-mark.svg',
    'app/assets/icons/shield-king/games/game-tic-tac-toe.svg',
    'app/assets/icons/shield-king/games/game-domino.svg',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover Shield King asset: ' . $path);
}

$assert(str_contains($phaseB, 'const LAUNCH_COUNTDOWN_STEP_MS = 600;'), 'Phase B countdown timing owner must remain unchanged.');
$assert(str_contains($phaseB, 'const LAUNCH_READY_HOLD_MS = 450;'), 'Phase B ready-hold timing owner must remain unchanged.');
$assert(!str_contains($skin, 'LAUNCH_COUNTDOWN_STEP_MS') && !str_contains($skin, 'setInterval(') && !str_contains($skin, 'fetch('), 'The Shield King visual layer must own no lifecycle/timing/network logic.');

foreach ([
    'app/assets/css/games/tictactoe/rules.css',
    'app/assets/css/games/four-in-a-row/game.css',
    'app/assets/css/games/battleship/game.css',
    'app/assets/css/games/checkers/game.css',
    'app/assets/css/games/reversi/game.css',
    'app/assets/css/games/chess/game.css',
    'app/assets/css/games/go/game.css',
    'app/assets/css/games/domino/game.css',
] as $gameCss) {
    $assert(str_contains($mainCss, $gameCss), 'Existing gameplay CSS owner must remain wired unchanged: ' . $gameCss);
}

fwrite(STDOUT, "ShieldKingSharedUiIntegrationContractTest: {$assertions} assertions passed\n");
