<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$screen = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$endpoint = $read('bot/notifications.php');
$service = $read('bot/services/GameInviteService.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$scenario = $read('e2e/staging/notification-owner-cancel-copy.spec.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$start = strpos($screen, 'function terminalNotificationFallback(');
$end = $start === false ? false : strpos($screen, 'function formatDate(', $start);
$fallback = $start === false || $end === false ? '' : substr($screen, $start, $end - $start);

$assert(
    $fallback !== ''
        && str_contains($fallback, "? 'Вы отменили своё приглашение.'")
        && str_contains($fallback, ": 'Вы отменили участие в матче.'")
        && str_contains($fallback, "if (status === 'declined') return 'Вы отклонили приглашение.';"),
    'Empty local terminal messages must describe the authenticated actor self-action exactly.'
);
$assert(
    !str_contains($fallback, 'inviterName')
        && !str_contains($fallback, 'inviteeName')
        && !str_contains($fallback, 'gameTitle'),
    'The empty-message fallback must not guess a remote actor from participant context.'
);
$assert(
    str_contains($screen, 'if (!message) return terminalNotificationFallback(item);'),
    'Only empty terminal copy may use the self-action fallback.'
);
$assert(
    str_contains($endpoint, "\$type === 'invite_cancelled'")
        && str_contains($endpoint, "\$item['message'] = \$inviterCancelled")
        && str_contains($service, 'private function liveInviteMessage')
        && str_contains($service, "(string)(\$notification['type'] ?? '') !== 'invite_cancelled'"),
    'Remote cancelled cards must keep their existing authoritative non-empty server/live contextual message.'
);
$assert(
    str_contains($shell, "notifications-screen-v110r12.js?v=1134&selfcopy=1"),
    'The corrected canonical notification owner must publish through a fresh immutable browser path.'
);
$assert(
    str_contains($scenario, ".toHaveText('Вы отменили своё приглашение.')"),
    'Live staging coverage must retain the exact owner self-cancel acceptance.'
);

fwrite(STDOUT, "ProductionMvp14NotificationSelfTerminalCopyBoundaryTest: {$assertions} assertions passed\n");
