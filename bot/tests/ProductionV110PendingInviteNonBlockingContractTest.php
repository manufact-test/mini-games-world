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

$creation = $read('bot/services/invites/GameInviteCreationTrait.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');
$watch = $read('bot/invite-watch.php');
$endpoint = $read('bot/invites.php');
$notifications = $read('bot/notifications.php');
$client = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(
    str_contains($creation, '$activeInvite = $this->activeForUser($db, $userId);')
        && str_contains($creation, 'if ($this->isNotificationOnlyPendingInvite($activeInvite)) $activeInvite = null;')
        && str_contains($creation, '$openedInvite = null;')
        && str_contains($creation, 'if ($this->isNotificationOnlyPendingInvite($candidate))')
        && str_contains($creation, '$openedInvite = $candidate;')
        && str_contains($creation, '$trackedInvite = $candidate;')
        && str_contains($creation, "'opened_invite' => \$openedInvite"),
    'Pending received invitations must stay out of current/tracked state while a tracked Telegram token may expose one one-shot opened_invite payload.'
);
$assert(
    str_contains($creation, "'invite_events' => \$this->inviteEventsForUser(\$db, \$userId)")
        && str_contains($creation, "'unread_count' => \$this->unreadNotificationCount(\$db, \$userId)"),
    'The same pending invitation must remain available through notifications and unread state.'
);
$assert(
    str_contains($creation, "(string)(\$invite['status'] ?? '') === 'pending'")
        && str_contains($creation, "!empty(\$invite['is_invitee'])")
        && str_contains($creation, "empty(\$invite['is_owner'])"),
    'Only received pending invitations may become notification-only; owner-side and accepted states remain active.'
);
$assert(
    str_contains($watch, "'invite' => null")
        && str_contains($watch, "'notification_pending' => \$pending")
        && str_contains($watch, 'notification-only')
        && !str_contains($watch, "'invite' => \$invite"),
    'The fast signal endpoint must not reintroduce a received pending invitation as currentInvite.'
);
$openLinkStart = strpos($endpoint, "case 'open_link':");
$openLinkEnd = strpos($endpoint, "case 'sync':", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(
    str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false);')
        && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
        && !str_contains($openLinkBlock, '$core[\'invite\'] = $boundInvite;')
        && !str_contains($openLinkBlock, '$boundInvite =')
        && str_contains($linkEntry, 'const invite = result?.opened_invite || null;')
        && !str_contains($linkEntry, 'currentInvite ='),
    'Opening a Telegram link may paint one opened_invite sheet but must not restore current or tracked client invite state.'
);
$assert(
    str_contains($notifications, "return in_array(\$status, ['pending', 'accepted', 'declined'], true);")
        && str_contains($notifications, "if (\$status === 'pending' && \$invitee) return ['accept', 'decline'];")
        && !str_contains($notifications, "if (\$status === 'declined' && \$invitee) return ['accept', 'decline'];"),
    'Notification actions must remain available only while pending; a later declined card is read history without actions.'
);
$assert(
    str_contains($actions, '$this->assertAvailableForStart($db, $invitee, $token, \'Сначала завершите текущий поиск или игру.\');')
        && str_contains($actions, '$this->assertAvailableForStart($db, $inviter, $token, \'Пригласивший игрок сейчас занят в другой игре.\');'),
    'Accepting later must still fail safely if either player is searching or playing.'
);
$assert(
    str_contains($client, "document.addEventListener('mgw:before-game-launch'")
        && str_contains($client, 'if (hasActionableInvite())'),
    'The existing launch guard remains for accepted and owner-side invite states after server filtering.'
);

fwrite(STDOUT, "ProductionV110PendingInviteNonBlockingContractTest: {$assertions} assertions passed\n");
