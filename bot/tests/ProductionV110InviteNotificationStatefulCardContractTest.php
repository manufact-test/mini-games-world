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

$assert(
    str_contains($endpoint, "in_array(\$status, ['pending', 'accepted'], true)")
        && str_contains($endpoint, "if (\$status === 'declined')")
        && str_contains($endpoint, "if (\$status === 'cancelled')"),
    'A received invitation card must remain server-visible while pending, accepted or terminal for its acting user.'
);
$assert(
    str_contains($endpoint, "(string)(\$invite['invitee_id'] ?? '') === \$notificationUserId")
        && str_contains($endpoint, "(string)(\$invite['cancelled_by'] ?? '') === \$notificationUserId"),
    'Terminal visibility must be scoped to the user who declined or cancelled the invitation.'
);
$assert(
    str_contains($endpoint, "\$item['title'] = 'Приглашение принято';")
        && str_contains($endpoint, "\$item['title'] = 'Приглашение отклонено';")
        && str_contains($endpoint, "\$item['title'] = 'Приглашение отменено';")
        && str_contains($endpoint, "\$item['message'] = 'Приглашение больше недоступно.';"),
    'The authoritative notification endpoint must convert the original card to its current lifecycle state.'
);
$assert(
    str_contains($endpoint, "mgw_notification_is_received_type(\$type) && \$status !== 'pending'")
        && str_contains($endpoint, "\$item['read'] = true;"),
    'An invitation the user already acted on must stay visible without becoming a new unread notification.'
);
$assert(
    str_contains($actions, "message:'Приглашение больше недоступно.'")
        && str_contains($actions, "document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'))"),
    'The immediate local card and the following authoritative refresh must converge on the same terminal state.'
);

fwrite(STDOUT, "ProductionV110InviteNotificationStatefulCardContractTest: {$assertions} assertions passed\n");
