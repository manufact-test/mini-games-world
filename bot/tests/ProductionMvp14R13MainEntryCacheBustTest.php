<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/app/index.html');
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
if (!is_string($index) || !is_string($entry) || !is_string($main)) {
    throw new RuntimeException('Missing main entry cache-bust source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($index, './assets/js/main.js?v=98.3') === 1
        && str_contains($entry, "'./assets/js/main.js?v=98.3'")
        && str_contains($entry, "'./assets/js/main.js?v=115'"),
    'The source shell must retain one replacement anchor and the staging wrapper must publish v115 main.');
$assert(!str_contains($entry, "'./assets/js/main.js?v=114'")
        && !str_contains($entry, "'./assets/js/main.js?v=97'")
        && !str_contains($entry, "'./assets/js/main.js?v=96'"),
    'No stale active main target may remain in the staging wrapper.');
$assert(str_contains($entry, 'data-hotfix-build="v121-mvp14-notification-short-input-owner"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v121-mvp14-notification-short-input-owner'),
    'The served shell and response header must publish the v121 identity.');
$assert(str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v115-mvp14-d1-feedback-integration'"),
    'The retained main module must keep its reviewed v115 marker.');
$assert(str_contains($main, './first-interaction-readiness-v103.js?v=103')
        && str_contains($entry, '"./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114"'),
    'The active graph must retain the reviewed prewarm object.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && str_contains($entry, 'notifications-passive-v121.js?v=121')
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117'),
    'The cache-busted shell must publish one v121 input owner and one passive service.');
$assert(str_contains($index, './assets/js/production-regression-fix-entry.js?v=102'),
    'The unrelated production regression entry must retain its reviewed identity.');
$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
        && !str_contains($entry, 'mini-games-world.com')
        && !str_contains($main, 'mini-games-world.com'),
    'The staging cache change must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13MainEntryCacheBustTest: {$assertions} assertions passed\n");
