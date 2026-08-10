<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$service = $read('bot/services/GameInviteService.php');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$main = $read('app/assets/js/main-v110.js');
$entry = $read('app/v110.php');

$queuedCancelStart = strpos($invites, 'async function settleQueuedDirectInviteCancel(');
$queuedCancelEnd = $queuedCancelStart === false ? false : strpos($invites, 'async function createLinkDraft(', $queuedCancelStart);
$queuedCancelOwner = $queuedCancelStart === false || $queuedCancelEnd === false
    ? ''
    : substr($invites, $queuedCancelStart, $queuedCancelEnd - $queuedCancelStart);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($service, '$inviteSnapshot = is_array($invite) ? $this->publicInvite($invite, $userId) : null;')
        && str_contains($service, "'invite_snapshot' => \$inviteSnapshot"),
    'Live invite notifications must reuse publicInvite as the complete authoritative first-frame snapshot.'
);
$assert(
    str_contains($notifications, 'data-invite-snapshot=')
        && str_contains($notifications, 'inviteActionSnapshot(item)')
        && str_contains($notifications, 'invite_snapshot:value.invite_snapshot'),
    'Notification action buttons must carry the complete safe invite snapshot into the immediate transition.'
);
$assert(
    str_contains($invites, 'const directInviteCancelIntents = new Set();')
        && str_contains($invites, 'requestPendingDirectInviteCancel(requestGeneration)')
        && str_contains($invites, 'await settleQueuedDirectInviteCancel(currentInvite, requestGeneration);')
        && !str_contains($invites, 'aria-disabled="true" disabled style="opacity:1"'),
    'Direct invite Cancel must be actionable on the first frame and serialized after token creation when necessary.'
);
$assert(
    str_contains($invites, 'const rollbackInvite = inviteForAction(token, button) || cloneInvite(currentInvite);')
        && str_contains($invites, 'function inviteForAction(token, button)')
        && str_contains($invites, "ready_deadline_at:String(rollbackInvite?.ready_deadline_at || '')"),
    'Accept must build its first waiting frame from the notification/public snapshot without a fake deadline.'
);
$assert(
    str_contains($invites, "const optimisticParticipantCancel = action === 'cancel'")
        && str_contains($invites, "const selfCancelledParticipant = action === 'cancel'")
        && str_contains($invites, 'Boolean(terminalInvite?.is_invitee)'),
    'Self-cancel must return both inviter and invitee directly to ordinary activity without a local terminal confirmation sheet.'
);
$assert(
    str_contains($invites, "return formatted === '—' ? 'Ожидаем запуск матча.'")
        && !str_contains($invites, 'new Date(Date.now() + 90000).toISOString()'),
    'Optimistic Accept must not invent a client-side ready deadline while the authoritative response is pending.'
);
$assert(
    str_contains($shell, './games/game-invites-v110.js?v=1137')
        && str_contains($shell, './screens/notifications-screen-v110r12.js?v=1137')
        && str_contains($main, './main-v110-handoff-shell.js?v=1137')
        && str_contains($entry, './assets/js/main-v110.js?v=1137')
        && str_contains($entry, "header('X-MGW-Invite-Graph: v1137');")
        && str_contains($entry, "header('X-MGW-Notification-Graph: v1137');"),
    'The final UX behavior must be published through one immutable v1137 Telegram graph.'
);
$assert(
    $queuedCancelOwner !== ''
        && !str_contains($queuedCancelOwner, 'retry')
        && !str_contains($queuedCancelOwner, 'setTimeout(')
        && !str_contains($queuedCancelOwner, 'setInterval('),
    'The new queued-cancel owner must not use retry, sleep, timeout, or polling workarounds.'
);

fwrite(STDOUT, "ProductionMvp14InviteTransitionUxV1137Test: {$assertions} assertions passed\n");
