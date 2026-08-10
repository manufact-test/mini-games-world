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
$v1Skin = $read('app/assets/css/shield-king.css');
$v2Skin = $read('app/assets/css/shield-king-v2.css');
$preloader = $read('app/assets/css/components/preloader.css');
$cards = $read('app/assets/css/components/cards.css');
$gameCardCopy = $read('app/assets/js/games/game-card-copy.js');
$visuals = $read('app/assets/js/components/shield-king-visuals.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');
$phaseB = $read('app/assets/js/production-v110-acceptance-runtime.js');
$assetReader = $read('app/assets/shield-king-icon.php');
$bundlePath = $root . '/app/assets/icons/shield-king/accepted/MGW_SHIELD_KING_ACCEPTED_METALLIC_ICON_EXPORT_V1.zip';

$assert(str_contains($v110, 'shield-king-v2-light-metallic'), 'Accepted v110 route must expose the V2 light/metallic design identity.');
$assert(str_contains($v110, './assets/css/main.css?v=125&sk=2'), 'V2 CSS graph must have a fresh immutable URL.');
$assert(str_contains($v110, './assets/js/main-v110.js?v=1137&ux=1&sk=2'), 'Runtime identity must remain v1137 while only visual dependencies are cache-busted.');

foreach ([
    '--sk-bg-app:#080B12',
    '--sk-brand-primary:#735BFF',
    '--sk-brand-violet:#B274FF',
    '--sk-brand-gold:#FFD45C',
    '--sk-brand-silver:#E9ECF4',
    '--sk-silver-bright:#F7F8FC',
    '--sk-text-secondary:#DDD9E7',
    '--sk-text-muted:#AEA7B8',
    '--sk-success:#48D6A5',
] as $token) {
    $assert(str_contains($tokens, $token), 'Missing V2 visibility token: ' . $token);
}

$assert(str_contains($mainCss, "@import url('./shield-king.css?v=124&sk=1');"), 'V1 shared skin must remain available before V2.');
$assert(str_contains($mainCss, "@import url('./shield-king-v2.css?v=125&sk=2');"), 'V2 visibility layer must load last.');
$assert(str_contains($v2Skin, ':focus-visible'), 'V2 must provide a visible keyboard/focus state.');
$assert(str_contains($v2Skin, 'Search: stronger silver structure'), 'Search state must receive the sunlight/readability pass.');
$assert(str_contains($v2Skin, 'Phase B V2:'), 'Phase B V2 styling must be explicitly presentation-only.');
$assert(str_contains($v2Skin, 'shieldKingPhaseAssembleMark'), 'Phase B prepare presentation must use the new assembly motion.');
$assert(!str_contains($v2Skin, 'setInterval(') && !str_contains($v2Skin, 'fetch('), 'V2 CSS must own no timing/network lifecycle.');
$assert(!str_contains($v2Skin, '#gameBoard') && !str_contains($v2Skin, '.board-cell'), 'V2 must not style gameplay board cells/pieces.');

$assert(str_contains($preloader, 'shieldKingPlateLeft'), 'Startup loader V2 must assemble a left shield plate.');
$assert(str_contains($preloader, 'shieldKingPlateRight'), 'Startup loader V2 must assemble a right shield plate.');
$assert(str_contains($preloader, 'shieldKingCrownDrop'), 'Startup loader V2 must assemble the crown.');
$assert(str_contains($preloader, 'mgw-mark.svg'), 'Startup loader must keep the shared MGW crown/shield mark.');

$assert(is_file($bundlePath), 'Exact accepted metallic icon bundle must be present in the active integration graph.');
$assert(hash_file('sha256', $bundlePath) === '83e4bb23745fa3f0453d8bf22a7298030bec963942577c0bc20f1d1964d4df60', 'Accepted metallic bundle SHA-256 must remain frozen.');
$assert(str_contains($assetReader, "MGW_SK_ICON_EXPORT_SHA = 'bcb098b72333e5efa3247de82506550091710757'"), 'Asset reader must pin the exact accepted export SHA.');
$assert(str_contains($assetReader, "header('Cache-Control: public, max-age=31536000, immutable')"), 'Accepted icons must be served with immutable caching.');
$assert(str_contains($assetReader, 'gzinflate($compressed)'), 'Asset reader must support the frozen bundle deflate method without requiring a mutable extraction step.');

