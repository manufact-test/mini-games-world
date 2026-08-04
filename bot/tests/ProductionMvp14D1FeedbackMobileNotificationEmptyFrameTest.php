<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
if (!is_string($entry) || !is_string($owner)) throw new RuntimeException('Missing v123 mobile notification sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
    && !str_contains($entry, 'notification-window-owner-v119.js?v=119')
    && !str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115'), 'v123 must publish one v121 owner without retired empty-frame layers.');
$assert(str_contains($owner, 'notificationTrigger(event.target)')
    && str_contains($owner, '#notificationsOpen, #notificationToast')
    && str_contains($owner, "document.addEventListener('mgw:notification-sync'")
    && str_contains($owner, 'rememberItems([item])'), 'Bell and toast must use the live first-frame cache.');
$assert(str_contains($owner, 'const cached = freshItems();')
    && str_contains($owner, 'if (cached.length) renderNotifications(cached);')
    && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180'), 'A fresh actionable item must paint before empty confirmation.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
    && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();')
    && str_contains($owner, 'requestGeneration === generation'), 'A delayed false-empty response must not replace fresh data.');
$assert(str_contains($owner, "window.addEventListener('pointerup'")
    && str_contains($owner, 'openFromUserInput();')
    && !str_contains($owner, '.click()')
    && !str_contains($owner, 'openingSheet'), 'Mobile opening must use the original pointerup without a synthetic click.');
fwrite(STDOUT, "ProductionMvp14D1FeedbackMobileNotificationEmptyFrameTest: {$assertions} assertions passed\n");
