from pathlib import Path
import subprocess

CLIENT = Path('app/assets/js/games/game-invites-v110.js')
PICKER_E2E = Path('e2e/staging/d1-bug-b-player-picker-v122.spec.mjs')
SHELL = Path('app/assets/js/main-v110-handoff-shell.js')
MAIN = Path('app/assets/js/main-v110.js')
ROUTE = Path('app/v110.php')


def blob(path: str) -> str:
    return subprocess.check_output(['git', 'hash-object', path], text=True).strip()[:12]


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 anchor, got {count}')
    return text.replace(old, new, 1)


text = CLIENT.read_text(encoding='utf-8')
if 'let inviteUiTransitionGeneration = 0;' not in text:
    text = replace_once(
        text,
        'let inviteStartPending = false;',
        'let inviteStartPending = false;\nlet inviteUiTransitionGeneration = 0;',
        'transition generation variable',
    )

    start = text.index('async function openPlayerPicker(context, sourceButton = null){')
    end = text.index('\nfunction playerCard(item){', start)
    replacement = r'''async function openPlayerPicker(context, sourceButton = null){
  const requestGeneration = ++playerPickerRequestGeneration;
  const trigger = sourceButton instanceof HTMLButtonElement ? sourceButton : null;
  if (trigger) {
    trigger.disabled = true;
    trigger.setAttribute('aria-busy', 'true');
  }

  haptic('light');
  showPlayerPickerLoading(context, requestGeneration);

  try {
    const result = await postJson(OPPONENTS_URL, {});
    if (requestGeneration !== playerPickerRequestGeneration) return;
    const items = Array.isArray(result.items) ? result.items.slice(0, MAX_OPPONENTS) : [];
    items.sort((a, b) => Number(Boolean(b.online)) - Number(Boolean(a.online)));
    renderPlayerPicker(items, context, requestGeneration);
  } catch (error) {
    if (requestGeneration !== playerPickerRequestGeneration) return;
    renderPlayerPickerError(requestGeneration, error);
  } finally {
    if (trigger?.isConnected && requestGeneration === playerPickerRequestGeneration) {
      trigger.disabled = false;
      trigger.removeAttribute('aria-busy');
    }
  }
}

function showPlayerPickerLoading(context, requestGeneration){
  openSheet(`
    <span data-player-picker-generation="${Number(requestGeneration || 0)}" hidden></span>
    <div class="sheet-head">
      <div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="invite-player-list" data-player-picker-results aria-busy="true">
      <button class="invite-player-card loading" type="button" disabled aria-hidden="true" tabindex="-1">
        <span class="invite-player-avatar" aria-hidden="true">…</span>
        <span class="invite-player-copy"><strong>Загружаем игроков</strong><span>Проверяем доступность</span></span>
        <span class="invite-player-arrow" aria-hidden="true">›</span>
      </button>
    </div>
    <button class="btn ghost full" data-back-to-invite-setup type="button">Назад к условиям</button>
  `);
  bindPlayerPickerBack(context);
}

function activePlayerPickerSurface(requestGeneration){
  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return null;
  const root = document.getElementById('sheet');
  const marker = root?.querySelector('[data-player-picker-generation]');
  const results = root?.querySelector('[data-player-picker-results]');
  if (!root || !marker || !results) return null;
  if (String(marker.dataset.playerPickerGeneration || '') !== String(Number(requestGeneration || 0))) return null;
  return { results };
}

function renderPlayerPicker(items, context, requestGeneration){
  const list = items.length
    ? items.map(playerCard).join('')
    : `<div class="notifications-empty invite-empty-state"><div>👥</div><strong>Недавних соперников пока нет</strong><span>Вернитесь назад и отправьте ссылку.</span></div>`;
  const surface = activePlayerPickerSurface(requestGeneration);
  if (!surface) return;
  surface.results.innerHTML = list;
  surface.results.setAttribute('aria-busy', 'false');
  document.querySelectorAll('[data-direct-opponent]').forEach(button => button.addEventListener('click', () => {
    createDirectInvite(context, String(button.dataset.directOpponent || ''), button);
  }));
}

function renderPlayerPickerError(requestGeneration, error){
  const surface = activePlayerPickerSurface(requestGeneration);
  if (!surface) return;
  surface.results.innerHTML = `
    <div class="notifications-empty invite-empty-state">
      <div>⚠️</div><strong>Не удалось загрузить игроков</strong>
      <span>${escapeHtml(error?.message || 'Попробуйте ещё раз.')}</span>
    </div>`;
  surface.results.setAttribute('aria-busy', 'false');
}

function bindPlayerPickerBack(context){
  document.querySelector('[data-back-to-invite-setup]')?.addEventListener('click', () => {
    playerPickerRequestGeneration += 1;
    openInviteSetup(context.gameType, context);
  });
}
'''
    text = text[:start] + replacement + text[end:]

    text = replace_once(
        text,
        "    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {\n      showOwnerWaiting(currentInvite);\n    } else if (isPassiveOwnerPending(currentInvite)) {",
        "    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {\n      finalizeDirectInvitePendingSurface(currentInvite, requestGeneration);\n    } else if (isPassiveOwnerPending(currentInvite)) {",
        'direct invite in-place finalization',
    )
    text = replace_once(
        text,
        '    <button class="btn primary full" type="button" aria-disabled="true" disabled style="opacity:1">Отменить приглашение</button>',
        '    <button class="btn primary full" data-direct-invite-cancel-reserved type="button" aria-disabled="true" disabled style="opacity:1">Отменить приглашение</button>',
        'reserved cancel control',
    )
    anchor = "function isDirectInvitePendingSurfaceOpen(requestGeneration){\n  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return false;\n  return String(document.querySelector('#sheet [data-direct-invite-pending]')?.dataset.directInvitePending || '')\n    === String(Number(requestGeneration || 0));\n}\n"
    addition = anchor + r'''
function finalizeDirectInvitePendingSurface(invite, requestGeneration){
  const root = document.getElementById('sheet');
  const marker = root?.querySelector('[data-direct-invite-pending]');
  const button = root?.querySelector('[data-direct-invite-cancel-reserved]');
  const token = String(invite?.token || '');
  if (!root || !marker || !button || !token
      || String(marker.dataset.directInvitePending || '') !== String(Number(requestGeneration || 0))) {
    showOwnerWaiting(invite);
    return;
  }
  marker.dataset.inviteToken = token;
  marker.dataset.inviteState = inviteSheetState(invite);
  marker.removeAttribute('data-direct-invite-pending');
  button.disabled = false;
  button.removeAttribute('aria-disabled');
  button.removeAttribute('style');
  button.removeAttribute('data-direct-invite-cancel-reserved');
  button.dataset.inviteAction = 'cancel';
  button.dataset.inviteToken = token;
}
'''
    text = replace_once(text, anchor, addition, 'direct invite helper')

    text = replace_once(
        text,
        "async function performInviteAction(action, token, button){\n  if (!action || !token || button.disabled) return;\n  haptic('light');",
        "async function performInviteAction(action, token, button){\n  if (!action || !token || button.disabled) return;\n  inviteUiTransitionGeneration += 1;\n  haptic('light');",
        'action transition generation',
    )
    text = replace_once(
        text,
        "    if (action === 'accept') {\n      showInviteeWaiting(currentInvite);\n      scheduleSync(0);\n      return;\n    }",
        "    if (action === 'accept') {\n      if (!reconcileInviteeWaiting(currentInvite)) showInviteeWaiting(currentInvite);\n      scheduleSync(0);\n      return;\n    }",
        'accept in-place reconciliation',
    )
    anchor = "function showInviteeWaiting(invite){\n  openSheet(`\n    ${inviteMarker(invite)}\n    <div class=\"sheet-head\">\n      <div><h2>Приглашение принято</h2><p>Ждём запуска матча от ${escapeHtml(invite.inviter_name || 'игрока')}.</p></div>\n      <button class=\"close\" data-close-sheet type=\"button\">×</button>\n    </div>\n    ${inviteSummary(invite)}\n    <div class=\"small-note invite-status-note\">Ожидание до ${escapeHtml(formatTime(invite.ready_deadline_at))}.</div>\n    <button class=\"btn ghost full\" data-invite-action=\"cancel\" data-invite-token=\"${escapeHtml(invite.token || '')}\" type=\"button\">Отменить участие</button>\n  `);\n}\n"
    addition = anchor + r'''
function reconcileInviteeWaiting(invite){
  const token = String(invite?.token || '');
  if (!token || openSheetInviteToken() !== token || openSheetInviteState() !== 'accepted:invitee') return false;
  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  const note = document.querySelector('#sheet .invite-status-note');
  if (!marker || !note) return false;
  marker.dataset.inviteState = inviteSheetState(invite);
  note.textContent = `Ожидание до ${formatTime(invite.ready_deadline_at)}.`;
  return true;
}
'''
    text = replace_once(text, anchor, addition, 'invitee waiting reconciliation helper')

    text = replace_once(
        text,
        "async function syncNow({ announce = true } = {}){\n  if (inviteStartPending || syncBusy || document.visibilityState !== 'visible') return null;\n  if (String(state.activeGame?.status || '') === 'active') return null;\n\n  const requestedInviteToken = String(currentInvite?.token || '');\n  syncBusy = true;\n  try {\n    const result = await inviteRequest('sync', { token:requestedInviteToken });\n    syncState(result);\n    processInviteEvents(result.invite_events, Number(result.unread_count || 0), announce);",
        "async function syncNow({ announce = true } = {}){\n  if (inviteStartPending || syncBusy || document.visibilityState !== 'visible') return null;\n  if (String(state.activeGame?.status || '') === 'active') return null;\n\n  const requestedInviteToken = String(currentInvite?.token || '');\n  const syncUiTransitionGeneration = inviteUiTransitionGeneration;\n  syncBusy = true;\n  try {\n    const result = await inviteRequest('sync', { token:requestedInviteToken });\n    syncState(result);\n    if (syncUiTransitionGeneration !== inviteUiTransitionGeneration) return result;\n    processInviteEvents(result.invite_events, Number(result.unread_count || 0), announce);",
        'stale sync UI guard',
    )
    CLIENT.write_text(text, encoding='utf-8')


