from pathlib import Path


def replace_once(path: str | Path, old: str, new: str) -> None:
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one occurrence, found {count}: {old[:180]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')


screen_path = Path('app/assets/js/screens/notifications-screen-v110r12.js')
replace_once(
    screen_path,
    """    const unreadCount = Number(event.detail?.unreadCount || 0);
    const announce = event.detail?.announce !== false;
    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);
    if (!item.id) return;
    const inviteToken = String(item.invite_token || '');
    if (inviteToken && consumedInviteTokens.has(inviteToken)) return;

    rememberLocalAuthority(item);""",
    """    const unreadCount = Number(event.detail?.unreadCount || 0);
    const announce = event.detail?.announce !== false;
    if (!item.id) return;
    const inviteToken = String(item.invite_token || '');
    if (inviteToken && consumedInviteTokens.has(inviteToken)) return;
    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);

    rememberLocalAuthority(item);""",
)
replace_once(
    screen_path,
    """function mergeServerItems(serverItems){
  pruneLocalAuthority();
  const preserved = [...localAuthority.values()].map(entry => entry.item);
  const merged = mergeNotificationItems(preserved, serverItems);""",
    """function mergeServerItems(serverItems){
  pruneLocalAuthority();
  const preserved = [...localAuthority.values()].map(entry => entry.item);
  const visibleServerItems = normalizeItems(serverItems).filter(item => {
    const token = String(item.invite_token || '');
    return !token || !consumedInviteTokens.has(token);
  });
  const merged = mergeNotificationItems(preserved, visibleServerItems);""",
)
replace_once(
    screen_path,
    '// The local surface is already consumed; a later authoritative refresh retries parity.',
    '// Keep this token consumed in the current document; normal refreshes cannot reinsert it.',
)

endpoint_path = Path('bot/notifications.php')
replace_once(
    endpoint_path,
    """if ($markRead || $consumeInviteToken !== '') {
    $db->transaction(function (array &$data) use (
        $notifications,
        $userId,
        $markRead,
        $consumeInviteToken
    ): void {
        if ($markRead) {
            $notifications->markAllRead($data, $userId);
        } else {
            mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
        }
    });
}

        $runtimeNotifications""",
    """        if ($markRead || $consumeInviteToken !== '') {
            $db->transaction(function (array &$data) use (
                $notifications,
                $userId,
                $markRead,
                $consumeInviteToken
            ): void {
                if ($markRead) {
                    $notifications->markAllRead($data, $userId);
                } else {
                    mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
                }
            });
        }

        $runtimeNotifications""",
)
replace_once(
    endpoint_path,
    """} elseif ($markRead || $consumeInviteToken !== '') {
    $result = $db->transaction(function (array &$data) use (
        $notifications,
        $userId,
        $markRead,
        $consumeInviteToken
    ): array {
        if ($markRead) {
            $notifications->markAllRead($data, $userId);
        } else {
            mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
        }
        return [
            'items' => mgw_visible_notifications($data, $notifications, $userId, 30),
            'unread_count' => mgw_visible_unread_count($data, $userId),
        ];
    });
    } else {""",
    """    } elseif ($markRead || $consumeInviteToken !== '') {
        $result = $db->transaction(function (array &$data) use (
            $notifications,
            $userId,
            $markRead,
            $consumeInviteToken
        ): array {
            if ($markRead) {
                $notifications->markAllRead($data, $userId);
            } else {
                mgw_consume_invite_notifications($data, $userId, $consumeInviteToken);
            }
            return [
                'items' => mgw_visible_notifications($data, $notifications, $userId, 30),
                'unread_count' => mgw_visible_unread_count($data, $userId),
            ];
        });
    } else {""",
)

contract_path = Path('bot/tests/ProductionMvp14D2TerminalDedupSelfCancelContractTest.php')
replace_once(
    contract_path,
    """        && str_contains($notifications, 'consumedInviteTokens.has(inviteToken)')
        && str_contains($notifications, 'removedIds')""",
    """        && str_contains($notifications, 'consumedInviteTokens.has(inviteToken)')
        && str_contains($notifications, 'visibleServerItems')
        && str_contains($notifications, '!consumedInviteTokens.has(token)')
        && str_contains($notifications, 'removedIds')""",
)
