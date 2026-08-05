<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/app/index.html');
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
if (!is_string($index) || !is_string($entry) || !is_string($main)) throw new RuntimeException('Missing canonical main entry source.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($index, './assets/js/main.js?v=98.3') === 1
    && str_contains($entry, "'./assets/js/main.js?v=98.3'")
    && str_contains($entry, "'./assets/js/main.js?v=d1-bell-single-owner'"),
    'Source shell must retain one replacement anchor and staging must publish canonical main.');
$assert(str_contains($entry, 'data-hotfix-build="d1-bell-single-owner"')
    && str_contains($entry, 'X-MGW-Frontend-Build: d1-bell-single-owner')
    && str_contains($main, "window.__MGW_BUILD__ = 'd1-bell-single-owner'"),
    'Served shell, response header and main marker must identify the canonical graph.');
$assert(!str_contains($main, '__MGW_HOTFIX_BUILD__')
    && !str_contains($entry, 'notification-window-owner')
    && !str_contains($entry, 'notification-compat-click-guard')
    && !str_contains($entry, 'opponents-authoritative-confirm'),
    'No hotfix marker or injected owner may remain.');
$assert(str_contains($index, './assets/js/production-regression-fix-entry.js?v=102'),
    'Unrelated regression entry must retain its reviewed identity.');
$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($entry, 'mini-games-world.com')
    && !str_contains($main, 'mini-games-world.com'),
    'Staging package must not introduce a production target.');
fwrite(STDOUT, "ProductionMvp14R13MainEntryCacheBustTest: {$assertions} assertions passed\n");
