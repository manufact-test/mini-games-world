from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file_path = Path(path)
    text = file_path.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one anchor, found {count}")
    file_path.write_text(text.replace(old, new, 1), encoding="utf-8")


def append_once(path: str, marker: str, block: str) -> None:
    file_path = Path(path)
    text = file_path.read_text(encoding="utf-8")
    if marker in text:
        raise SystemExit(f"{path}: marker already exists")
    file_path.write_text(text.rstrip() + "\n\n" + block.strip() + "\n", encoding="utf-8")


notifications_path = "app/assets/js/screens/notifications-screen-v110r12.js"
replace_once(
    notifications_path,
    """function notificationIdentity(item){
  const token = String(item?.invite_token || '');
  const type = String(item?.type || '');
  if (token && type.startsWith('invite_')) return `${token}|${type}`;
  return String(item?.id || '');
}""",
    """function notificationIdentity(item){
  const token = String(item?.invite_token || '');
  const type = String(item?.type || '');
  if (token && type.startsWith('invite_')) return token;
  return String(item?.id || '');
}""",
)
replace_once(
    notifications_path,
    "  const merged = normalizeItem({ ...existing, ...item });",
    """  const merged = mergeEquivalentNotification(existing, item);
  const identity = notificationIdentity(merged);
  if (identity) {
    for (const [id, candidate] of items.entries()) {
      if (id !== merged.id && notificationIdentity(candidate) === identity) items.delete(id);
    }
  }""",
)
replace_once(
    notifications_path,
    "    merged.set(identity, normalizeItem({ ...previous, ...value }));",
    "    merged.set(identity, mergeEquivalentNotification(previous, value));",
)
replace_once(
    notifications_path,
    """function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id);
}""",
    """function mergeEquivalentNotification(previousValue, incomingValue){
  const previous = normalizeItem(previousValue);
  const incoming = normalizeItem(incomingValue);
  if (!previous.id) return incoming;
  if (!incoming.id) return previous;

  const sameInvite = previous.invite_token
    && previous.invite_token === incoming.invite_token
    && String(previous.type || '').startsWith('invite_')
    && String(incoming.type || '').startsWith('invite_');
  if (sameInvite && isTerminalInviteNotification(previous) && !isTerminalInviteNotification(incoming)) {
    return normalizeItem({ ...incoming, ...previous });
  }
  return normalizeItem({ ...previous, ...incoming });
}

function isTerminalInviteNotification(item){
  const status = String(item?.invite_status || '');
  const type = String(item?.type || '');
  return ['cancelled','canceled','declined','expired','timed_out'].includes(status)
    || ['invite_cancelled','invite_declined','invite_expired','invite_timed_out'].includes(type);
}

function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id);
}""",
)

replace_once(
    "bot/services/invites/GameInviteActionTrait.php",
    """            $message = $isOwner
                ? 'Матч «' . (string)($invite['game_title'] ?? 'Игра') . '» не начался.'
                : (string)($invite['invitee_name'] ?? 'Игрок') . ' отменил участие в матче.';""",
    """            $message = $isOwner
                ? (string)($invite['inviter_name'] ?? 'Игрок')
                    . ' отменил приглашение сыграть в «'
                    . (string)($invite['game_title'] ?? 'Игра') . '».'
                : (string)($invite['invitee_name'] ?? 'Игрок')
                    . ' отменил участие в матче «'
                    . (string)($invite['game_title'] ?? 'Игра') . '».';""",
)

