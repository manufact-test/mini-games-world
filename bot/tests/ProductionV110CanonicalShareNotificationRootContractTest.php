<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read R8 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r5.js');
$endpoint = $read('bot/invites.php');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$entry = $read('app/v110.php');
$clean = $read('app/assets/js/production-clean-entry-v110.js');

$assert(
    str_contains($invites, "document.addEventListener('pointerdown', handleInvitePointerDown, true)")
        && str_contains($invites, 'function warmShareDraft(context)')
        && str_contains($invites, "inviteRequest('create_link_draft', normalized, { prefetch:true })")
        && str_contains($invites, 'shareWarmSerial = shareWarmSerial'),
    'The canonical invitation owner must prewarm one serialized prepared-message draft before the share click.'
);
$assert(
    str_contains($invites, 'tg.shareMessage(preparedId')
        && str_contains($invites, "tg.onEvent('shareMessageSent'")
        && str_contains($invites, "tg.onEvent('shareMessageFailed'")
        && !str_contains($invites, 'showSharingSheet(')
        && !str_contains($invites, 'Ждём результата отправки')
        && !str_contains($invites, 'Отправка отменена'),
    'Sharing must keep the native editable prepared-message dialog while leaving the existing Mini App setup surface untouched.'
);
$assert(
    str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
        && str_contains($invites, 'void discardDraft(attempt.invite).finally')
        && !str_contains($invites, "toast(sent === false"),
    'Native cancellation must silently discard its draft without a technical toast or waiting surface.'
);
$watchStart = strpos($invites, 'async function watchIncomingInvite()');
$watchEnd = strpos($invites, 'function canWatchIncomingInvite()', $watchStart ?: 0);
$watchBlock = $watchStart !== false && $watchEnd !== false
    ? substr($invites, $watchStart, $watchEnd - $watchStart)
    : '';
$assert(
    str_contains($invites, 'announceLinkedInviteNotification(result, token);')
        && !str_contains($invites, 'if (currentInvite?.token) openCurrentInvite();')
        && str_contains($watchBlock, 'currentInvite = invite;')
        && str_contains($watchBlock, 'dispatchNotificationCount(result.unread_count);')
        && !str_contains($watchBlock, 'showIncomingInvite(invite);'),
    'Incoming link and live invites must enter through the notification owner instead of forcing the invitation sheet open.'
);
$openLinkStart = strpos($endpoint, "case 'open_link':");
$openLinkEnd = strpos($endpoint, "case 'sync':", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(
    str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false)')
        && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
        && !str_contains($openLinkBlock, '$invites->markSeen('),
    'Opening a shared link must create an unread authoritative invitation notification and return that event to the client.'
);
$assert(
    str_contains($notifications, 'let notificationAuthorityRevision = 0;')
        && str_contains($notifications, 'const authorityRevision = notificationAuthorityRevision;')
        && str_contains($notifications, 'if (authorityRevision !== notificationAuthorityRevision)')
        && str_contains($notifications, 'setUnreadCount(Math.max(unreadHint, Number(result?.unread_count || 0)))'),
    'A notification event must not be erased by an older badge request that started before it arrived.'
);
$assert(
    str_contains($notifications, 'function isInviteAlreadyPresented(item)')
        && str_contains($notifications, 'if (item && isInviteAlreadyPresented(item))')
        && str_contains($notifications, 'rememberAnnouncedId(String(item.id || \'\'));'),
    'An invitation already visible in its canonical sheet must not reappear as a duplicate blue toast.'
);
$assert(
    str_contains($notifications, 'void openNotificationsSheet([item], true, true);')
        && str_contains($notifications, 'setSheetSeed(generation, seedItems, preserveSeed)')
        && str_contains($notifications, 'reconcileItems(mergeNotificationItems(sheetSeedItems(generation), serverItems))'),
    'Clicking a real blue toast must paint and retain that exact notification while the bell refreshes.'
);
$assert(
    !str_contains($clean, 'initV109ShareSpeed')
        && !str_contains($clean, 'initV109ShareFallbackGuard')
        && str_contains($shell, 'game-invites-v110.js?v=1112')
        && str_contains($shell, 'notifications-screen-v110r5.js?v=1112')
        && str_contains($entry, 'main-v110.js?v=1112'),
    'No historical share layer may return; only the fresh canonical R8 owners may be active.'
);

fwrite(STDOUT, "ProductionV110CanonicalShareNotificationRootContractTest: {$assertions} assertions passed\n");
