<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
if (!is_string($entry) || !is_string($owner)) {
    throw new RuntimeException('Missing canonical mobile notification first-frame sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115'),
    'The staging entry must publish one canonical v119 owner and no retired empty-frame guard.'
);

$assert(
    str_contains($owner, "target?.closest('#notificationsOpen, #notificationToast')")
        && str_contains($owner, "document.addEventListener('mgw:notification-sync'")
        && str_contains($owner, 'rememberItems([item])'),
    'Bell and blue-toast openings must use the cache primed by live document notification events.'
);

$assert(
    str_contains($owner, 'const cached = freshItems();')
        && str_contains($owner, 'if (cached.length) renderNotifications(cached);')
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180'),
    'A fresh actionable item must paint immediately while a possible empty response is confirmed.'
);

$assert(
    substr_count($owner, 'api.notifications(true)') >= 2
        && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();')
        && str_contains($owner, 'requestGeneration === generation'),
    'A delayed false-empty result must not replace the fresh item and stale generations must be ignored.'
);

$assert(
    !str_contains($owner, 'GUARD_MS')
        && !str_contains($owner, 'originalHtml')
        && !str_contains($owner, 'openingSheet'),
    'The canonical owner must solve the first frame through state and requests, not temporary DOM masking or a blocking flag.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackMobileNotificationEmptyFrameTest: {$assertions} assertions passed\n");
