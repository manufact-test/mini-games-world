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
    'The application HTML must load exactly one v97 main entry.');
$assert(!str_contains($index, './assets/js/main.js?v=96'),
    'The stale v96 main entry must not remain reachable from the application HTML.');
$assert(str_contains($index, 'data-hotfix-build="v98-mvp14-notification-canonical-owner"'),
    'The visible application shell must publish the same v97 notification hotfix identity.');
$assert(str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v98-mvp14-notification-canonical-owner'"),
    'The loaded main module must publish the matching v97 hotfix marker.');

$canonicalInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($canonicalInit !== false && $prewarmInit !== false && $canonicalInit < $prewarmInit,
    'The cache-busted entry must contain the canonical-before-prewarm listener order.');
$assert(str_contains($index, './assets/js/production-regression-fix-entry.js?v=96'),
    'The unrelated production regression entry must retain its reviewed cache identity.');
$assert(!str_contains($index, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($index, 'mini-games-world.com'),
    'The entrypoint cache change must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13MainEntryCacheBustTest: {$assertions} assertions passed\n");
