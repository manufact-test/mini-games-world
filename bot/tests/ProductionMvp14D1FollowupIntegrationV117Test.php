<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($notifications)
    || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing integrated D1 follow-up sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1,
    'Integrated entry must publish the canonical notification owner v119 exactly once.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v117.js?v=117') === 1,
    'Integrated entry must retain the authoritative opponent confirmation exactly once.');
$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Responsive and retry notification owners must be retired from the active graph.');
$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
        && str_contains($notifications, "String(item?.invite_token || '') !== inviteToken")
        && str_contains($notifications, 'generation += 1;'),
    'Terminal invitation actions must clear stale actionable cards before history is reopened.'
);
$assert(
    !str_contains($notifications, 'openingSheet')
        && str_contains($notifications, 'const requestGeneration = ++generation;')
        && str_contains($notifications, 'requestGeneration === generation'),
    'Mobile and desktop openings must share generations and never be blocked by a stale in-flight flag.'
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
    str_contains($entry, 'data-hotfix-build="v119-mvp14-notification-canonical-owner"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v119-mvp14-notification-canonical-owner'),
    'The no-cache entry must expose the canonical v119 build identity.'
);

fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
