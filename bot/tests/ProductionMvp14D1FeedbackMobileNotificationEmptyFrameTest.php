<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$legacy = file_get_contents($root . '/app/assets/js/screens/notification-empty-frame-guard-v115.js');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v118.js');
if (!is_string($entry) || !is_string($legacy) || !is_string($owner)) {
    throw new RuntimeException('Missing mobile notification first-frame sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v118.js?v=118') === 1
        && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115'),
    'The active graph must use one authoritative owner and retire the 420-ms visual mask.');
$assert(str_contains($legacy, 'const GUARD_MS = 420;')
        && str_contains($legacy, "'Пока уведомлений нет'"),
    'The retained rollback file must remain available but inactive.');
$assert(str_contains($owner, 'if (cached.length) renderNotifications(cached);')
        && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180')
        && substr_count($owner, 'api.notifications(true)') >= 2,
    'The active owner must paint cache immediately and confirm an empty server response authoritatively.');
$assert(str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();')
        && str_contains($owner, 'if (!canApply(requestGeneration)) return;'),
    'A delayed empty response must never replace a fresh card or a closed sheet.');
$assert(!str_contains($owner, 'mgwEmptyFrameGuard')
        && !str_contains($owner, 'GUARD_MS = 420'),
    'The single owner must solve first-frame state without a DOM masking layer.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackMobileNotificationEmptyFrameTest: {$assertions} assertions passed\n");
