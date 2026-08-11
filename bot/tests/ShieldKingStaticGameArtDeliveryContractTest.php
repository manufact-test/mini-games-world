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
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$gameCards = $read('app/assets/js/games/game-card-copy.js');
$fingerprint = $read('bot/helpers/staging-e2e-runtime-files.txt');

$assert(str_contains($v110, 'X-MGW-Icon-Render: accepted-v5-static-game-art'), 'Real Telegram v110 route must expose static game-art render identity.');
$assert(str_contains($v110, './assets/js/main-v110.js?v=1138&ux=1&sk=3&icons=c1efd5af&render=5'), 'Real Telegram v110 entry must refresh the active module graph.');
$assert(str_contains($main, 'main-v110-handoff-shell.js?v=1137&ux=1&sk=3&icons=c1efd5af&render=5'), 'Active main entry must refresh the authoritative v110 shell.');
$assert(str_contains($shell, "game-card-copy.js?v=83&sk=5&icons=c1efd5af&delivery=static"), 'Authoritative shell must import the static game-card renderer under a fresh URL.');
$assert(str_contains($gameCards, "const GAME_ICON_ROOT = './assets/icons/shield-king/accepted/files/';"), 'Game cards must use the static accepted asset root.');
$assert(!str_contains($gameCards, 'shield-king-icon.php'), 'Game cards must not depend on the PHP/ZIP icon extractor.');
$assert(str_contains($gameCards, "image.dataset.skDelivery = 'static'"), 'Rendered rich game art must identify static delivery for live verification.');

$games = [
    'tic-tac-toe.webp',
    'four-in-a-row.webp',
    'battleship.webp',
    'checkers.webp',
    'reversi.webp',
    'chess.webp',
    'go.webp',
    'domino.webp',
];

foreach ($games as $file) {
    $relative = 'app/assets/icons/shield-king/accepted/files/games/' . $file;
    $absolute = $root . '/' . $relative;
    $assert(is_file($absolute), 'Missing static accepted game art: ' . $file);
    $assert(filesize($absolute) > 50000, 'Accepted rich game art unexpectedly small: ' . $file);
    $size = getimagesize($absolute);
    $assert(is_array($size), 'Accepted rich game art must decode as an image: ' . $file);
    $assert(($size[0] ?? 0) === 384 && ($size[1] ?? 0) === 512, 'Accepted rich game art must remain 384x512: ' . $file);
    $assert(($size['mime'] ?? '') === 'image/webp', 'Accepted rich game art must remain WebP: ' . $file);
    $assert(str_contains($fingerprint, $relative), 'Hostinger exact fingerprint must include static game art: ' . $file);
}

foreach ([
    'games/tic-tac-toe.webp', 'games/four-in-a-row.webp', 'games/battleship.webp',
    'games/checkers.webp', 'games/reversi.webp', 'games/chess.webp', 'games/go.webp', 'games/domino.webp',
] as $asset) {
    $assert(str_contains($gameCards, $asset), 'Missing rich game-card mapping: ' . $asset);
}

$assert(!str_contains($gameCards, '.preloader'), 'Static game-art delivery must not touch loaders.');
$assert(!str_contains($gameCards, '#gameBoard') && !str_contains($gameCards, '.board-cell'), 'Static game-art delivery must not touch gameplay boards.');

fwrite(STDOUT, "Shield King static game-art delivery contract PASS ({$assertions} assertions).\n");
