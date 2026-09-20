<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$canonical = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness-v103.js');
$index = file_get_contents($root . '/app/index.html');
$entry = file_get_contents($root . '/app/v114.php');
$v110 = file_get_contents($root . '/app/v110.php');
if (!is_string($main) || !is_string($canonical) || !is_string($prewarm)
    || !is_string($index) || !is_string($entry) || !is_string($v110)) {
    throw new RuntimeException('Missing notification ownership source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$canonicalInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($canonicalInit !== false && $prewarmInit !== false && $canonicalInit < $prewarmInit,
    'The canonical notifications screen must initialize before generic first-interaction warming.');

$assert(str_contains($main, "./screens/notifications-screen-v99.js?v=99")
    && !str_contains($main, "./screens/notifications-screen.js?v=99")
    && str_contains($main, "./first-interaction-readiness-v103.js?v=103")
    && str_contains($main, "./interaction-latency-coordinator-v101.js?v=101")
    && str_contains($main, "window.__MGW_BUILD__ = 'v96-mvp14-root-cause-stabilization'")
    && str_contains($main, "window.__MGW_HOTFIX_BUILD__ = 'v115-mvp14-d1-feedback-integration'")
    && str_contains($entry, '"./assets/js/screens/notifications-screen-v99.js?v=99": "./assets/js/screens/notifications-screen-v99.js?v=114"')
    && str_contains($entry, '"./assets/js/first-interaction-readiness-v103.js?v=103": "./assets/js/first-interaction-readiness-v103.js?v=114"'),
    'The v115 release must retain canonical specifiers while routing notifications and authoritative prewarm reads to immutable module objects.');

$assert(str_contains($canonical, "const trigger = event.target.closest('#notificationsOpen')")
    && str_contains($canonical, 'event.stopImmediatePropagation();')
    && str_contains($canonical, 'openNotificationsSheet();'),
    'The canonical screen must exclusively own notification rendering after the delegated click.');

$assert(str_contains($canonical, 'const result = await api.notifications(true);')
    && str_contains($canonical, 'renderNotifications(items);')
    && str_contains($canonical, 'data-invite-action=')
    && str_contains($canonical, 'data-invite-token='),
    'The canonical owner must fetch the fresh marked-read list and render invitation actions.');

$assert(str_contains($prewarm, 'warmNotificationsSnapshot()')
    && str_contains($prewarm, 'return api.notifications(false);'),
    'First-interaction readiness may warm notifications only through a read-only request.');

$assert(!str_contains($prewarm, "target.id === 'notificationsOpen'")
    && !str_contains($prewarm, 'renderNotificationsSheet')
    && !str_contains($prewarm, 'refreshNotificationsSnapshot')
    && !str_contains($prewarm, 'data-invite-action=')
    && !str_contains($prewarm, 'data-invite-token=')
    && !str_contains($prewarm, 'setUnreadCount(')
    && !str_contains($prewarm, 'api.notifications(Boolean(markRead))'),
    'Prewarm must not intercept, mark read, render or mutate the notifications interface.');

$retainedScript = '<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>';
$sourceMain = '<script type="module" src="./assets/js/main.js?v=98.3"></script>';
$regressionEntry = strpos($index, $retainedScript);
$sourceMainPosition = strpos($index, $sourceMain);
$assert($regressionEntry !== false
    && $sourceMainPosition !== false
    && $regressionEntry < $sourceMainPosition
    && str_contains($entry, "'./assets/js/main.js?v=98.3'")
    && str_contains($entry, "'./assets/js/main.js?v=115'")
    && str_contains($entry, 'data-hotfix-build="v115-mvp14-d1-feedback-integration"')
    && str_contains($entry, 'X-MGW-Frontend-Build: v115-mvp14-d1-feedback-integration'),
    'The immutable source shell must retain its replacement anchor while the active staging wrapper publishes the v115 graph after the retained regression script.');

$assert(str_contains($v110, "'./assets/js/main.js?v=98.3'")
    && !str_contains($v110, "'./assets/js/main.js?v=98',")
    && str_contains($v110, "'./assets/js/main-v110.js?v=1124'")
    && str_contains($v110, "'data-hotfix-build=\"v98-mvp14-notification-canonical-owner\"'"),
    'The historical v110 wrapper must remain unchanged and separate from the active staging v115 entry.');

$assert(substr_count($main, 'initNotificationsScreen();') === 1
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1,
    'Each canonical/prewarm initializer must run exactly once.');

$assert(!str_contains($entry, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($entry, 'mini-games-world.com')
    && !str_contains($main, 'mini-games-world.com'),
    'The UI ownership fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
