<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$endpoint = $read('bot/invites.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');
$clean = $read('app/assets/js/production-clean-entry-v110.js');

$assert(str_contains($invites, "document.addEventListener('pointerdown', handleInvitePointerDown, true)")
    && str_contains($invites, 'function warmShareDraft(context)')
    && str_contains($invites, "inviteRequest('create_link_draft', { ...normalized, prepareMessage:true }, { prefetch:true })"),
    'The canonical invitation owner must keep serialized share prewarm.');
$assert(str_contains($invites, 'tg.shareMessage(preparedId')
    && str_contains($invites, "tg.onEvent('shareMessageSent'")
    && str_contains($invites, "tg.onEvent('shareMessageFailed'")
    && !str_contains($invites, 'showSharingSheet(')
    && !str_contains($invites, 'Ждём результата отправки'),
    'Sharing must keep the native editable Telegram dialog.');
$assert(str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
    && str_contains($invites, 'restoreWarmShareDraft(attempt);')
    && !str_contains($invites, 'void discardDraft(attempt.invite).finally'),
    'Native cancellation must silently reuse the prepared draft.');

$watchStart = strpos($invites, 'async function watchIncomingInvite()');
$watchEnd = strpos($invites, 'function canWatchInviteSignal()', $watchStart ?: 0);
$watchBlock = $watchStart !== false && $watchEnd !== false ? substr($invites, $watchStart, $watchEnd - $watchStart) : '';
$assert(str_contains($watchBlock, 'scheduleSync(0);')
    && str_contains($watchBlock, 'return invite;')
    && !str_contains($watchBlock, 'showIncomingInvite(invite);')
    && !str_contains($watchBlock, 'currentInvite = invite;'),
    'Low-latency invite watch must wake the canonical invite sync owner instead of becoming a second UI/state owner.');

$openLinkStart = strpos($endpoint, "case 'open_link':");
$openLinkEnd = strpos($endpoint, "case 'sync':", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart) : '';
$assert(str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false)')
    && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
    && !str_contains($openLinkBlock, '$invites->markSeen('),
    'Opening a shared link must return an unread authoritative invite event.');

$assert(str_contains($notifications, 'LOCAL_AUTHORITY_MS = 12000')
    && str_contains($notifications, 'mergeServerItems(serverItems)')
    && str_contains($notifications, 'rememberLocalAuthority(item)')
    && str_contains($notifications, 'sheetState.pinned'),
    'A stale badge response must not erase a fresh notification.');
$assert(str_contains($notifications, "openNotificationsSheet({ seed:[item], source:'toast' })")
    && str_contains($notifications, 'renderNotifications(immediate);')
    && str_contains($notifications, 'visibleSheetItems()'),
    'A blue-toast click must paint the exact item before refresh.');
$assert(str_contains($notifications, 'CLOSE_GUARD_MS = 1100')
    && str_contains($notifications, 'armCloseGuard()')
    && str_contains($notifications, 'announcementGuardUntil'),
    'Closing notifications must suppress duplicate reopening and re-announcement.');

$assert(!str_contains($clean, 'initV109ShareSpeed')
    && !str_contains($clean, 'initV109ShareFallbackGuard')
    && str_contains($shell, "from './games/game-invites-v110.js?v=1137&ux=1'")
    && str_contains($shell, "from './screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle'")
    && !str_contains($shell, 'notifications-screen-v110r5.js')
    && str_contains($entry, 'runtime/client/version-manifest.php')
    && str_contains($entry, 'X-MGW-Client-Bootstrap: v2-single-owner'),
    'The v110 shell must retain one canonical invite owner, one accepted notification owner and the manifest-driven single bootstrap.');

fwrite(STDOUT, "ProductionV110CanonicalShareNotificationRootContractTest: {$assertions} assertions passed\n");
