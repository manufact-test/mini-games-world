<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'app/assets/js/games/game-invites-v110.js',
    'app/assets/js/screens/notifications-screen-v110r5.js',
];

foreach ($paths as $path) {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot export source: ' . $path);
    fwrite(STDOUT, "MGW_SOURCE_BEGIN {$path}\n");
    fwrite(STDOUT, base64_encode($content) . "\n");
    fwrite(STDOUT, "MGW_SOURCE_END {$path}\n");
}

fwrite(STDOUT, "TemporarySourceExportTest passed\n");
