<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
if (!is_string($entry) || !is_string($owner)) throw new RuntimeException('Missing v124 desktop notification sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1, 'v121 owner must be published once.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'), 'Retired desktop owners must remain absent.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
    && str_contains($owner, "window.addEventListener('pointerup'")
    && str_contains($owner, '#notificationsOpen, #notificationToast')
    && str_contains($owner, 'event.stopImmediatePropagation();'), 'The original desktop pointer sequence must be captured.');
$assert(str_contains($owner, 'performance.now() - pointer.startedAt')
    && str_contains($owner, 'Math.hypot(dx, dy) > TAP_MOVE_TOLERANCE_PX')
    && str_contains($owner, 'openFromUserInput();'), 'A short stationary pointerup must open immediately.');
$assert(str_contains($owner, 'if (isSuppressedClickTail(event))')
    && str_contains($owner, 'Math.hypot(dx, dy) <= CLICK_SUPPRESSION_RADIUS_PX')
    && !str_contains($owner, '.click()')
    && !str_contains($owner, 'openingSheet'), 'The generated or retargeted click tail must be consumed without retry or lock.');
$assert(str_contains($owner, 'renderLoading();')
    && str_contains($owner, 'requestGeneration === generation')
    && substr_count($owner, 'api.notifications(true)') >= 2, 'Slow and empty responses must remain generation-gated.');
fwrite(STDOUT, "ProductionMvp14D1DesktopBellFirstClickV117Test: {$assertions} assertions passed\n");
