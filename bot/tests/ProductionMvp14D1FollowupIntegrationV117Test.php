<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
$mobileRollback = file_get_contents($root . '/app/assets/js/screens/notification-mobile-open-owner-v117.js');
$desktopRollback = file_get_contents($root . '/app/assets/js/screens/notification-desktop-open-owner-v117.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($owner) || !is_string($mobileRollback)
    || !is_string($desktopRollback) || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing integrated D1 follow-up sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115'),
    'The integrated entry must publish one notification owner and no superseded click or visual guards.');
$assert(
    strpos($entry, '$notificationWindowOwner') < strpos($entry, '$nativeFetchGuard')
        && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard . "\\n  " . $opponentsConfirm'),
    'The notification owner must load before main while opponent confirmation remains after the canonical picker owner.'
);
$assert(
    str_contains($owner, "window.addEventListener('mgw:notification-remove'")
        && str_contains($owner, "String(item?.invite_token || '') !== inviteToken")
        && str_contains($owner, 'const requestGeneration = ++generation;')
        && !str_contains($owner, 'openingSheet'),
    'Terminal actions must clear first-frame cache and every open must be generation-based.'
);
$assert(
    str_contains($mobileRollback, "window.matchMedia('(max-width: 760px)')")
        && str_contains($desktopRollback, 'isDesktopSurface()'),
    'Superseded responsive owners must remain available only as rollback evidence.'
);
$assert(
    str_contains($opponents, 'RETRY_DELAYS_MS = [120, 260, 520]')
        && str_contains($opponents, "cache:'no-store'")
        && !str_contains($opponents, 'openSheet('),
    'Opponent empty confirmation must stay bounded, authoritative and transport-only.'
);
$assert(
    str_contains($endpoint, "['pending', 'accepted', 'declined']")
        && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
        && str_contains($endpoint, "\$item['read'] = true;")
        && str_contains($endpoint, "\$invite['declined_at']"),
    'The server must expose declined invitations as read timestamped history without changing unread behavior.'
);
$assert(
    str_contains($entry, 'data-hotfix-build="v115-mvp14-d1-feedback-integration"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v115-mvp14-d1-feedback-integration'),
    'The no-cache entry must retain the stable integrated build identity while owner modules use immutable URLs.'
);

fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