e2e = PICKER_E2E.read_text(encoding='utf-8')
if 'Загружаем игроков' not in e2e:
    e2e = replace_once(
        e2e,
        "    await playerA.page.locator('[data-open-player-picker]').click();\n\n    const response = await opponentResponse;",
        "    await playerA.page.locator('[data-open-player-picker]').click();\n    await expect(playerA.page.locator('#sheet .sheet-head h2')).toHaveText('Выберите игрока', { timeout:650 });\n    await expect(playerA.page.locator('[data-player-picker-results]')).toHaveAttribute('aria-busy', 'true', { timeout:650 });\n    await expect(playerA.page.locator('[data-player-picker-results]')).toContainText('Загружаем игроков', { timeout:650 });\n    await expect(playerA.page.locator('[data-player-picker-results]')).not.toContainText(PLAYER_B_VISIBLE_NAME, { timeout:650 });\n\n    const response = await opponentResponse;",
        'picker immediate loading frame',
    )
    e2e = replace_once(
        e2e,
        "    expect(frames.filter(frame => /Загружаем соперников/i.test(String(frame.text)))).toEqual([]);\n    expect(frames.filter(frame => FALSE_EMPTY_PATTERN.test(String(frame.text)))).toEqual([]);\n    const pickerFrames = frames.filter(frame => String(frame.text).includes('Выберите игрока'));\n    expect(pickerFrames.length).toBeGreaterThan(0);\n    expect(String(pickerFrames[0].text)).toContain(PLAYER_B_VISIBLE_NAME);",
        "    expect(frames.filter(frame => FALSE_EMPTY_PATTERN.test(String(frame.text)))).toEqual([]);\n    const pickerFrames = frames.filter(frame => String(frame.text).includes('Выберите игрока'));\n    expect(pickerFrames.length).toBeGreaterThan(0);\n    expect(String(pickerFrames[0].text)).toContain('Загружаем игроков');\n    expect(pickerFrames.some(frame => String(frame.text).includes(PLAYER_B_VISIBLE_NAME))).toBe(true);",
        'picker frame transition semantics',
    )
    PICKER_E2E.write_text(e2e, encoding='utf-8')


