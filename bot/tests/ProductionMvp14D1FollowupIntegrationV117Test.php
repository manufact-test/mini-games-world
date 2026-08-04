<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($notifications)
    || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing integrated D1 follow-up v122 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1,
    'The isolated Bug B entry must retain notification owner v119 exactly once.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The entry must publish only authoritative opponent confirmation v122.');
$assert(!str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Retired notification owners must remain absent.');
$assert(str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
        && str_contains($notifications, "String(item?.invite_token || '') !== inviteToken")
        && str_contains($notifications, 'generation += 1;'),
    'Terminal invitation actions must still clear stale actionable cards.');
$assert(!str_contains($notifications, 'openingSheet')
        && str_contains($notifications, 'const requestGeneration = ++generation;')
        && str_contains($notifications, 'requestGeneration === generation'),
    'Accepted notification generation protection must remain unchanged.');
$assert(str_contains($opponents, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]')
        && str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
        && str_contains($opponents, "payload?.storage_driver === 'database'")
        && !str_contains($opponents, 'openSheet('),
    'Opponent empty confirmation must be bounded, DB-primary and transport-only.');
$assert(str_contains($endpoint, "['pending', 'accepted', 'declined']")
        && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
        && str_contains($endpoint, "\$item['read'] = true;"),
    'Declined invitation read history must remain intact.');
$assert(str_contains($entry, 'data-hotfix-build="v122-mvp14-opponents-authoritative-source"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v122-mvp14-opponents-authoritative-source'),
    'The no-cache entry must expose the v122 build identity.');

fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
