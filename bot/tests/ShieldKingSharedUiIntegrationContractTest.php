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
$iconVisibility = $read('app/assets/css/shield-king-icons-v3.css');
$preloader = $read('app/assets/css/components/preloader.css');
$cards = $read('app/assets/css/components/cards.css');
$gameCardCopy = $read('app/assets/js/games/game-card-copy.js');
$visuals = $read('app/assets/js/components/shield-king-visuals.js');
$mainJs = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');
$phaseB = $read('app/assets/js/production-v110-acceptance-runtime.js');
$assetReader = $read('app/assets/shield-king-icon.php');
$bundlePath = $root . '/app/assets/icons/shield-king/accepted/MGW_SHIELD_KING_ACCEPTED_METALLIC_ICON_EXPORT_V1.zip';

$assert(str_contains($v110, 'shield-king-v2-light-metallic'), 'Accepted v110 route must keep the V2 light/metallic design identity.');
$assert(str_contains($v110, './assets/css/main.css?v=126&sk=3&icons=c1efd5af'), 'Icon pass CSS graph must have a fresh cache identity.');
$assert(str_contains($v110, './assets/js/main-v110.js?v=1138&ux=1&sk=3&icons=c1efd5af'), 'Runtime identity must remain v1137 while the visual entry URL is cache-busted.');
$assert(str_contains($v110, "X-MGW-Icon-Pack: c1efd5afbf0125a090b1755fed2b40cb2cc6f2e1"), 'Active route must publish the exact repaired accepted icon export identity.');
$assert(str_contains($v110, 'shield-king-visuals.js?v=126&sk=3&icons=c1efd5af'), 'Import map must force a fresh accepted UI icon module URL.');
$assert(str_contains($v110, 'game-card-copy.js?v=82&sk=3&icons=c1efd5af'), 'Import map must force a fresh accepted game-card art module URL.');
$assert(str_contains($mainJs, "main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af"), 'Fresh main entry must also bust the shared shell cache without changing runtime ownership.');

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
$assert(str_contains($mainCss, "@import url('./shield-king-v2.css?v=125&sk=2');"), 'Accepted V2 light layer must remain wired unchanged.');
$assert(str_contains($mainCss, "@import url('./shield-king-icons-v3.css?v=126&icons=c1efd5af');"), 'Icon visibility layer must load last with the repaired export identity.');
$assert(str_contains($v2Skin, ':focus-visible'), 'V2 must provide a visible keyboard/focus state.');
$assert(str_contains($v2Skin, 'Search: stronger silver structure'), 'Search state must retain the accepted sunlight/readability pass.');
$assert(str_contains($v2Skin, 'Phase B V2:'), 'Phase B V2 styling must remain explicitly presentation-only.');
$assert(str_contains($v2Skin, 'shieldKingPhaseAssembleMark'), 'Phase B prepare presentation must retain the accepted assembly motion.');
$assert(str_contains($v2Skin, "background:url('../icons/shield-king/mgw-mark.svg')"), 'Phase B V2 MGW mark path must remain intact.');
$assert(!str_contains($v2Skin, 'setInterval(') && !str_contains($v2Skin, 'fetch('), 'V2 CSS must own no timing/network lifecycle.');
$assert(!str_contains($v2Skin, '#gameBoard') && !str_contains($v2Skin, '.board-cell'), 'V2 must not style gameplay board cells/pieces.');

$assert(str_contains($preloader, 'shieldKingPlateLeft'), 'Accepted startup loader must keep its left shield plate.');
$assert(str_contains($preloader, 'shieldKingPlateRight'), 'Accepted startup loader must keep its right shield plate.');
$assert(str_contains($preloader, 'shieldKingCrownDrop'), 'Accepted startup loader must keep its crown assembly.');
$assert(str_contains($preloader, 'mgw-mark.svg'), 'Accepted startup loader must keep the shared MGW crown/shield mark.');

$assert(str_contains($iconVisibility, 'Shield King icon visibility pass'), 'Focused icon visibility layer must identify its presentation-only scope.');
$assert(str_contains($iconVisibility, '.shield-king-game-art'), 'Accepted game-card raster art must receive an explicit optical-size owner.');
$assert(str_contains($iconVisibility, 'width:82px') && str_contains($iconVisibility, 'height:92px'), 'Game-card accepted art showcase slot must be visibly larger than the old compact 56px slot.');
$assert(str_contains($iconVisibility, '#notificationsOpen .shield-king-metal-icon'), 'Notification bell must have a dedicated optical-size owner.');
$assert(str_contains($iconVisibility, '#moreMenuOpen .shield-king-metal-icon'), 'More icon must have a dedicated optical-size/centering owner.');
$assert(str_contains($iconVisibility, '.game-card .game-rules-button .shield-king-metal-icon'), 'Game-card rules art must be explicitly enlarged.');
$assert(str_contains($iconVisibility, '.menu-item .shield-king-menu-icon'), 'Menu accepted art must be explicitly enlarged and brightened.');
$assert(!str_contains($iconVisibility, '.preloader') && !str_contains($iconVisibility, 'mgw-phase-b'), 'Focused icon pass must not reopen accepted loaders.');
$assert(!str_contains($iconVisibility, '#gameBoard') && !str_contains($iconVisibility, '.board-cell'), 'Focused icon pass must not style gameplay boards.');