preg_match_all("/'((?:games|ui)\\/[^']+\\.webp)'/", $assetReader, $assetMatches);
$assetNames = array_values(array_unique($assetMatches[1] ?? []));
$assert(count($assetNames) === 44, 'Asset reader must expose exactly the 44 recovered accepted metallic assets.');
$assert(!in_array('ui/actions/surrender.webp', $assetNames, true), 'Missing surrender art must not be silently fabricated.');

foreach ([
    'tictactoe' => 'games/tic-tac-toe.webp',
    'four_in_a_row' => 'games/four-in-a-row.webp',
    'battleship' => 'games/battleship.webp',
    'checkers' => 'games/checkers.webp',
    'reversi' => 'games/reversi.webp',
    'chess' => 'games/chess.webp',
    'go' => 'games/go.webp',
    'domino' => 'games/domino.webp',
] as $game => $asset) {
    $assert(str_contains($gameCardCopy, $game) && str_contains($gameCardCopy, $asset), 'Missing rich accepted game-card mapping for ' . $game);
}
$assert(!str_contains($gameCardCopy, 'game-tic-tac-toe.svg'), 'Simplified geometry-reference game SVGs must not be active card art.');
$assert(str_contains($cards, 'width:56px;height:56px'), 'Existing shared game icon slot geometry must remain 56x56.');

foreach ([
    "notificationsOpen'), 'ui/navigation/notifications.webp'",
    "moreMenuOpen'), 'ui/actions/more.webp'",
    "'ui/actions/rules.webp'",
    "'ui/economy/coins.webp'",
    "'ui/economy/premium-currency.webp'",
    "'ui/actions/close.webp'",
    "'ui/actions/invite.webp'",
] as $binding) {
    $assert(str_contains($visuals, $binding), 'Missing accepted metallic UI binding: ' . $binding);
}
$assert(str_contains($visuals, 'MutationObserver'), 'Dynamic sheets must receive the same metallic icon family when they render.');

foreach ([
    'app/assets/design-backups/shield-king-v1/tokens-v1.css',
    'app/assets/design-backups/shield-king-v1/startup-preloader-v1.css',
    'app/assets/design-backups/shield-king-v1/phase-b-presentation-v1.css',
    'app/assets/design-backups/shield-king-v1/README.md',
] as $fallback) {
    $assert(is_file($root . '/' . $fallback), 'Missing inert V1 fallback asset: ' . $fallback);
}

foreach ([
    'app/assets/css/shield-king-v2.css',
    'app/assets/shield-king-icon.php',
    'app/assets/icons/shield-king/accepted/MGW_SHIELD_KING_ACCEPTED_METALLIC_ICON_EXPORT_V1.zip',
    'app/assets/js/components/shield-king-visuals.js',
    'app/assets/js/games/game-card-copy.js',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover V2 asset: ' . $path);
}

$assert(str_contains($phaseB, 'const LAUNCH_COUNTDOWN_STEP_MS = 600;'), 'Phase B countdown timing owner must remain unchanged.');
$assert(str_contains($phaseB, 'const LAUNCH_READY_HOLD_MS = 450;'), 'Phase B ready-hold timing owner must remain unchanged.');
$assert(str_contains($shell, "game-invites-v110.js?v=1137&ux=1"), 'Invite runtime identity must remain unchanged.');
$assert(str_contains($shell, "production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a"), 'Readonly game sync runtime identity must remain unchanged.');

foreach ([
    './games/tictactoe/rules.css',
    './games/four-in-a-row/game.css',
    './games/battleship/game.css',
    './games/checkers/game.css',
    './games/reversi/game.css',
    './games/chess/game.css',
    './games/go/game.css',
    './games/domino/game.css',
] as $gameCssImport) {
    $assert(str_contains($mainCss, $gameCssImport), 'Existing gameplay CSS owner must remain wired unchanged: ' . $gameCssImport);
}

fwrite(STDOUT, "ShieldKingSharedUiIntegrationContractTest V2: {$assertions} assertions passed\n");
