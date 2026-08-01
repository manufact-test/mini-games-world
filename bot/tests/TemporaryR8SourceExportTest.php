<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = [
    'app/assets/js/games/game-invites-v110.js',
    'app/assets/js/screens/notifications-screen-v110r5.js',
    'app/assets/js/main-v110-handoff-shell.js',
    'app/assets/js/main-v110.js',
    'app/assets/js/production-clean-entry-v110.js',
    'app/v110.php',
    'bot/invites.php',
    'bot/helpers/WebAppLaunchUrl.php',
];

foreach ($paths as $path) {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot export source: ' . $path);
    fwrite(STDOUT, "MGW_SOURCE_BEGIN {$path}\n");
    fwrite(STDOUT, base64_encode($content) . "\n");
    fwrite(STDOUT, "MGW_SOURCE_END {$path}\n");
}

$tests = glob($root . '/bot/tests/*.php') ?: [];
foreach ($tests as $testPath) {
    $content = file_get_contents($testPath);
    if (!is_string($content)) continue;
    if (!preg_match('/v1111|mvp14r6|game-invites-v110\\.js\\?v=1110|notifications-screen-v110r5\\.js\\?v=1110/', $content)) continue;
    $relative = substr($testPath, strlen($root) + 1);
    fwrite(STDOUT, "MGW_SOURCE_BEGIN {$relative}\n");
    fwrite(STDOUT, base64_encode($content) . "\n");
    fwrite(STDOUT, "MGW_SOURCE_END {$relative}\n");
}

fwrite(STDOUT, "TemporaryR8SourceExportTest passed\n");
