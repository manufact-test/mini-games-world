<?php
declare(strict_types=1);

// Exact recipient active-to-terminal replacement contract. Keep this focused
// on one invite token, terminal priority, contextual copy and clean publication.
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

$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$endpoint = $read('bot/notifications.php');
$service = $read('bot/services/GameInviteService.php');
$entry = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$regressionEntry = $read('app/assets/js/production-clean-entry-v110.js');
$e2e = $read('e2e/staging/invite-recipient-terminal-replacement.spec.mjs');

$assert(
    str_contains($notifications, "if (token && type.startsWith('invite_')) return token;")
        && !str_contains($notifications, 'return `${token}|${type}`;'),
    'All visible states of one invitation must share the exact invite-token identity.'
);
$assert(
    str_contains($notifications, 'function mergeEquivalentNotification')
        && str_contains($notifications, 'function isTerminalInviteNotification')
        && str_contains($notifications, 'mergeEquivalentNotification(existing, item)')
        && str_contains($notifications, 'mergeEquivalentNotification(previous, value)')
        && str_contains($notifications, 'notificationIdentity(candidate) === identity')
        && str_contains($notifications, 'for (const item of normalizeItems(parsed.items)) upsert(item);')
        && str_contains($notifications, 'if (isTerminalInviteNotification(item)) return [];'),
    'Terminal invite state must replace active memory, pinned, server and hydrated-cache cards and remove stale actions.'
);
$assert(
    str_contains($endpoint, "\$type === 'invite_cancelled'")
        && str_contains($endpoint, "\$inviterName . ' отменил приглашение сыграть в «'")
        && str_contains($endpoint, "\$item['inviter_name']")
        && str_contains($endpoint, "\$item['invitee_name']")
        && str_contains($endpoint, "\$item['invite_is_owner']"),
    'The notification endpoint must derive cancelled actor, game and participant context from the authoritative invite.'
);
$assert(
    str_contains($service, 'private function liveInviteMessage')
        && str_contains($service, "'inviter_name' =>")
        && str_contains($service, "'invitee_name' =>")
        && str_contains($service, "'game_title' =>")
        && str_contains($service, "' отменил приглашение сыграть в «'"),
    'Live invite events must carry the same contextual terminal message before a bell refresh.'
);
$assert(
    str_contains($entry, './assets/js/main-v110.js?v=1134')
        && str_contains($entry, 'X-MGW-Notification-Graph: v1134')
        && str_contains($entry, 'v110-mvp14r12-recipient-terminal-v1134')
        && str_contains($main, './main-v110-handoff-shell.js?v=1134')
        && str_contains($shell, './screens/notifications-screen-v110r12.js?v=1134')
        && str_contains($shell, './games/game-invites-v110.js?v=1133')
        && str_contains($regressionEntry, 'v110-mvp14r12-recipient-terminal-v1134'),
    'Only the corrected notification publication must move to fresh v1134 while the invite owner remains v1133.'
);
$assert(
    str_contains($e2e, 'recipient bell replaces active invite with one contextual cancelled terminal card')
        && str_contains($e2e, "toHaveText('Приглашение отменено')")
        && str_contains($e2e, "expect(terminalMessage).toContain(inviterName)")
        && str_contains($e2e, "expect(terminalMessage).toContain('Крестики-нолики')")
        && str_contains($e2e, "terminalCards).toHaveCount(1")
        && str_contains($e2e, "terminalCard.locator('.invite-actions')).toHaveCount(0)"),
    'Live mobile coverage must prove one exact-token terminal card without buttons and with inviter and game context.'
);

fwrite(STDOUT, "ProductionMvp14D2RecipientTerminalReplacementContextContractTest: {$assertions} assertions passed\n");
