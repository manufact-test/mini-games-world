from pathlib import Path


def replace_once(path: str | Path, old: str, new: str) -> None:
    path = Path(path)
    text = path.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{path}: expected one occurrence, found {count}: {old[:160]!r}')
    path.write_text(text.replace(old, new, 1), encoding='utf-8')


screen_path = Path('app/assets/js/screens/notifications-screen-v110r12.js')
replace_once(
    screen_path,
    'let localAuthority = new Map();\nlet announcedIds = loadAnnouncedIds();',
    'let localAuthority = new Map();\nlet consumedInviteTokens = new Set();\nlet announcedIds = loadAnnouncedIds();',
)
replace_once(
    screen_path,
    """    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);
    if (!item.id) return;

    rememberLocalAuthority(item);""",
    """    if (Number.isFinite(unreadCount)) setUnreadCount(unreadCount);
    if (!item.id) return;
    const inviteToken = String(item.invite_token || '');
    if (inviteToken && consumedInviteTokens.has(inviteToken)) return;

    rememberLocalAuthority(item);""",
)
replace_once(
    screen_path,
    """  const exactUnread = Number(detail.unreadCount);
  setUnreadCount(Number.isFinite(exactUnread) ? exactUnread : Math.max(0, unreadHint - 1));""",
    """  const hasExactUnread = detail.unreadCount !== null && detail.unreadCount !== undefined;
  const exactUnread = hasExactUnread ? Number(detail.unreadCount) : Number.NaN;
  setUnreadCount(Number.isFinite(exactUnread) ? exactUnread : Math.max(0, unreadHint - 1));""",
)
replace_once(
    screen_path,
    """  const token = String(detail.inviteToken || detail.token || '');
  if (!token) return;

  removeInviteNotification(detail);
  try {
    const result = await rawNotifications(false, { consumeInviteToken:token });
    const unreadCount = Number(result?.unread_count);
    if (Number.isFinite(unreadCount)) setUnreadCount(Math.max(0, unreadCount));""",
    """  const token = String(detail.inviteToken || detail.token || '');
  if (!token) return;

  consumedInviteTokens.add(token);
  while (consumedInviteTokens.size > MAX_ANNOUNCED_IDS) {
    consumedInviteTokens.delete(consumedInviteTokens.values().next().value);
  }
  removeInviteNotification(detail);
  try {
    const result = await rawNotifications(false, { consumeInviteToken:token });
    const unreadCount = Number(result?.unread_count);
    removeInviteNotification({
      inviteToken:token,
      unreadCount:Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : null,
    });""",
)

endpoint_path = Path('bot/notifications.php')
replace_once(
    endpoint_path,
    """    $token = trim($token);
    if ($token === '') return;
    $now = now_iso();
    foreach ($data['notifications'] ?? [] as &$notification) {""",
    """    $token = trim($token);
    if (!preg_match('/^[A-Za-z0-9_-]{12,80}$/', $token)) return;
    if (!isset($data['notifications']) || !is_array($data['notifications'])) return;
    $now = now_iso();
    foreach ($data['notifications'] as &$notification) {""",
)

contract_path = Path('bot/tests/ProductionMvp14D2TerminalDedupSelfCancelContractTest.php')
replace_once(
    contract_path,
    """        && str_contains($notifications, 'removedIds')
        && str_contains($notifications, 'persistAnnouncedIds();'),""",
    """        && str_contains($notifications, 'let consumedInviteTokens = new Set();')
        && str_contains($notifications, 'consumedInviteTokens.has(inviteToken)')
        && str_contains($notifications, 'removedIds')
        && str_contains($notifications, 'persistAnnouncedIds();'),""",
)
replace_once(
    contract_path,
    """        && str_contains($endpoint, "if (empty($notification['hidden_at'])) $notification['hidden_at'] = $now;")
        && str_contains($endpoint, "$consumeInviteToken = trim((string)($payload['consumeInviteToken'] ?? ''));")""",
    """        && str_contains($endpoint, "if (!isset($data['notifications']) || !is_array($data['notifications'])) return;")
        && str_contains($endpoint, "foreach ($data['notifications'] as &$notification)")
        && str_contains($endpoint, "if (empty($notification['hidden_at'])) $notification['hidden_at'] = $now;")
        && str_contains($endpoint, "$consumeInviteToken = trim((string)($payload['consumeInviteToken'] ?? ''));")""",
)
