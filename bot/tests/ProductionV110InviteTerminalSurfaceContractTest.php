<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$actions = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');

$assert(
    str_contains($actions, "import { closeSheet } from '../components/sheet.js?v=1109';")
        && str_contains($actions, 'const notificationSurface = isNotificationSurface(button);'),
    'Terminal actions must identify the source surface while keeping one capture owner.'
);
$closePosition = strpos($actions, 'closeSheet();');
$requestPosition = strpos($actions, 'const result = await inviteRequest(action, token);');
$assert(
    $closePosition !== false && $requestPosition !== false && $closePosition < $requestPosition
        && str_contains($actions, "new CustomEvent('mgw:notification-remove'")
        && !str_contains($actions, 'if (notificationSurface) {')
        && !str_contains($actions, 'announce:false'),
    'A decline from either surface must close before the request and remove the actor card instead of updating it in place.'
);
$assert(
    !str_contains($actions, "toast('Приглашение отклонено.')")
        && !str_contains($actions, "toast('Приглашение отменено.')")
        && !str_contains($actions, 'terminalNotificationItem(')
        && !str_contains($actions, "message:'Приглашение больше недоступно.'"),
    'Decline and cancel success must remain silent without a terminal confirmation sheet, toast or retained card.'
);
$assert(
    str_contains($actions, 'const rawUnreadCount = result?.unread_count;')
        && str_contains($actions, 'if (unreadCount !== null)')
        && !str_contains($actions, 'Number(result?.unread_count || 0)'),
    'A terminal action response without an unread count must never clear unrelated unread notifications.'
);
$assert(
    str_contains($notifications, "document.addEventListener('mgw:notification-remove'")
        && str_contains($notifications, 'function removeInviteNotification(detail)')
        && str_contains($notifications, 'items.delete(id)')
        && str_contains($notifications, 'localAuthority.delete(key)')
        && str_contains($notifications, 'sheetState.pinned.delete(key)')
        && !str_contains($notifications, 'applyInviteActionResult'),
    'The notification owner must remove the card from every local state instead of terminalizing it.'
);
$assert(
    str_contains($invites, "if (action === 'decline') toast('Приглашение отклонено.');"),
    'The older branch remains unreachable behind the earlier capture owner until the separate canonical invite cleanup task.'
);

fwrite(STDOUT, "ProductionV110InviteTerminalSurfaceContractTest: {$assertions} assertions passed\n");