replace_once(
    "bot/notifications.php",
    """    $type = (string)($item['type'] ?? '');
    if (!mgw_notification_is_received_type($type)) return $item;

    $status = (string)($invite['status'] ?? '');
    if ($status === 'accepted') {""",
    """    $type = (string)($item['type'] ?? '');
    $status = (string)($invite['status'] ?? '');

    if ($type === 'invite_cancelled' && in_array($status, ['cancelled', 'canceled'], true)) {
        $inviterId = (string)($invite['inviter_id'] ?? '');
        $inviteeId = (string)($invite['invitee_id'] ?? '');
        $cancelledBy = (string)($invite['cancelled_by'] ?? '');
        $inviterName = trim((string)($invite['inviter_name'] ?? 'Игрок')) ?: 'Игрок';
        $inviteeName = trim((string)($invite['invitee_name'] ?? 'Игрок')) ?: 'Игрок';
        $gameTitle = trim((string)($invite['game_title'] ?? 'Игра')) ?: 'Игра';
        $inviterCancelled = $cancelledBy !== ''
            ? $cancelledBy === $inviterId
            : $userId === $inviteeId;

        $item['title'] = $inviterCancelled ? 'Приглашение отменено' : 'Соперник отменил участие';
        $item['message'] = $inviterCancelled
            ? $inviterName . ' отменил приглашение сыграть в «' . $gameTitle . '».'
            : $inviteeName . ' отменил участие в матче «' . $gameTitle . '».';
        $item['tone'] = 'warning';
        $item['created_at'] = (string)($invite['cancelled_at'] ?? $invite['updated_at'] ?? $item['created_at'] ?? '');
        return $item;
    }

    if (!mgw_notification_is_received_type($type)) return $item;

    if ($status === 'accepted') {""",
)
replace_once(
    "bot/notifications.php",
    """            $item['invite_status'] = (string)($invite['status'] ?? '');
            $item['game_title'] = (string)($invite['game_title'] ?? '');""",
    """            $item['invite_status'] = (string)($invite['status'] ?? '');
            $item['game_title'] = (string)($invite['game_title'] ?? '');
            $item['inviter_name'] = (string)($invite['inviter_name'] ?? '');
            $item['invitee_name'] = (string)($invite['invitee_name'] ?? '');""",
)

replace_once(
    "app/v110.php",
    "./assets/js/main-v110.js?v=1133",
    "./assets/js/main-v110.js?v=1134",
)
replace_once(
    "app/v110.php",
    "v110-mvp14r12-terminal-dedup-v1133",
    "v110-mvp14r12-recipient-terminal-v1134",
)
replace_once(
    "app/v110.php",
    "X-MGW-Notification-Graph: v1133",
    "X-MGW-Notification-Graph: v1134",
)
replace_once(
    "app/assets/js/main-v110.js",
    "v110-mvp14r12-terminal-dedup-v1133",
    "v110-mvp14r12-recipient-terminal-v1134",
)
replace_once(
    "app/assets/js/main-v110.js",
    "./main-v110-handoff-shell.js?v=1133",
    "./main-v110-handoff-shell.js?v=1134",
)
replace_once(
    "app/assets/js/main-v110-handoff-shell.js",
    "v110-mvp14r12-terminal-dedup-v1133",
    "v110-mvp14r12-recipient-terminal-v1134",
)
replace_once(
    "app/assets/js/main-v110-handoff-shell.js",
    "./screens/notifications-screen-v110r12.js?v=1133",
    "./screens/notifications-screen-v110r12.js?v=1134",
)

