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

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$v110 = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$gameCards = $read('app/assets/js/games/game-card-copy.js');
$visuals = $read('app/assets/js/components/shield-king-visuals.js');
$mainCss = $read('app/assets/css/main.css');
$v4 = $read('app/assets/css/shield-king-icons-v4.css');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';"), 'Normal Telegram /start must still own the v110 route.');
$assert(str_contains($v110, 'X-MGW-Icon-Render: accepted-v4-authoritative-v110'), 'Real Telegram route must publish the V4 icon render identity.');
$assert(str_contains($v110, './assets/css/main.css?v=126&sk=3&icons=c1efd5af&render=4'), 'Real Telegram route must refresh the CSS graph.');
$assert(str_contains($v110, './assets/js/main-v110.js?v=1138&ux=1&sk=3&icons=c1efd5af&render=4'), 'Real Telegram route must refresh the main module graph.');
$assert(str_contains($main, "main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=4"), 'Main v110 entry must refresh the authoritative shell directly.');

$assert(str_contains($shell, "shield-king-visuals.js?v=126&sk=4&icons=c1efd5af"), 'Authoritative v110 shell must import accepted UI icon bindings under a fresh direct URL.');
$assert(str_contains($shell, "game-card-copy.js?v=82&sk=4&icons=c1efd5af"), 'Authoritative v110 shell must import accepted game-card art under a fresh direct URL.');
$assert(substr_count($shell, 'initShieldKingVisuals();') === 1, 'Accepted UI icon binding must have exactly one v110 initializer.');
$assert(substr_count($shell, 'initGameCardCopy();') === 1, 'Accepted game-card binding must have exactly one v110 initializer.');
$assert(strpos($shell, 'initShieldKingVisuals();') < strpos($shell, 'initGameCardCopy();'), 'Shared UI binding must initialize before game-card art without changing runtime ownership.');

foreach ([
    'games/tic-tac-toe.webp', 'games/four-in-a-row.webp', 'games/battleship.webp',
    'games/checkers.webp', 'games/reversi.webp', 'games/chess.webp', 'games/go.webp', 'games/domino.webp',
] as $asset) {
    $assert(str_contains($gameCards, $asset), 'Missing accepted rich game art mapping: ' . $asset);
}
$assert(str_contains($gameCards, "image.className = 'shield-king-game-art'"), 'Accepted game art must render as an image, never an emoji fallback when mapped.');

foreach ([
    "notificationsOpen'), 'ui/navigation/notifications.webp'",
    "moreMenuOpen'), 'ui/actions/more.webp'",
    "'ui/actions/rules.webp'",
    "'ui/actions/invite.webp'",
    "'ui/economy/coins.webp'",
    "'ui/economy/premium-currency.webp'",
] as $binding) {
    $assert(str_contains($visuals, $binding), 'Missing accepted UI icon binding: ' . $binding);
}

$assert(str_contains($mainCss, "@import url('./shield-king-icons-v4.css?v=127&icons=c1efd5af&render=4');"), 'V4 optical correction must load last.');
$assert(str_contains($v4, '.shield-king-label-icon'), 'Economy icons must have a dedicated enlarged owner.');
$assert(str_contains($v4, '.shield-king-button-icon'), 'Invite icon must have a dedicated enlarged owner.');
$assert(str_contains($v4, 'ui/actions/rules.webp'), 'Rules art must receive optical centering.');
$assert(str_contains($v4, 'ui/actions/more.webp'), 'More art must receive desktop optical centering.');
$assert(str_contains($v4, '.sheet:has(> .menu-list)'), 'Menu sheet must adapt to short viewport heights.');
$assert(str_contains($v4, 'overflow-y:auto'), 'Menu list must remain usable when all rows cannot fit simultaneously.');
$assert(!str_contains($v4, '.preloader') && !str_contains($v4, 'mgw-phase-b'), 'Icon V4 must not reopen accepted loaders.');
$assert(!str_contains($v4, '#gameBoard') && !str_contains($v4, '.board-cell'), 'Icon V4 must not style gameplay boards.');

$assert(str_contains($fingerprint, 'app/assets/css/shield-king-icons-v4.css'), 'Exact Hostinger fingerprint must cover V4 CSS.');
$assert(str_contains($fingerprint, 'app/assets/js/main-v110-handoff-shell.js'), 'Exact Hostinger fingerprint must cover the authoritative icon initializer shell.');
$assert(str_contains($fingerprint, 'app/assets/js/games/game-card-copy.js'), 'Exact Hostinger fingerprint must cover accepted game art binding.');
$assert(str_contains($fingerprint, 'app/assets/js/components/shield-king-visuals.js'), 'Exact Hostinger fingerprint must cover accepted UI art binding.');

fwrite(STDOUT, "ShieldKingAuthoritativeIconRenderV4ContractTest: {$assertions} assertions passed\n");
