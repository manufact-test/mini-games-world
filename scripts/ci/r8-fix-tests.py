from pathlib import Path


def replace_exact(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise RuntimeError(f"Expected block not found in {path}")
    file.write_text(text.replace(old, new, 1))


replace_exact(
    'bot/tests/Mvp14r2InviteMatchmakingContractTest.php',
    """$assert(str_contains($endpoint, '$invites->bindFromLink($data, $user, $token, true, true)')
    && str_contains($endpoint, '$invites->markSeen($data, $userId, $token)'), 'Link opening must bind, hide and mark the received invite event.');""",
    """$openLinkStart = strpos($endpoint, \"case 'open_link':\");
$openLinkEnd = strpos($endpoint, \"case 'sync':\", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false)')
    && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
    && !str_contains($openLinkBlock, '$invites->markSeen('),
    'Link opening must preserve and return the unread invite notification.');""",
)

replace_exact(
    'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php',
    """$assert(
    str_contains($invites, 'announceLinkedInviteNotification(result, token);')
        && !str_contains($invites, 'if (currentInvite?.token) openCurrentInvite();')
        && str_contains($invites, 'currentInvite = invite;\\n    dispatchNotificationCount(result.unread_count);')
        && !str_contains($invites, 'currentInvite = invite;\\n    showIncomingInvite(invite);'),
    'Incoming link and live invites must enter through the notification owner instead of forcing the invitation sheet open.'
);""",
    """$watchStart = strpos($invites, 'async function watchIncomingInvite()');
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
);""",
)

replace_exact(
    'bot/tests/ProductionV110CanonicalShareNotificationRootContractTest.php',
    """$assert(
    str_contains($endpoint, '$invites->bindFromLink($data, $user, $token, true, false)')
        && str_contains($endpoint, '$core = $invites->sync($data, $user, $token);')
        && !str_contains($endpoint, \"$invites->markSeen($data, $userId, $token);\"),
    'Opening a shared link must create an unread authoritative invitation notification and return that event to the client.'
);""",
    """$openLinkStart = strpos($endpoint, \"case 'open_link':\");
$openLinkEnd = strpos($endpoint, \"case 'sync':\", $openLinkStart ?: 0);
$openLinkBlock = $openLinkStart !== false && $openLinkEnd !== false
    ? substr($endpoint, $openLinkStart, $openLinkEnd - $openLinkStart)
    : '';
$assert(
    str_contains($openLinkBlock, '$invites->bindFromLink($data, $user, $token, true, false)')
        && str_contains($openLinkBlock, '$core = $invites->sync($data, $user, $token);')
        && !str_contains($openLinkBlock, '$invites->markSeen('),
    'Opening a shared link must create an unread authoritative invitation notification and return that event to the client.'
);""",
)

replace_exact(
    'bot/tests/ProductionV110PvpResultNotificationRootContractTest.php',
    "$toastOpen = strpos($notifications, 'void openNotificationsSheet(mergeNotificationItems([item], currentItems()), true);', $toastStart ?: 0);",
    "$toastOpen = strpos($notifications, 'void openNotificationsSheet([item], true, true);', $toastStart ?: 0);",
)