append_once(
    "e2e/staging/invite-terminal-dedup.spec.mjs",
    "recipient bell replaces active invite with one contextual cancelled terminal card",
    r"""
test('recipient bell replaces active invite with one contextual cancelled terminal card', async ({ browser }) => {
  test.setTimeout(180_000);
  const players = await createPlayers(browser);
  try {
    const token = await createDirectInvite(players.playerA.page);

    await players.playerB.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await players.playerB.page.locator('#notificationsOpen').click();
    await expect(players.playerB.page.locator('#sheet .sheet-head h2')).toHaveText(
      'Уведомления',
      { timeout: 30_000 },
    );
    const activeCard = players.playerB.page.locator(
      `#sheet [data-notification-invite-token="${token}"]`,
    );
    await expect(activeCard).toHaveCount(1, { timeout: 30_000 });
    await expect(activeCard.locator('.invite-actions')).toHaveCount(1);
    const activeMessage = String(await activeCard.locator('p').textContent() || '').trim();
    const inviterName = activeMessage.split(' приглашает')[0].trim();
    expect(inviterName).not.toBe('');

    await players.playerB.page.locator('#sheet [data-close-sheet]').click();
    await expect(players.playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/);

    await syncOwnerInvite(players.playerA.page, token);
    const cancelled = await clickInviteAction(players.playerA.page, 'cancel', token);
    expect(String(cancelled?.invite?.status || '')).toMatch(/cancelled|canceled/);

    await players.playerB.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await players.playerB.page.locator('#notificationsOpen').click();
    await expect(players.playerB.page.locator('#sheet .sheet-head h2')).toHaveText(
      'Уведомления',
      { timeout: 30_000 },
    );

    const terminalCards = players.playerB.page.locator(
      `#sheet [data-notification-invite-token="${token}"]`,
    );
    await expect(terminalCards).toHaveCount(1, { timeout: 30_000 });
    const terminalCard = terminalCards.first();
    await expect(terminalCard.locator('.notification-head strong')).toHaveText('Приглашение отменено');
    await expect(terminalCard.locator('.invite-actions')).toHaveCount(0);
    const terminalMessage = String(await terminalCard.locator('p').textContent() || '').trim();
    expect(terminalMessage).toContain(inviterName);
    expect(terminalMessage).toContain('отменил приглашение сыграть');
    expect(terminalMessage).toContain('Крестики-нолики');

    expect(players.playerA.diagnostics.pageErrors).toEqual([]);
    expect(players.playerB.diagnostics.pageErrors).toEqual([]);
    expect(players.playerA.diagnostics.failedRequests).toEqual([]);
    expect(players.playerB.diagnostics.failedRequests).toEqual([]);
    expect(players.playerA.diagnostics.serverErrors).toEqual([]);
    expect(players.playerB.diagnostics.serverErrors).toEqual([]);
  } finally {
    await disposePlayers(players);
  }
});
""",
)

contract = r'''<?php
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

$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$endpoint = $read('bot/notifications.php');
$actions = $read('bot/services/invites/GameInviteActionTrait.php');
$entry = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$e2e = $read('e2e/staging/invite-terminal-dedup.spec.mjs');

$assert(
    str_contains($notifications, "if (token && type.startsWith('invite_')) return token;")
        && !str_contains($notifications, 'return `${token}|${type}`;'),
    'All cards for one invitation must share the exact invite-token identity.'
);
$assert(
    str_contains($notifications, 'function mergeEquivalentNotification')
        && str_contains($notifications, 'function isTerminalInviteNotification')
        && str_contains($notifications, 'mergeEquivalentNotification(existing, item)')
        && str_contains($notifications, 'mergeEquivalentNotification(previous, value)')
        && str_contains($notifications, 'notificationIdentity(candidate) === identity'),
    'Terminal invite state must replace the active local/cache card and remove the stale equivalent id.'
);
$assert(
    str_contains($actions, ". ' отменил приглашение сыграть в «'")
        && str_contains($actions, ". ' отменил участие в матче «'")
        && str_contains($endpoint, "$type === 'invite_cancelled'")
        && str_contains($endpoint, "$inviterName . ' отменил приглашение сыграть в «'")
        && str_contains($endpoint, "$item['inviter_name']"),
    'Cancelled terminal copy must expose actor identity and game context, including historical stored notifications.'
);
$assert(
    str_contains($entry, './assets/js/main-v110.js?v=1134')
        && str_contains($entry, 'X-MGW-Notification-Graph: v1134')
        && str_contains($main, './main-v110-handoff-shell.js?v=1134')
        && str_contains($shell, './screens/notifications-screen-v110r12.js?v=1134'),
    'The corrected notification owner must publish through a fresh v1134 graph.'
);
$assert(
    str_contains($e2e, 'recipient bell replaces active invite with one contextual cancelled terminal card')
        && str_contains($e2e, "toHaveText('Приглашение отменено')")
        && str_contains($e2e, "expect(terminalMessage).toContain(inviterName)")
        && str_contains($e2e, "expect(terminalMessage).toContain('Крестики-нолики')"),
    'Live mobile coverage must prove one exact-token terminal card without buttons and with inviter/game context.'
);

fwrite(STDOUT, "ProductionMvp14D2RecipientTerminalReplacementContextContractTest: {$assertions} assertions passed\n");
'''
contract_path = Path('bot/tests/ProductionMvp14D2RecipientTerminalReplacementContextContractTest.php')
if contract_path.exists():
    raise SystemExit(f'{contract_path}: already exists')
contract_path.write_text(contract, encoding='utf-8')
