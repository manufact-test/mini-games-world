<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
if (!is_string($entry) || !is_string($owner)) throw new RuntimeException('Missing v123 mobile input sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1, 'v121 notification owner must be published exactly once.');
$assert(!str_contains($entry, 'notification-window-owner-v119.js?v=119')
    && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117'), 'Retired mobile and click-only owners must not remain active.');
$assert(str_contains($owner, '#notificationsOpen, #notificationToast')
    && str_contains($owner, "window.addEventListener('pointerdown'")
    && str_contains($owner, "window.addEventListener('pointerup'"), 'The same canonical pointer owner must handle mobile and desktop.');
$assert(str_contains($owner, 'const cached = freshItems();')
    && str_contains($owner, 'if (cached.length) renderNotifications(cached);')
    && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180'), 'Fresh notification data must paint immediately.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
    && str_contains($owner, 'requestGeneration === generation'), 'Slow responses must be confirmed and stale generations ignored.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-sync'")
    && str_contains($owner, 'rememberItems([item])')
    && str_contains($owner, 'openFromUserInput();')
    && !str_contains($owner, '.click()'), 'The first frame must use live cache and original mobile pointerup.');
fwrite(STDOUT, "ProductionMvp14D1MobileNotificationFirstFrameV117Test: {$assertions} assertions passed\n");
