<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$mobile = file_get_contents($root . '/app/assets/js/screens/notification-mobile-open-owner-v117.js');
$desktop = file_get_contents($root . '/app/assets/js/screens/notification-desktop-open-owner-v117.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
$endpoint = file_get_contents($root . '/bot/notifications.php');
if (!is_string($entry) || !is_string($mobile) || !is_string($desktop)
    || !is_string($opponents) || !is_string($endpoint)) {
    throw new RuntimeException('Missing integrated D1 follow-up sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'notification-mobile-open-owner-v117.js?v=117',
    'notification-desktop-open-owner-v117.js?v=117',
    'opponents-authoritative-confirm-v117.js?v=117',
] as $script) {
    $assert(substr_count($entry, $script) === 1, "Integrated entry must publish {$script} exactly once.");
}
$assert(
    strpos($entry, '$mobileNotificationOwner') < strpos($entry, '$desktopNotificationOwner')
        && strpos($entry, '$desktopNotificationOwner') < strpos($entry, '$bellFirstClickGuard')
        && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard . "\\n  " . $opponentsConfirm'),
    'Responsive notification owners must precede the fallback bell guard and opponent confirmation must follow main/v115 guard.'
);
$assert(
    str_contains($mobile, "document.addEventListener('mgw:notification-remove'")
        && str_contains($desktop, "document.addEventListener('mgw:notification-remove'")
        && str_contains($mobile, 'removeInviteToken(')
        && str_contains($desktop, 'removeInviteToken('),
    'Terminal invitation actions must clear pending cards from both fast owner caches before history is reopened.'
);
$assert(
    !str_contains($mobile, 'openingSheet')
        && !str_contains($desktop, 'openingSheet')
        && str_contains($mobile, 'requestGeneration !== generation')
        && str_contains($desktop, 'requestGeneration !== generation'),
    'Mobile and desktop openings must be generation-based and never blocked by a stale in-flight request flag.'
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
    'The no-cache entry must retain the stable integrated build identity while v117 modules carry their own immutable URLs.'
);

fwrite(STDOUT, "ProductionMvp14D1FollowupIntegrationV117Test: {$assertions} assertions passed\n");
