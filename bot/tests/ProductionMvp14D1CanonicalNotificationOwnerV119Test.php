<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v124.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v124.js');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)) throw new RuntimeException('Missing v124 notification sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };

$assert(substr_count($entry, 'notification-window-owner-v124.js?v=124') === 1, 'v124 must publish its notification owner exactly once.');
$assert(str_contains($entry, 'notifications-passive-v124.js?v=124')
    && !str_contains($entry, 'notification-window-owner-v121.js?v=121')
    && !str_contains($entry, 'notifications-passive-v121.js?v=121'), 'v124 must retire v121 and route background work to passive v124.');
$assert(!str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
    && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'), 'All retired notification owners must remain absent.');
$assert(str_contains($owner, "window.addEventListener('pointerdown'")
    && str_contains($owner, "window.addEventListener('pointerup'")
    && str_contains($owner, "window.addEventListener('touchend'")
    && str_contains($owner, "window.addEventListener('click'")
    && str_contains($owner, 'event.stopImmediatePropagation();'), 'The complete pointer, touch and compatibility input sequence must be owned at capture.');
$assert(str_contains($owner, 'function handlePointerUp(event)')
    && str_contains($owner, 'function handleTouchEnd(event)')
    && str_contains($owner, 'function claimGesture(triggerId)')
    && str_contains($owner, 'openFromUserInput();'), 'The first valid input end must open once through the canonical claim path.');
$assert(str_contains($owner, 'function isCompatibilityTail(triggerId)')
    && str_contains($owner, 'INPUT_TAIL_SUPPRESSION_MS = 700')
    && str_contains($owner, 'beginNewPhysicalInput();'), 'Generated tails must be consumed without blocking the next physical gesture.');
$assert(!str_contains($owner, '.click()') && !str_contains($owner, 'openingSheet') && !str_contains($owner, 'STALE_REOPEN_BLOCK_MS'), 'No synthetic retry or blackout is allowed.');
$assert(str_contains($owner, "document.addEventListener('mgw:notification-sync'")
    && str_contains($owner, 'rememberItems([item])')
    && str_contains($owner, 'requestGeneration === generation'), 'Live cache and late-response protection must remain canonical.');
$assert(substr_count($owner, 'api.notifications(true)') >= 2
    && str_contains($owner, 'EMPTY_CONFIRM_DELAY_MS = 180')
    && str_contains($owner, 'if (!items.length && freshItems().length) items = freshItems();'), 'False empty notification responses must be confirmed.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
    && !str_contains($passive, "from '../components/sheet.js")
    && str_contains($passive, 'refreshNotificationBadge(false)')
    && str_contains($passive, 'showNotificationToast(item)'), 'The passive service cannot open or render the sheet.');
$assert(str_contains($entry, 'v124-mvp14-d1-live-failure-fixes'), 'The integrated shell must expose v124.');

fwrite(STDOUT, "ProductionMvp14D1CanonicalNotificationOwnerV119Test: {$assertions} assertions passed\n");
