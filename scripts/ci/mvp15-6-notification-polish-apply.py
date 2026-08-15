from pathlib import Path


def replace_one(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'expected one anchor in {path}, got {count}: {old[:100]!r}')
    p.write_text(text.replace(old, new, 1), encoding='utf-8')


def replace_exact_count(path: str, old: str, new: str, expected: int) -> None:
    p = Path(path)
    text = p.read_text(encoding='utf-8')
    count = text.count(old)
    if count != expected:
        raise SystemExit(f'expected {expected} anchors in {path}, got {count}: {old[:100]!r}')
    p.write_text(text.replace(old, new), encoding='utf-8')


# 1) Exactly three semantic notification states at the backend presentation owner.
replace_one('bot/notifications.php', '''function mgw_notification_canonical_tone(array $item): array
{
    $type = (string)($item['type'] ?? '');
    if (in_array($type, ['invite_received', 'invite_rematch_received', 'invite_accepted', 'invite_started'], true)) {
        $item['tone'] = 'info';
    } elseif (in_array($type, ['invite_declined', 'invite_cancelled', 'invite_expired', 'invite_timed_out'], true)) {
        $item['tone'] = 'warning';
    }
    return $item;
}''', '''function mgw_notification_canonical_tone(array $item): array
{
    $type = (string)($item['type'] ?? '');
    if (in_array($type, ['invite_accepted', 'invite_started'], true)) {
        $item['tone'] = 'success';
    } elseif ($type === 'invite_declined') {
        $item['tone'] = 'danger';
    } elseif (in_array($type, ['invite_received', 'invite_rematch_received', 'invite_cancelled', 'invite_expired', 'invite_timed_out'], true)) {
        $item['tone'] = 'info';
    } else {
        $tone = (string)($item['tone'] ?? 'info');
        $item['tone'] = in_array($tone, ['success', 'danger', 'info'], true) ? $tone : 'info';
    }
    return $item;
}''')
replace_one('bot/notifications.php', "$item['tone'] = 'warning';\n        $item['created_at'] = (string)($invite['cancelled_at']", "$item['tone'] = 'info';\n        $item['created_at'] = (string)($invite['cancelled_at']")
replace_one('bot/notifications.php', "$item['tone'] = 'info';\n        $item['read'] = true;\n        return $item;", "$item['tone'] = 'success';\n        $item['read'] = true;\n        return $item;")
replace_one('bot/notifications.php', "$item['tone'] = 'warning';\n        $item['read'] = true;\n        $item['created_at'] = (string)($invite['declined_at']", "$item['tone'] = 'danger';\n        $item['read'] = true;\n        $item['created_at'] = (string)($invite['declined_at']")

# 2) Completed progress is canonical success green.
replace_one(
    'app/assets/js/screens/weekly-match-info.js',
    "const completedProgressStyle = ' style=\"color:var(--gold);text-shadow:0 0 18px rgba(255,212,92,.20)\"';",
    "const completedProgressStyle = ' style=\"color:var(--sk-success);text-shadow:0 0 18px rgba(72,214,165,.20)\"';",
)

# 3) Historical first-game notifications recover the game from immutable event_key.
replace_one('bot/services/NotificationService.php', '''        $gameType = trim((string)($notification['game_type'] ?? ''));
        if ($gameType === '') return (string)($notification['message'] ?? '');

        $amount = max(0, (int)($notification['amount'] ?? 0));''', '''        $gameType = trim((string)($notification['game_type'] ?? ''));
        if ($gameType === '') {
            $eventKey = trim((string)($notification['event_key'] ?? ''));
            if (str_starts_with($eventKey, 'first_game_bonus:')) {
                $separator = strrpos($eventKey, ':');
                if ($separator !== false) $gameType = trim(substr($eventKey, $separator + 1));
            }
        }
        if ($gameType === '') return (string)($notification['message'] ?? '');

        $amount = max(0, (int)($notification['amount'] ?? 0));''')
replace_one('bot/tests/Mvp156BonusUxPolishTest.php', "        'game_type' => 'battleship',\n", '')

