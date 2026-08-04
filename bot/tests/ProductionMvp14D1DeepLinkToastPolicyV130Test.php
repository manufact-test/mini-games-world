<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$policy = file_get_contents($root . '/app/assets/js/notification-deeplink-toast-policy-v130.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v130.js');
$linkEntry = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
if (!is_string($entry) || !is_string($policy) || !is_string($notifications) || !is_string($linkEntry)) {
    throw new RuntimeException('Missing v130 notification separation sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$policyPosition = strpos($entry, 'notification-deeplink-toast-policy-v130.js?v=130');
$mainPosition = strpos($entry, 'main.js?v=115');
$assert($policyPosition !== false && $mainPosition !== false && $policyPosition < $mainPosition,
    'The deep-link policy must run before application boot.');
$assert(str_contains($entry, 'notifications-passive-v130.js?v=130'),
    'The v130 passive notification coordinator must be published.');
$assert(str_contains($entry, 'v123-mvp14-d1-two-manual-regressions'),
    'The established v123 shell identity must remain observable.');

$assert(str_contains($linkEntry, 'announce:false'),
    'Invitation link entry must explicitly request a silent notification snapshot.');
$assert(str_contains($policy, 'window.__MGW_INVITE_LINK_OPENING__ = true')
        && str_contains($policy, "event.detail?.announce !== false")
        && str_contains($policy, 'event.stopImmediatePropagation();'),
    'The policy must suppress only silent deep-link notification events.');
$assert(str_contains($policy, "toast?.classList.remove('show', 'dragging')")
        && str_contains($policy, 'rememberNotificationId(id)'),
    'A suppressed deep-link toast must be hidden and remembered.');

$assert(str_contains($notifications, 'BASELINE_CLOCK_SAFETY_MS')
        && str_contains($notifications, 'pendingNotification = candidate')
        && str_contains($notifications, 'showPendingNotification();'),
    'A real incoming notification during baseline loading must be deferred instead of swallowed.');
$assert(str_contains($notifications, 'const shouldAnnounce = event.detail?.announce !== false')
        && str_contains($notifications, 'if (!shouldAnnounce)')
        && str_contains($notifications, 'rememberNotificationId(id);'),
    'The passive coordinator must honor announce:false without losing unread state.');
$assert(str_contains($notifications, 'window.__MGW_INVITE_LINK_OPENING__ === true'),
    'The passive coordinator must never paint a toast while a deep-link invitation is opening.');
$assert(str_contains($notifications, "String(activeScreen?.dataset.screen || '') !== 'home'")
        && str_contains($notifications, "classList.contains('active')"),
    'Normal toast display remains limited to the visible home screen without an open sheet.');

fwrite(STDOUT, "ProductionMvp14D1DeepLinkToastPolicyV130Test: {$assertions} assertions passed\n");