client_blob = blob(str(CLIENT))
shell = SHELL.read_text(encoding='utf-8')
if 'v110-mvp14-invite-popup-stability-v1136' not in shell:
    shell = replace_once(shell, 'v110-mvp14-interface-invite-speed-v1135', 'v110-mvp14-invite-popup-stability-v1136', 'shell build')
    shell = replace_once(shell, './games/game-invites-v110.js?v=1135&pending=6&b=8c98ab6d8635', f'./games/game-invites-v110.js?v=1136&pending=7&b={client_blob}', 'shell invite graph')
    SHELL.write_text(shell, encoding='utf-8')

shell_blob = blob(str(SHELL))
main = MAIN.read_text(encoding='utf-8')
if 'v110-mvp14-invite-popup-stability-v1136' not in main:
    main = replace_once(main, 'v110-mvp14-interface-invite-speed-v1135', 'v110-mvp14-invite-popup-stability-v1136', 'main build')
    main = replace_once(main, './main-v110-handoff-shell.js?v=1135&pending=6&b=c723392fcac8', f'./main-v110-handoff-shell.js?v=1136&pending=7&b={shell_blob}', 'main shell graph')
    MAIN.write_text(main, encoding='utf-8')

main_blob = blob(str(MAIN))
route = ROUTE.read_text(encoding='utf-8')
if "X-MGW-Invite-Graph: v1136" not in route:
    route = replace_once(route, './assets/js/main-v110.js?v=1135&pending=6&b=31fca0ad4bfb', f'./assets/js/main-v110.js?v=1136&pending=7&b={main_blob}', 'route main graph')
    route = replace_once(route, 'v110-mvp14-interface-invite-speed-v1135', 'v110-mvp14-invite-popup-stability-v1136', 'route build')
    route = replace_once(route, "header('X-MGW-Invite-Graph: v1135');", "header('X-MGW-Invite-Graph: v1136');", 'route invite header')
    ROUTE.write_text(route, encoding='utf-8')

picker = PICKER_E2E.read_text(encoding='utf-8')
picker = picker.replace("url.searchParams.get('v') === '1135'", "url.searchParams.get('v') === '1136'")
PICKER_E2E.write_text(picker, encoding='utf-8')