$assert(is_file($bundlePath), 'Accepted metallic icon bundle must be present in the active integration graph.');
$assert(str_contains($assetReader, "MGW_SK_ICON_EXPORT_SHA = 'c1efd5afbf0125a090b1755fed2b40cb2cc6f2e1'"), 'Asset reader must pin the exact repaired accepted export SHA.');
$assert(str_contains($assetReader, "header('Cache-Control: public, max-age=31536000, immutable')"), 'Accepted icons may be immutable only under the repaired export cache identity.');
$assert(str_contains($assetReader, 'gzinflate($compressed)'), 'Asset reader must support the frozen bundle deflate method without requiring a mutable extraction step.');
$assert(str_contains($gameCardCopy, "ICON_ENDPOINT = './assets/shield-king-icon.php?v=c1efd5af&asset='"), 'Game-card art must use the repaired accepted export URL.');
$assert(str_contains($visuals, "ICON_ENDPOINT = './assets/shield-king-icon.php?v=c1efd5af&asset='"), 'Shared UI icons must use the repaired accepted export URL.');

/* Verify accepted ART bytes, not ZIP container metadata. */
$_GET['asset'] = 'ui/actions/more.webp';
$_SERVER['REQUEST_METHOD'] = 'HEAD';
ob_start();
require $root . '/app/assets/shield-king-icon.php';
ob_end_clean();

$manifest = json_decode(readZipMember($bundlePath, 'MANIFEST.json'), true, 512, JSON_THROW_ON_ERROR);
$manifestFiles = $manifest['files'] ?? [];
$assert(count($manifestFiles) === 44, 'Frozen accepted manifest must contain exactly 44 production assets.');
$assert(($manifest['base_frozen_design_sha'] ?? '') === '7918d249112bcbadde8c59d3015a16c39dc3d2e1', 'Accepted manifest must point to the frozen Shield King V1 design SHA.');
$assert(($manifest['production_format'] ?? '') === 'WebP lossless RGBA', 'Accepted manifest must retain lossless RGBA production format.');

$manifestNames = [];
foreach ($manifestFiles as $file) {
    $path = (string)($file['path'] ?? '');
    $expectedHash = (string)($file['sha256'] ?? '');
    $expectedBytes = (int)($file['bytes'] ?? -1);
    $data = readZipMember($bundlePath, $path);
    $assert(hash('sha256', $data) === $expectedHash, 'Accepted asset SHA-256 mismatch: ' . $path);
    $assert(strlen($data) === $expectedBytes, 'Accepted asset byte-size mismatch: ' . $path);
    $manifestNames[] = $path;
}
$manifestNames = array_values(array_unique($manifestNames));
sort($manifestNames);

preg_match_all("/'((?:games|ui)\\/[^']+\\.webp)'/", $assetReader, $assetMatches);
$assetNames = array_values(array_unique($assetMatches[1] ?? []));
sort($assetNames);
$assert(count($assetNames) === 44, 'Asset reader must expose exactly the 44 recovered accepted metallic assets.');
$assert($assetNames === $manifestNames, 'Asset reader whitelist must exactly match the frozen accepted manifest.');
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
$assert(str_contains($gameCardCopy, "image.className = 'shield-king-game-art'"), 'Game-card images must use the dedicated accepted-art visibility class.');
$assert(!str_contains($gameCardCopy, 'game-tic-tac-toe.svg'), 'Simplified geometry-reference game SVGs must not be active card art.');
$assert(str_contains($cards, 'width:56px;height:56px'), 'Historical base geometry remains intact underneath the focused final override.');

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
    'app/assets/css/shield-king-icons-v3.css',
    'app/assets/shield-king-icon.php',
    'app/assets/icons/shield-king/accepted/MGW_SHIELD_KING_ACCEPTED_METALLIC_ICON_EXPORT_V1.zip',
    'app/assets/js/components/shield-king-visuals.js',
    'app/assets/js/games/game-card-copy.js',
] as $path) {
    $assert(str_contains($fingerprint, $path), 'Exact staging fingerprint must cover icon pass asset: ' . $path);
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

fwrite(STDOUT, "ShieldKingSharedUiIntegrationContractTest icon visibility: {$assertions} assertions passed\n");
