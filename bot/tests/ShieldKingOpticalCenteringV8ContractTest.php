<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/app/assets/css/shield-king-icons-v6.css');
$main = file_get_contents($root . '/app/assets/css/main.css');
$entry = file_get_contents($root . '/app/v110.php');
$runtimeFiles = file_get_contents($root . '/bot/helpers/staging-e2e-runtime-files.txt');
foreach ([$css, $main, $entry, $runtimeFiles] as $source) {
    if (!is_string($source)) throw new RuntimeException('Missing Shield King V8 optical source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "@import url('./shield-king-icons-v6.css?v=129&icons=c1efd5af&render=8');"),
    'Main CSS must load the V8 optical pass after V7.');
$assert(str_contains($entry, './assets/css/main.css?v=128&sk=3&icons=c1efd5af&render=8'),
    'The real v110 entry must cache-bust the V8 CSS graph.');
$assert(str_contains($entry, "header('X-MGW-Icon-Render: accepted-v8-optical-monolithic');"),
    'The real v110 route must expose the V8 visual identity.');
$assert(str_contains($runtimeFiles, 'app/assets/css/shield-king-icons-v6.css'),
    'Hostinger exact fingerprint must include the V8 CSS file.');

$assert(str_contains($css, '@media (pointer:fine)')
    && str_contains($css, '.game-rules-button > img[data-sk-asset="ui/actions/rules.webp"]')
    && str_contains($css, 'transform:translateY(-3px) !important;'),
    'Rules artwork must receive fine-pointer optical centering without changing touch layout.');
$assert(str_contains($css, '#moreMenuOpen > img[data-sk-asset="ui/actions/more.webp"]')
    && str_contains($css, 'transform:translateY(3px) !important;')
    && str_contains($css, '#notificationsOpen > img[data-sk-asset="ui/navigation/notifications.webp"]')
    && str_contains($css, 'transform:none !important;'),
    'More must move optically down while the already-accepted bell remains untouched.');

$assert(str_contains($css, '.game-card .game-icon .shield-king-game-art{')
    && str_contains($css, 'width:92px !important;')
    && str_contains($css, 'height:123px !important;')
    && str_contains($css, 'transform:translateX(-10px) !important;'),
    'Accepted rich game art must be larger and visually closer to the button left edge.');

$assert(str_contains($css, '.mgw-phase-b-launch-card{')
    && str_contains($css, 'border:0 !important;')
    && str_contains($css, 'background:transparent !important;')
    && str_contains($css, 'box-shadow:none !important;')
    && str_contains($css, '.mgw-phase-b-launch-card:before{'),
    'Phase B presentation must be monolithic without the bright outer card frame.');

foreach (['animation-duration', 'LAUNCH_COUNTDOWN', 'setInterval', 'setTimeout', '.board', '.cell', '.piece'] as $forbidden) {
    $assert(!str_contains($css, $forbidden), 'V8 presentation must not own timing/runtime/board behavior: ' . $forbidden);
}

fwrite(STDOUT, "ShieldKingOpticalCenteringV8ContractTest: {$assertions} assertions passed\n");
