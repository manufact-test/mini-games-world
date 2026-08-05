<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$entry = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$e2e = $read('e2e/staging/d1-real-user-regressions-v127.spec.mjs');

// This contract intentionally follows the ordinary Telegram Start route, not the staging-only /app/ menu.
$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1123';"),
    'Ordinary Telegram Start must remain on the canonical v110 route.'
);
$assert(
    str_contains($entry, './assets/js/main-v110.js?v=1127'),
    'The ordinary Start entry must publish the fresh v1127 main identity.'
);
$assert(
    str_contains($main, './main-v110-handoff-shell.js?v=1127'),
    'The ordinary Start main module must publish the fresh v1127 shell identity.'
);
$assert(
    str_contains($shell, "./screens/notifications-screen-v110r12.js?v=1126"),
    'The ordinary Start shell must retain the accepted v1126 notification owner.'
);

$handlerStart = strpos($notifications, 'function handleDocumentClick(event)');
$handlerEnd = strpos($notifications, 'function handleSheetClosed()', $handlerStart ?: 0);
$assert(
    $handlerStart !== false && $handlerEnd !== false && $handlerEnd > $handlerStart,
    'Canonical bell handler boundaries are unavailable.'
);
$bellHandler = substr($notifications, $handlerStart, $handlerEnd - $handlerStart);

$assert(
    substr_count($notifications, "document.addEventListener('click', handleDocumentClick, true)") === 1,
    'The notification bell must have exactly one capture-phase click owner.'
);
$assert(
    str_contains($bellHandler, "target.closest('#notificationsOpen')"),
    'The canonical owner must resolve the real notification bell.'
);
$assert(
    str_contains($bellHandler, 'event.preventDefault();')
        && str_contains($bellHandler, 'event.stopImmediatePropagation();'),
    'The canonical bell owner must consume the original click before competitors.'
);
$assert(
    !str_contains($bellHandler, 'closeGuardUntil')
        && !str_contains($bellHandler, 'openGuardUntil'),
    'The real bell click must not be discarded by a post-close or post-open timer blackout.'
);
$assert(
    str_contains($bellHandler, "openNotificationsSheet({ seed:currentItems(), source:'bell' })"),
    'Every accepted bell click must open through the canonical notification sheet owner.'
);
$assert(
    str_contains($notifications, 'announcementGuardUntil')
        && str_contains($notifications, "source:'toast'"),
    'Toast and announcement protections must remain available outside the bell handler.'
);
$assert(
    str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;'),
    'The real-user regression must exercise the actual ordinary Telegram Start route.'
);
$assert(
    str_contains($e2e, 'reopens immediately for 25 click cycles')
        && str_contains($e2e, 'for (let cycle = 0; cycle < 25; cycle += 1)'),
    'The regression must verify repeated immediate open-close-open cycles.'
);

echo "ProductionMvp14D1BellImmediateReopenV1126Test: 13 assertions passed\n";
