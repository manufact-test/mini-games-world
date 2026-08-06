<?php
declare(strict_types=1);

// Exact D2 terminal-surface ownership and targeted notification-consume contract.
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$endpoint = $read('bot/notifications.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$e2e = $read('e2e/staging/invite-terminal-dedup.spec.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($invites, "const selfCancelledOwner = action === 'cancel'")
        && str_contains($invites, 'consumeInviteNotification(token, unreadCount);')
        && str_contains($invites, "closeSheet();\n        showScreen('home');"),
    'An owner cancelling their own invitation must return directly home without a second terminal sheet.'
);
$assert(
    str_contains($invites, 'consumeInviteNotification(currentInvite.token);')
        && str_contains($invites, 'mgw:notification-consume-invite'),
    'A terminal result already visible in the invite sheet must consume its duplicate notification.'
);
$assert(
    str_contains($notifications, 'mgw:notification-consume-invite')
        && str_contains($notifications, "consumeInviteToken:String(options.consumeInviteToken || '')")
        && str_contains($notifications, 'let consumedInviteTokens = new Set();')
        && str_contains($notifications, 'consumedInviteTokens.has(inviteToken)')
        && str_contains($notifications, 'visibleServerItems')
        && str_contains($notifications, '!consumedInviteTokens.has(token)')
        && str_contains($notifications, 'removedIds')
        && str_contains($notifications, 'persistAnnouncedIds();'),
    'The canonical notification owner must remove the local duplicate immediately and request one targeted server consume.'
);
$assert(
    str_contains($endpoint, 'function mgw_consume_invite_notifications')
        && str_contains($endpoint, 'if (!isset($data[\'notifications\']) || !is_array($data[\'notifications\'])) return;')
        && str_contains($endpoint, 'foreach ($data[\'notifications\'] as &$notification)')
        && str_contains($endpoint, 'if (empty($notification[\'hidden_at\'])) $notification[\'hidden_at\'] = $now;')
        && str_contains($endpoint, '$consumeInviteToken = trim((string)($payload[\'consumeInviteToken\'] ?? \'\'));')
        && str_contains($endpoint, '} elseif ($consumeInviteToken !== \'\') {')
        && str_contains($endpoint, 'mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);'),
    'The server must hide only the matching invite notification for the authenticated actor through a branch separate from markRead.'
);
$assert(
    str_contains($shell, './screens/notifications-screen-v110r12.js?v=1133')
        && str_contains($shell, './games/game-invites-v110.js?v=1133'),
    'Both corrected owners must publish under fresh v1133 identities.'
);
$assert(
    str_contains($e2e, 'remote decline already visible in owner sheet is not repeated as toast or bell card')
        && str_contains($e2e, 'owner self-cancel returns directly home without terminal confirmation'),
    'Live staging coverage must prove both exact user scenarios.'
);

fwrite(STDOUT, "ProductionMvp14D2TerminalDedupSelfCancelContractTest: {$assertions} assertions passed\n");
