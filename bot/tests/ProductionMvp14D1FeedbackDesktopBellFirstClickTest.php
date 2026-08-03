<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$guard = file_get_contents($root . '/app/assets/js/screens/notification-bell-first-click-v115.js');
if (!is_string($entry) || !is_string($guard)) {
    throw new RuntimeException('Missing desktop bell first-click sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($entry, 'notification-bell-first-click-v115.js?v=115')
        && substr_count($entry, 'notification-bell-first-click-v115.js?v=115') === 1,
    'The staging entry must load exactly one fresh bell recovery guard.'
);

$assert(
    str_contains($guard, "target?.closest('#notificationsOpen')")
        && str_contains($guard, '!event.isTrusted')
        && str_contains($guard, 'bell.click();')
        && str_contains($guard, 'RETRY_LIMIT_MS = 1400'),
    'A trusted user click must be retried within a bounded window when the canonical owner ignored it.'
);

$assert(
    str_contains($guard, "target?.closest('[data-close-sheet]')")
        && str_contains($guard, 'if (isNotificationsSheetOpen()) return cancelAttempt();')
        && str_contains($guard, 'if (isAnySheetOpen()) return cancelAttempt();')
        && str_contains($guard, "document.visibilityState !== 'visible'"),
    'Retries must stop on manual close, successful open, another sheet or hidden document.'
);

$assert(
    !str_contains($guard, 'preventDefault')
        && !str_contains($guard, 'stopImmediatePropagation')
        && !str_contains($guard, 'openSheet(')
        && !str_contains($guard, 'api.notifications')
        && !str_contains($guard, 'data-invite-action'),
    'The recovery guard must not become a second bell, network or invitation owner.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackDesktopBellFirstClickTest: {$assertions} assertions passed\n");