# 4) Poll refresh reuses the currently open list and preserves scroll state.
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', '''function renderNotifications(values){
  const safe = normalizeItems(values);
  const body = safe.length
    ? `<div class="notifications-list">${safe.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';

  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r12" hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}''', '''function renderNotifications(values){
  const safe = normalizeItems(values);
  const existingList = isNotificationsSheetOpen()
    ? document.querySelector('#sheet .notifications-list')
    : null;

  if (existingList && safe.length) {
    const scrollTop = existingList.scrollTop;
    existingList.innerHTML = safe.map(renderNotification).join('');
    existingList.scrollTop = scrollTop;
    return;
  }

  const body = safe.length
    ? `<div class="notifications-list">${safe.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';

  openSheet(`
    <span data-notifications-sheet data-notifications-owner="r12" hidden></span>
    <div class="sheet-head">
      <div><h2>Уведомления</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${body}
  `);
}''')
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', "  const tone = ['success','danger','info','warning'].includes(item.tone) ? item.tone : 'info';", '  const tone = semanticTone(item);')
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', "  const tone = ['success','danger','warning','info'].includes(item.tone) ? item.tone : 'info';", '  const tone = semanticTone(item);')
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', '''function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id);
}

function normalizeItem(value){''', '''function normalizeItems(values){
  return (Array.isArray(values) ? values : []).map(normalizeItem).filter(item => item.id);
}

function semanticTone(value){
  const type = String(value?.type || '');
  if (['invite_accepted','invite_started'].includes(type)) return 'success';
  if (type === 'invite_declined') return 'danger';
  if (['invite_received','invite_rematch_received','invite_cancelled','invite_expired','invite_timed_out'].includes(type)) return 'info';
  const tone = String(value?.tone || 'info');
  return ['success','danger','info'].includes(tone) ? tone : 'info';
}

function normalizeItem(value){''')
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', "    tone:String(value.tone || 'info'),", '    tone:semanticTone(value),')
replace_one('app/assets/js/screens/notifications-screen-v110r12.js', "  return tone === 'danger' || tone === 'warning' ? '!' : 'i';", "  return tone === 'danger' ? '!' : 'i';")

# Palette uses only the canonical Shield King blue/green/red semantic tokens.
css = Path('app/assets/css/screens/notifications.css')
text = css.read_text(encoding='utf-8')
start = text.index('/* Shield King semantic palette:')
end = text.index('\n\n.notification-icon{', start)
cards = '''/* Shield King notification semantics: blue=info, green=success, red=danger. */
.notification-card.info{border-color:rgba(134,183,255,.34);background:rgba(134,183,255,.08)}
.notification-card.success{border-color:rgba(72,214,165,.34);background:rgba(72,214,165,.08)}
.notification-card.danger{border-color:rgba(255,97,125,.36);background:rgba(255,97,125,.085)}'''
text = text[:start] + cards + text[end:]
replacements = {
    '.notification-card.info .notification-icon{color:var(--violet);background:rgba(155,113,255,.13);border-color:rgba(155,113,255,.3)}': '.notification-card.info .notification-icon{color:var(--sk-info);background:rgba(134,183,255,.14);border-color:rgba(134,183,255,.32)}',
    '.notification-card.success .notification-icon{color:var(--success);background:rgba(46,230,166,.13);border-color:rgba(46,230,166,.28)}': '.notification-card.success .notification-icon{color:var(--sk-success);background:rgba(72,214,165,.14);border-color:rgba(72,214,165,.32)}',
    '.notification-card.warning .notification-icon{color:#e7bd78;background:rgba(210,164,88,.13);border-color:rgba(210,164,88,.3)}\n': '',
    '.notification-card.danger .notification-icon{color:#d7a19c;background:rgba(169,117,112,.14);border-color:rgba(169,117,112,.32)}': '.notification-card.danger .notification-icon{color:var(--sk-error);background:rgba(255,97,125,.14);border-color:rgba(255,97,125,.34)}',
    '.notification-toast.info{border-color:rgba(155,113,255,.46);background:rgba(32,25,54,.975)}': '.notification-toast.info{border-color:rgba(134,183,255,.42);background:rgba(24,34,52,.975)}',
    '.notification-toast.success{border-color:rgba(46,230,166,.38);background:rgba(19,45,39,.975)}': '.notification-toast.success{border-color:rgba(72,214,165,.40);background:rgba(18,43,37,.975)}',
    '.notification-toast.warning{border-color:rgba(210,164,88,.42);background:rgba(51,39,25,.975)}\n': '',
    '.notification-toast.danger{border-color:rgba(169,117,112,.44);background:rgba(48,30,33,.975)}': '.notification-toast.danger{border-color:rgba(255,97,125,.44);background:rgba(52,24,31,.975)}',
    '.notification-toast.info .notification-toast-icon{color:var(--violet);background:rgba(155,113,255,.16);border-color:rgba(155,113,255,.36)}': '.notification-toast.info .notification-toast-icon{color:var(--sk-info);background:rgba(134,183,255,.16);border-color:rgba(134,183,255,.36)}',
    '.notification-toast.success .notification-toast-icon{color:var(--success);background:rgba(46,230,166,.15);border-color:rgba(46,230,166,.34)}': '.notification-toast.success .notification-toast-icon{color:var(--sk-success);background:rgba(72,214,165,.15);border-color:rgba(72,214,165,.34)}',
    '.notification-toast.warning .notification-toast-icon{color:#e7bd78;background:rgba(210,164,88,.15);border-color:rgba(210,164,88,.34)}\n': '',
    '.notification-toast.danger .notification-toast-icon{color:#d7a19c;background:rgba(169,117,112,.16);border-color:rgba(169,117,112,.36)}': '.notification-toast.danger .notification-toast-icon{color:var(--sk-error);background:rgba(255,97,125,.16);border-color:rgba(255,97,125,.36)}',
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'notification css anchor missing: {old[:100]!r}')
    text = text.replace(old, new, 1)
css.write_text(text, encoding='utf-8')

# Real /start cache graph.
replace_one('app/assets/css/main.css', "@import url('./screens/notifications.css?v=81&palette=shield-king-semantic');", "@import url('./screens/notifications.css?v=82&palette=three-state-semantic');")
replace_one('app/assets/js/main-v110-handoff-shell.js', "./screens/notifications-screen-v110r12.js?v=1137&ux=1", "./screens/notifications-screen-v110r12.js?v=1139&semantic=3&scroll=stable")
replace_one('app/assets/js/main-v110-handoff-shell.js', "./screens/weekly-match-info.js?v=78", "./screens/weekly-match-info.js?v=79&complete=green")
replace_exact_count('app/v110.php', "./assets/js/main-v110-handoff-shell.js?v=1145&mvp15=bonus-modal-fit", "./assets/js/main-v110-handoff-shell.js?v=1146&mvp15=notification-polish", 2)
replace_one('app/v110.php', "./assets/css/main.css?v=154&sk=3&icons=c1efd5af&render=28&palette=notification-semantic&battleship=authoritative-shot-only&wallet=weekly-bonus-cta", "./assets/css/main.css?v=155&sk=3&icons=c1efd5af&render=29&palette=three-state-notifications&battleship=authoritative-shot-only&wallet=weekly-bonus-cta")
replace_one('app/v110.php', 'data-hotfix-build="v110-mvp15-unified-balance-copy-cleanup-v1163"', 'data-hotfix-build="v110-mvp15-unified-balance-copy-cleanup-v1164"')
replace_one('app/v110.php', "header('X-MGW-Notification-Graph: v1138-shield-semantic-tone');", "header('X-MGW-Notification-Graph: v1139-three-state-scroll-stable');")
replace_one('app/v110.php', "header('X-MGW-Notification-Palette: shield-king-v1-semantic');", "header('X-MGW-Notification-Palette: green-red-blue-v1');")
replace_one('bot/tests/Mvp156UnifiedZoneCutoverTest.php', 'color:var(--gold)', 'color:var(--sk-success)')
replace_one('bot/tests/Mvp156UnifiedZoneCutoverTest.php', 'Completed 3/3 and 8/8 progress must use Shield King gold accent', 'Completed 3/3 and 8/8 progress must use the canonical success green')
replace_one('bot/tests/Mvp156UnifiedZoneCutoverTest.php', 'v=1145&mvp15=bonus-modal-fit', 'v=1146&mvp15=notification-polish')

manifest = Path('bot/helpers/staging-e2e-runtime-files.txt')
manifest_text = manifest.read_text(encoding='utf-8')
if 'app/assets/css/screens/notifications.css\n' not in manifest_text:
    marker = 'app/assets/css/screens/profile.css\n'
    if marker not in manifest_text:
        raise SystemExit('fingerprint manifest anchor missing')
    manifest_text = manifest_text.replace(marker, marker + 'app/assets/css/screens/notifications.css\n', 1)
    manifest.write_text(manifest_text, encoding='utf-8')
