<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$index = file_get_contents($root . '/app/index.html');
$main = file_get_contents($root . '/app/assets/js/main.js');
if (!is_string($index) || !is_string($main)) {
    throw new RuntimeException('Missing main entry cache-bust source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($index, './assets/js/main.js?v=98') === 1,
    'The application HTML must load exactly one v98 main entry.');
$assert(!str_contains($index, './assets/js/main.js?v=97')
    && !str_contains($index, './assets/js/main.js?v=96'),
    'No stale v97 or v96 main entry may remain reachable from the application HTML.');
$assert(str_contains($index, 'data-hotfix-build="v98-mvp14-notification-canonical-owner"'),
    'The visible application shell must publish the v98 canonical-owner identity.');
$assert(str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v98-mvp14-notification-canonical-owner'"),
    'The loaded main module must publish the matching v98 hotfix marker.');
$assert(str_contains($main, "./first-interaction-readiness-v103.js?v=103"),
    'The active main graph must cache-bust the changed first-interaction module.');

$canonicalInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($canonicalInit !== false && $prewarmInit !== false && $canonicalInit < $prewarmInit,
    'The cache-busted entry must initialize the canonical notification owner before generic prewarm.');
$assert(str_contains($index, './assets/js/production-regression-fix-entry.js?v=102'),
    'The unrelated production regression entry must retain its reviewed cache identity.');
$assert(!str_contains($index, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($index, 'mini-games-world.com'),
    'The entrypoint cache change must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13MainEntryCacheBustTest: {$assertions} assertions passed\n");
