<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$coordinator = file_get_contents($root . '/app/assets/js/interaction-latency-coordinator-v101.js');
$legacyCoordinator = file_get_contents($root . '/app/assets/js/interaction-latency-coordinator.js');
$index = file_get_contents($root . '/app/index.html');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($main) || !is_string($coordinator) || !is_string($legacyCoordinator)
    || !is_string($index) || !is_string($v110)) {
    throw new RuntimeException('Missing authoritative notification read source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "./interaction-latency-coordinator-v101.js?v=101")
    && !str_contains($main, "./interaction-latency-coordinator.js?v=90"),
    'The active application must load the corrected coordinator through a new physical object name.');

$authoritativeBranch = "if (meta?.kind === 'notifications' && meta.markRead) {\n      return baseFetch(input, init);\n    }";
$assert(str_contains($coordinator, $authoritativeBranch),
    'Marked-read notification requests must return the authoritative network response directly.');

$assert(!str_contains($coordinator, 'NOTIFICATIONS_CACHE_TTL_MS')
    && !str_contains($coordinator, 'notificationsCache')
    && !str_contains($coordinator, "refreshCacheInBackground(input, init, 'notifications')")
    && !str_contains($coordinator, 'cached.unread_count = 0'),
    'The corrected coordinator must contain no stale notifications response cache.');

$assert(str_contains($coordinator, "meta?.kind === 'history'")
    && str_contains($coordinator, 'HISTORY_CACHE_TTL_MS')
    && str_contains($coordinator, 'refreshHistoryCacheInBackground'),
    'The unrelated history first-interaction optimization must remain intact.');

$assert(str_contains($legacyCoordinator, 'NOTIFICATIONS_CACHE_TTL_MS')
    && str_contains($legacyCoordinator, 'notificationsCache'),
    'The reviewed historical coordinator must remain available for older accepted graphs.');

$assert(substr_count($index, './assets/js/main.js?v=98.3') === 1
    && !str_contains($index, './assets/js/main.js?v=98.1'),
    'The active HTML must publish exactly one fresh main graph for the corrected coordinator.');

$published = str_replace(
    [
        './assets/js/production-regression-fix-entry.js?v=102',
        './assets/js/main.js?v=98.3',
        'data-hotfix-build="v98-mvp14-notification-canonical-owner"',
    ],
    [
        './assets/js/production-clean-entry-v110.js?v=1120',
        './assets/js/main-v110.js?v=1124',
        'data-hotfix-build="v110-mvp14r12-invite-notification-presence-stability"',
    ],
    $index
);
$assert(str_contains($v110, "'./assets/js/main.js?v=98.3'")
    && substr_count($published, './assets/js/main-v110.js?v=1124') === 1
    && !str_contains($published, 'main-v110.js?v=1124.2')
    && !str_contains($published, './assets/js/main.js?v=98.3'),
    'The v110 publication must replace the exact active URL without a leaked subrevision.');

$assert(!str_contains($coordinator, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($coordinator, 'mini-games-world.com'),
    'The authoritative-read fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationAuthoritativeReadTest: {$assertions} assertions passed\n");
