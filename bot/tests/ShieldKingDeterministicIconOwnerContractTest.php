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
$cleanEntry = $read('app/assets/js/production-clean-entry-v110.js');
$owner = $read('app/assets/js/production-deterministic-icons.js');
$gameCards = $read('app/assets/js/games/game-card-copy.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assert(str_contains($v110, 'X-MGW-Icon-Render: accepted-v6-static-owner-fix'), 'Real Telegram v110 route must expose V6 owner-fix identity.');
$assert(str_contains($v110, './assets/js/production-clean-entry-v110.js?v=1122&sk=6'), 'Real Telegram v110 route must refresh the clean-entry owner graph.');
$assert(str_contains($cleanEntry, "./production-deterministic-icons.js?v=97&sk=6"), 'Clean entry must refresh the deterministic icon owner.');
$assert(substr_count($cleanEntry, 'initDeterministicGameIcons();') === 1, 'Deterministic icon owner must still have exactly one initializer.');

$guard = 'img.shield-king-game-art[data-sk-delivery="static"][data-sk-asset^="games/"]';
$guardPos = strpos($owner, $guard);
$svgWritePos = strpos($owner, 'icon.innerHTML = markup');
$assert($guardPos !== false, 'Deterministic icon owner must recognize accepted static rich game art.');
$assert($svgWritePos !== false && $guardPos < $svgWritePos, 'Accepted rich-art guard must run before legacy SVG fallback write.');
$assert(str_contains($owner, 'if (acceptedRichArt)'), 'Accepted rich art must short-circuit the legacy SVG owner.');
$assert(str_contains($owner, 'return;'), 'Accepted rich art guard must yield ownership.');
$assert(str_contains($owner, 'new MutationObserver'), 'Existing deterministic observer must remain the single legacy fallback owner.');
$assert(str_contains($owner, "document.addEventListener('mgw:app-ready', renderAllGameIcons)"), 'Existing app-ready fallback hook must remain intact.');

foreach (['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'] as $game) {
    $assert(str_contains($owner, $game . ':'), 'Legacy deterministic SVG fallback missing game: ' . $game);
}

$assert(str_contains($gameCards, "const GAME_ICON_ROOT = './assets/icons/shield-king/accepted/files/';"), 'Primary game-card renderer must remain static rich WebP.');
$assert(str_contains($gameCards, "image.dataset.skDelivery = 'static'"), 'Primary rich game art must retain static delivery marker.');
$assert(!str_contains($gameCards, 'shield-king-icon.php'), 'Primary game-card renderer must not return to the PHP/ZIP extractor.');

$assert(str_contains($fingerprint, 'app/assets/js/production-deterministic-icons.js'), 'Exact Hostinger fingerprint must include the deterministic icon owner.');
$assert(!str_contains($owner, '.preloader') && !str_contains($owner, 'hidePreloader'), 'Owner fix must not touch loaders.');
$assert(!str_contains($owner, '#gameBoard') && !str_contains($owner, '.board-cell'), 'Owner fix must not touch gameplay boards.');
$assert(!str_contains($owner, 'fetch(') && !str_contains($owner, 'setInterval(') && !str_contains($owner, 'XMLHttpRequest'), 'Owner fix must not add network or polling ownership.');

fwrite(STDOUT, "Shield King deterministic icon owner contract PASS ({$assertions} assertions).\n");
