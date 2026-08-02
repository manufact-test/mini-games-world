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
    'Terminal actions must identify which visible surface owns the clicked action.'
);
$assert(
    str_contains($actions, "sheet.querySelector('[data-notifications-owner=\"r12\"]')")
        && str_contains($actions, 'if (notificationSurface) {')
        && str_contains($actions, 'detail:{ item, unreadCount, announce:false }'),
    'A decline inside the notification center must update the existing card without announcing another toast.'
);
$assert(
    str_contains($actions, "} else {\n      closeSheet();\n    }")
        && !str_contains($actions, "toast('Приглашение отклонено.')"),
    'A decline inside the standalone invitation sheet must close silently instead of rendering a terminal confirmation sheet.'
);
$assert(
    str_contains($actions, "message:'Приглашение больше недоступно.'")
        && !str_contains($actions, 'Вы отклонили это приглашение.')
        && !str_contains($actions, 'Вы отменили это приглашение.'),
    'The retained notification card must describe terminal state without a redundant self-confirmation message.'
);
$assert(
    str_contains($notifications, 'applyInviteActionResult')
        && str_contains($invites, "if (action === 'decline') toast('Приглашение отклонено.');"),
    'The R12 capture owner must remain responsible for terminal actions while the older handler stays blocked behind it.'
);

fwrite(STDOUT, "ProductionV110InviteTerminalSurfaceContractTest: {$assertions} assertions passed\n");
