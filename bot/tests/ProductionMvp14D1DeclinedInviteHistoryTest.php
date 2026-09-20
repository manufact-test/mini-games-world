<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$notifications = file_get_contents($root . '/bot/notifications.php');
if (!is_string($notifications)) {
    throw new RuntimeException('Missing notifications endpoint source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($notifications, "['pending', 'accepted', 'declined']"),
    'A received invitation must remain visible as history after the invitee declines it.'
);
$assert(
    str_contains($notifications, '$status === \'declined\' && $isInvitee')
        && str_contains($notifications, '$item[\'title\'] = \'Приглашение отклонено\';')
        && str_contains($notifications, 'Вы отклонили приглашение от '),
    'Declined history must be decorated for the invitee rather than hidden.'
);
$assert(
    str_contains($notifications, '$item[\'read\'] = true;')
        && str_contains($notifications, '$item[\'created_at\'] = (string)($invite[\'declined_at\']'),
    'Declined history must be read and timestamped with the actual decline time.'
);
$assert(
    str_contains($notifications, 'if (mgw_notification_is_received_type($type) && $status !== \'pending\') continue;'),
    'Terminal invitation history must never increase the unread badge or trigger a new alert.'
);
$assert(
    str_contains($notifications, 'if ($status === \'pending\' && $invitee) return [\'accept\', \'decline\'];')
        && !str_contains($notifications, 'if ($status === \'declined\' && $invitee) return'),
    'A declined history card must not expose invitation actions.'
);

fwrite(STDOUT, "ProductionMvp14D1DeclinedInviteHistoryTest: {$assertions} assertions passed\n");
