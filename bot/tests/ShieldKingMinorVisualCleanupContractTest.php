<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v5.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V7 cleanup source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v5.css?v=128&icons=c1efd5af&render=7');"),
    'Main CSS must load the V7 minor visual cleanup after the earlier icon passes.');
$assert(str_contains($entry, './assets/css/main.css?v=127&sk=3&icons=c1efd5af&render=7'),
    'The real v110 entry must cache-bust the V7 CSS graph.');
$assert(str_contains($entry, "header('X-MGW-Icon-Render: accepted-v7-clean-optics');"),
    'The v110 route must expose the exact V7 visual identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v5.css'),
    'Hostinger exact fingerprint must include the V7 CSS file.');

$assert(str_contains($css, '.account-menu-icon{')
    && str_contains($css, 'background:transparent !important;')
    && str_contains($css, 'border:0 !important;')
    && str_contains($css, 'box-shadow:none !important;'),
    'My applications icon must no longer sit inside a second tile.');

$assert(str_contains($css, '#moreMenuOpen > img[data-sk-asset="ui/actions/more.webp"]')
    && str_contains($css, '#notificationsOpen > img[data-sk-asset="ui/navigation/notifications.webp"]')
    && substr_count($css, 'transform:none !important;') >= 4,
    'Header bell and more glyphs must return to geometric centering on touch and fine pointers.');

$assert(str_contains($css, 'mix-blend-mode:screen;')
    && !str_contains($css, 'drop-shadow('),
    'Small accepted UI icons must drop the artificial black CSS shadow and blend dark pixels into the control surface.');

$assert(str_contains($css, '.balance-card{')
    && str_contains($css, 'padding-left:10px;')
    && str_contains($css, 'transform:translateX(-5px) scale(1.16);'),
    'Economy art must visually align closer to the balance value left edge.');

$assert(str_contains($css, '.game-card .game-icon{')
    && str_contains($css, 'width:72px !important;')
    && str_contains($css, 'background:transparent !important;')
    && str_contains($css, 'border-radius:0 !important;')
    && str_contains($css, '.game-card .game-icon .shield-king-game-art{')
    && str_contains($css, 'filter:none !important;'),
    'Rich game art must own its visual boundary without the obsolete square tile or added shadow.');

$assert(str_contains($css, '.btn[data-invite-friend] > .shield-king-button-icon')
    && str_contains($css, 'transform:scale(1.15);'),
    'Invite action must expose the accepted icon clearly without its former black CSS shadow.');

foreach (['mgw-phase-b', 'preloader', '.board', '.cell', '.piece'] as $forbidden) {
    $assert(!str_contains($css, $forbidden), 'V7 visual cleanup must not target loader or gameplay-board owners: ' . $forbidden);
}

fwrite(STDOUT, "ShieldKingMinorVisualCleanupContractTest: {$assertions} assertions passed\n");
