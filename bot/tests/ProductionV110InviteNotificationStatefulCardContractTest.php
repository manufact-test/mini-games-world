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

$endpoint = $read('bot/notifications.php');
$actions = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$serviceActions = $read('bot/services/invites/GameInviteActionTrait.php');

$assert(
    str_contains($endpoint, "return in_array(\$status, ['pending', 'accepted'], true);")
        && !str_contains($endpoint, "if (\$status === 'declined')")
        && !str_contains($endpoint, "if (\$status === 'cancelled')"),
    'A received invitation card must remain server-visible only while it is actionable for the acting user.'
);
$assert(
    str_contains($endpoint, "function mgw_notification_is_visible(array \$item, ?array \$invite, string \$userId = '')")
        && str_contains($endpoint, 'mgw_notification_is_visible($item, $invite, $userId)'),
    'Invitation visibility must remain authoritative at the notification endpoint.'
);
$assert(
    str_contains($endpoint, "\$item['title'] = 'Приглашение принято';")
        && !str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
        && !str_contains($endpoint, "\$item['title'] = 'Приглашение отменено';")
        && !str_contains($endpoint, "\$item['message'] = 'Приглашение больше недоступно.';"),
    'The actor notification endpoint must not convert a declined or cancelled received invitation into a terminal self-confirmation card.'
);
$assert(
    str_contains($endpoint, "mgw_notification_is_received_type(\$type) && \$status !== 'pending'")
        && str_contains($endpoint, "\$item['read'] = true;"),
    'Accepted invitations may remain visible and read, while terminal actor cards are excluded entirely.'
);
$assert(
    str_contains($actions, "new CustomEvent('mgw:notification-remove'")
        && str_contains($actions, "document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'))")
        && !str_contains($actions, "message:'Приглашение больше недоступно.'")
        && str_contains($notifications, 'function removeInviteNotification(detail)')
        && str_contains($notifications, 'localAuthority.delete(key)')
        && str_contains($notifications, 'sheetState.pinned.delete(key)'),
    'The immediate local action and authoritative refresh must converge by removing the actor card from all notification state.'
);
$declineStart = strpos($serviceActions, 'public function decline(');
$cancelStart = strpos($serviceActions, 'public function cancel(', $declineStart ?: 0);
$declineBlock = $declineStart !== false && $cancelStart !== false
    ? substr($serviceActions, $declineStart, $cancelStart - $declineStart)
    : '';
$assert(
    str_contains($declineBlock, "'invite_declined'")
        && str_contains($declineBlock, "'Приглашение отклонено'"),
    'The inviter must retain the separate authoritative decline notification.'
);

fwrite(STDOUT, "ProductionV110InviteNotificationStatefulCardContractTest: {$assertions} assertions passed\n");
