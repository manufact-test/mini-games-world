<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$invites = file_get_contents($root . '/app/assets/js/games/game-invites-v110.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v110r13.js');
$manifest = file_get_contents($root . '/app/runtime/client/version-manifest.php');
if (!is_string($invites) || !is_string($notifications) || !is_string($manifest)) {
    throw new RuntimeException('Unable to read notification terminal runtime files.');
}

$start = strpos($invites, 'async function performInviteAction(action, token, button){');
$end = strpos($invites, "\nfunction beginInviteStartTransition", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('performInviteAction owner not found.');
}
$block = substr($invites, $start, $end - $start);

foreach ([
    "const optimisticNotificationTerminal = terminalContext.notificationSurface",
    "new CustomEvent('mgw:notification-remove'",
    "if (terminalContext.notificationSurface) dispatchNotificationsRefresh();",
    "querySelector('[data-notifications-owner]')",
] as $needle) {
    if (!str_contains($invites, $needle)) {
        throw new RuntimeException('Missing silent actor terminal contract: ' . $needle);
    }
}

if (str_contains($invites, "querySelector('[data-notifications-owner=\"r12\"]')")) {
    throw new RuntimeException('Notification action routing must not be pinned to obsolete owner r12.');
}

$optimistic = strpos($block, 'optimisticNotificationTerminal');
$request = strpos($block, "await inviteRequest(action, { token })");
$close = strpos($block, 'closeSheet();', $optimistic === false ? 0 : $optimistic);
$remove = strpos($block, "new CustomEvent('mgw:notification-remove'", $optimistic === false ? 0 : $optimistic);
if ($optimistic === false || $request === false || $close === false || $remove === false || $close > $request || $remove > $request) {
    throw new RuntimeException('Actor decline/cancel must close and remove locally before awaiting the network.');
}

$notificationBranch = strpos($block, 'if (terminalContext.notificationSurface) {');
$participantBranch = strpos($block, '} else if (selfCancelledParticipant)', $notificationBranch === false ? 0 : $notificationBranch);
if ($notificationBranch === false || $participantBranch === false) {
    throw new RuntimeException('Notification terminal success branch not found.');
}
$notificationSuccess = substr($block, $notificationBranch, $participantBranch - $notificationBranch);
if (str_contains($notificationSuccess, "mgw:notification-sync") || str_contains($notificationSuccess, 'showTerminalInvite(')) {
    throw new RuntimeException('Acting user must not receive a terminal self-confirmation card or sheet.');
}
if (!str_contains($notificationSuccess, "mgw:notification-remove") || !str_contains($notificationSuccess, 'dispatchNotificationsRefresh();')) {
    throw new RuntimeException('Actor terminal success must remove local card and converge with authoritative refresh.');
}

if (!str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
    || !str_contains($notifications, 'function removeInviteNotification(detail)')) {
    throw new RuntimeException('Notification owner must retain the canonical actor-card removal path.');
}

if (!str_contains($invites, 'data-rematch-pending')
    || !str_contains($invites, 'Реванш предложен')
    || str_contains($invites, 'Предлагаем реванш…')) {
    throw new RuntimeException('Silent terminal correction must preserve the accepted optimistic rematch UX.');
}

if (!str_contains($manifest, 'game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent')) {
    throw new RuntimeException('Silent terminal runtime cache-bust missing.');
}

fwrite(STDOUT, "Notification actor silent terminal contract: OK\n");
