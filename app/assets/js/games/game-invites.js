import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { renderBalances } from '../ui.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const SYNC_INTERVAL_MS = 1500;
const SHARE_CALLBACK_TIMEOUT_MS = 90000;
const MAX_OPPONENTS = 10;

const GAME_OPTIONS = {
  tictactoe: { title:'Крестики-нолики', sizes:[3,5,9], defaultSize:3 },
  four_in_a_row: { title:'4 в ряд', sizes:[6,7,8], defaultSize:7 },
  battleship: { title:'Морской бой', sizes:[10], defaultSize:10 },
  checkers: { title:'Шашки', sizes:[8], defaultSize:8 },
  reversi: { title:'Реверси', sizes:[6,8,10], defaultSize:8 },
  chess: { title:'Шахматы', sizes:[8], defaultSize:8 },
  go: { title:'Го', sizes:[9,13], defaultSize:9 },
  domino: { title:'Домино', sizes:[7], defaultSize:7 },
};

let initialized = false;
let appReady = false;
let syncBusy = false;
let syncTimer = null;
let currentInvite = null;
let deepLinkHandled = false;
let eventBaselineReady = false;
let seenInviteEventIds = new Set();
let resultObserver = null;
let resultEnhanceTimer = null;
let lastFinishedGame = null;

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', handleDocumentClick, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      syncNow({ announce:true });
      scheduleSync(0);
    }
  });
  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    scheduleSync(0);
  }, { once:true });
  document.addEventListener('mgw:game-dismissed', () => {
    window.setTimeout(() => syncNow({ announce:true }), 80);
  });

  const sheet = document.getElementById('sheet');
  if (sheet) {
    resultObserver = new MutationObserver(scheduleResultEnhancement);
    resultObserver.observe(sheet, { childList:true, subtree:true });
  }

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => {
        syncNow({ announce:true });
        scheduleSync(0);
      });
    } catch (error) {
      // Older Telegram clients do not expose this event.
    }
  }
}

export async function openIncomingInviteIfPresent(){
  const token = incomingToken();
  if (token && !deepLinkHandled) {
    deepLinkHandled = true;
    try {
      const result = await inviteRequest('open_link', { token });
      syncState(result);
      currentInvite = result.invite || null;
      if (currentInvite?.token) openCurrentInvite();
    } catch (error) {
      toast(error.message || 'Приглашение уже недоступно.');
    }
  }

  await syncNow({ announce:false });
  scheduleSync(SYNC_INTERVAL_MS);
}

function handleDocumentClick(event){
  const actionButton = event.target.closest('[data-invite-action]');
  if (actionButton) {
    event.preventDefault();
    event.stopImmediatePropagation();
    performInviteAction(
      String(actionButton.dataset.inviteAction || ''),
      String(actionButton.dataset.inviteToken || currentInvite?.token || ''),
      actionButton
    );
    return;
  }

  const rematchButton = event.target.closest('[data-create-rematch]');
  if (rematchButton) {
    event.preventDefault();
    event.stopImmediatePropagation();
    createRematch(String(rematchButton.dataset.createRematch || ''), rematchButton);
    return;
  }

  const launchTarget = event.target.closest('button, [role="button"]');
  if (launchTarget && currentInvite?.status === 'accepted' && isGameLaunchControl(launchTarget)) {
    event.preventDefault();
    event.stopImmediatePropagation();
    toast('Сначала запустите или отмените подтверждённое приглашение.');
    openCurrentInvite();
    return;
  }

  const inviteButton = event.target.closest('[data-invite-friend]');
  if (!inviteButton) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  openInviteSetup(String(inviteButton.dataset.inviteFriend || 'tictactoe'));
}

function openInviteSetup(gameType, preserved = null){
  if (currentInvite?.status === 'accepted') return openCurrentInvite();

  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  const room = preserved?.room || (state.room === 'gold' ? 'gold' : 'match');
  let boardSize = Number(preserved?.boardSize || option.defaultSize);
  let bet = Number(preserved?.bet || (
    room === 'gold' && APP_CONFIG.goldBets.includes(Number(state.selectedBet))
      ? Number(state.selectedBet)
      : (room === 'gold' ? APP_CONFIG.goldBets[0] : APP_CONFIG.matchBet)
  ));

  haptic('light');
  openSheet(`
    <span data-invite-setup hidden></span>
    <div class="sheet-head">
      <div>
        <h2>Пригласить в «${escapeHtml(option.title)}»</h2>
        <p>${escapeHtml(roomLabel(room))}. Выберите условия.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    <div class="setup-scroll">
      <div class="small-note">Коины спишутся только после запуска матча.</div>
      <div class="section-title"><h2>Вариант игры</h2></div>
      <div class="choice-grid field-size-grid" data-invite-sizes>
        ${option.sizes.map(size => `
          <button class="choice ${size === boardSize ? 'active' : ''}" data-invite-size="${size}" type="button">
            ${escapeHtml(boardLabel(gameType, size))}
          </button>
        `).join('')}
      </div>
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${room === 'gold' ? '' : 'single-choice'}" data-invite-bets>
        ${(room === 'gold' ? APP_CONFIG.goldBets : [APP_CONFIG.matchBet]).map(value => `
          <button class="choice ${value === bet ? 'active' : ''} ${room === 'gold' ? 'gold' : ''}" data-invite-bet="${value}" type="button">
            ${value} коинов
          </button>
        `).join('')}
      </div>
    </div>

    <div class="stack invite-actions">
      <button class="btn ${room === 'gold' ? 'gold' : 'primary'} full" data-open-player-picker type="button">Пригласить игрока</button>
      <button class="btn ghost full" data-create-link-invite type="button">Поделиться ссылкой</button>
    </div>
    <div class="invite-method-note">Игроку из списка приглашение сразу придёт в приложение. Ссылка нужна для нового человека.</div>
  `);

  document.querySelectorAll('[data-invite-size]').forEach(button => button.addEventListener('click', () => {
    boardSize = Number(button.dataset.inviteSize || option.defaultSize);
    document.querySelectorAll('[data-invite-size]').forEach(item => item.classList.toggle('active', item === button));
  }));
  document.querySelectorAll('[data-invite-bet]').forEach(button => button.addEventListener('click', () => {
    bet = Number(button.dataset.inviteBet || APP_CONFIG.matchBet);
    document.querySelectorAll('[data-invite-bet]').forEach(item => item.classList.toggle('active', item === button));
  }));
  document.querySelector('[data-open-player-picker]')?.addEventListener('click', () => openPlayerPicker({ gameType, room, boardSize, bet }));
  document.querySelector('[data-create-link-invite]')?.addEventListener('click', event => createLinkDraft({ gameType, room, boardSize, bet }, event.currentTarget));
}

async function openPlayerPicker(context){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading"><div>👥</div><strong>Загружаем соперников…</strong></div>
  `);

  try {
    const result = await postJson(OPPONENTS_URL, {});
    const items = Array.isArray(result.items) ? result.items.slice(0, MAX_OPPONENTS) : [];
    items.sort((a, b) => Number(Boolean(b.online)) - Number(Boolean(a.online)));
    renderPlayerPicker(items, context);
  } catch (error) {
    openSheet(`
      <div class="sheet-head"><div><h2>Не удалось загрузить игроков</h2></div><button class="close" data-close-sheet type="button">×</button></div>
      <div class="small-note">${escapeHtml(error.message || 'Попробуйте ещё раз.')}</div>
      <button class="btn ghost full" data-back-to-invite-setup type="button">Назад</button>
    `);
    document.querySelector('[data-back-to-invite-setup]')?.addEventListener('click', () => openInviteSetup(context.gameType, context));
  }
}

function renderPlayerPicker(items, context){
  const list = items.length
    ? `<div class="invite-player-list">${items.map(playerCard).join('')}</div>`
    : `<div class="notifications-empty invite-empty-state"><div>👥</div><strong>Недавних соперников пока нет</strong><span>Вернитесь назад и отправьте ссылку.</span></div>`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${list}
    <button class="btn ghost full" data-back-to-invite-setup type="button">Назад к условиям</button>
  `);

  document.querySelector('[data-back-to-invite-setup]')?.addEventListener('click', () => openInviteSetup(context.gameType, context));
  document.querySelectorAll('[data-direct-opponent]').forEach(button => button.addEventListener('click', () => {
    createDirectInvite(context, String(button.dataset.directOpponent || ''), button);
  }));
}

function playerCard(item){
  const id = String(item?.id || '');
  const name = String(item?.name || 'Игрок');
  const statusClass = item?.busy ? 'busy' : (item?.online ? 'online' : 'offline');
  return `
    <button class="invite-player-card" data-direct-opponent="${escapeHtml(id)}" type="button">
      <span class="invite-player-avatar" style="--invite-avatar-hue:${avatarHue(id)}" aria-hidden="true">${escapeHtml(initials(name))}</span>
      <span class="invite-player-copy">
        <strong>${escapeHtml(name)}</strong>
        <span><i class="invite-player-dot ${statusClass}"></i>${escapeHtml(item?.activity || 'недавний соперник')}</span>
      </span>
      <span class="invite-player-arrow" aria-hidden="true">›</span>
    </button>
  `;
}

async function createDirectInvite(context, inviteeId, button){
  if (!inviteeId || button.disabled) return;
  haptic('light');
  document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = true; });
  button.classList.add('loading');

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    syncState(result);
    currentInvite = result.invite || null;
    if (!currentInvite?.token) throw new Error('Не удалось создать приглашение.');
    showOwnerWaiting(currentInvite, result.telegram_sent ? 'Игрок получил приглашение в приложении и сообщение от бота.' : 'Игрок получил приглашение в приложении.');
    dispatchNotificationCount(result.unread_count);
    scheduleSync(0);
  } catch (error) {
    toast(error.message || 'Не удалось отправить приглашение.');
    document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = false; });
    button.classList.remove('loading');
  }
}

async function createLinkDraft(context, button){
  if (button.disabled) return;
  haptic('light');
  button.disabled = true;
  button.textContent = 'Готовим ссылку…';

  try {
    const result = await inviteRequest('create_link_draft', context);
    syncState(result);
    const draftInvite = result.invite || null;
    const draftToken = String(draftInvite?.token || '');
    if (!draftToken) throw new Error('Не удалось подготовить ссылку.');
    currentInvite = draftInvite;

    const tg = getTelegram();
    const preparedId = String(draftInvite.prepared_message_id || '');
    if (preparedId && typeof tg?.shareMessage === 'function') {
      showSharingSheet(draftInvite);
      const sent = await sharePreparedMessage(tg, preparedId);
      if (sent === true) {
        const confirmed = await inviteRequest('confirm_shared', { token:draftToken });
        syncState(confirmed);
        currentInvite = confirmed.invite || draftInvite;
        showOwnerWaiting(currentInvite, 'Приглашение отправлено. Ждём ответа игрока.');
        scheduleSync(0);
        return;
      }

      await inviteRequest('discard_draft', { token:draftToken }).catch(() => null);
      if (String(currentInvite?.token || '') === draftToken) currentInvite = null;
      openInviteSetup(context.gameType, context);
      toast(sent === false ? 'Отправка отменена.' : 'Telegram не подтвердил отправку.');
      scheduleSync(0);
      return;
    }

    showPreparedLink(draftInvite, context);
  } catch (error) {
    toast(error.message || 'Не удалось подготовить приглашение.');
    openInviteSetup(context.gameType, context);
  }
}

function showSharingSheet(invite){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head"><div><h2>Отправка приглашения</h2><p>Выберите человека в Telegram.</p></div></div>
    ${inviteSummary(invite)}
    <div class="notifications-loading"><div>✈️</div><strong>Ждём результата отправки…</strong></div>
  `);
}

function showPreparedLink(invite, context){
  const shareUrl = String(invite.share_url || '');
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>Ссылка подготовлена</h2><p>Telegram не может подтвердить отправку на этом устройстве.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note">Приглашение начнёт ожидать ответ, когда получатель откроет ссылку.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-fallback-share type="button">Открыть список Telegram</button>
      <button class="btn ghost full" data-copy-invite-link type="button">Скопировать ссылку</button>
      <button class="btn ghost full" data-discard-draft type="button">Отменить</button>
    </div>
  `);

  document.querySelector('[data-fallback-share]')?.addEventListener('click', () => openFallbackShare(invite));
  document.querySelector('[data-copy-invite-link]')?.addEventListener('click', () => copyInviteLink(shareUrl));
  document.querySelector('[data-discard-draft]')?.addEventListener('click', async () => {
    await inviteRequest('discard_draft', { token:invite.token }).catch(() => null);
    currentInvite = null;
    openInviteSetup(context.gameType, context);
  });
}

function openFallbackShare(invite){
  const shareUrl = String(invite.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');
  const text = String(invite.share_text || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  const tg = getTelegram();
  try {
    if (tg?.openTelegramLink) tg.openTelegramLink(url);
    else window.open(url, '_blank', 'noopener,noreferrer');
  } catch (error) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

async function copyInviteLink(url){
  if (!url) return toast('Ссылка временно недоступна.');
  try {
    await navigator.clipboard.writeText(url);
    toast('Ссылка скопирована.');
  } catch (error) {
    window.prompt('Скопируйте ссылку:', url);
  }
}

function sharePreparedMessage(tg, preparedId){
  return new Promise(resolve => {
    let settled = false;
    const finish = value => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timeout);
      resolve(value);
    };
    const timeout = window.setTimeout(() => finish(null), SHARE_CALLBACK_TIMEOUT_MS);
    try {
      tg.shareMessage(preparedId, result => finish(Boolean(result)));
    } catch (error) {
      finish(null);
    }
  });
}

async function performInviteAction(action, token, button){
  if (!action || !token || button.disabled) return;
  haptic('light');
  const originalText = button.textContent;
  setInviteButtonsDisabled(true);
  button.textContent = actionText(action);

  try {
    const result = await inviteRequest(action, { token });
    syncState(result);
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result.game);
      return;
    }

    currentInvite = result.invite || currentInvite;

    if (action === 'accept') {
      if (currentInvite?.source === 'rematch') {
        scheduleSync(0);
      } else {
        showInviteeWaiting(currentInvite);
      }
      return;
    }
    if (action === 'decline' || action === 'cancel') {
      const sheetToken = openSheetInviteToken();
      if (sheetToken === token) closeSheet();
      currentInvite = null;
      toast(action === 'decline' ? 'Приглашение отклонено.' : 'Приглашение отменено.');
      dispatchNotificationsRefresh();
      scheduleSync(0);
      return;
    }
    if (action === 'start') {
      scheduleSync(0);
      return;
    }

    setInviteButtonsDisabled(false);
    button.textContent = originalText;
  } catch (error) {
    toast(error.message || 'Не удалось выполнить действие.');
    setInviteButtonsDisabled(false);
    button.textContent = originalText;
  }
}

async function createRematch(gameId, button){
  if (!gameId || button.disabled) return;
  haptic('light');
  button.disabled = true;
  button.textContent = 'Предлагаем реванш…';

  try {
    const result = await inviteRequest('rematch', { gameId });
    syncState(result);
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result.game);
      return;
    }

    currentInvite = result.invite || null;
    if (!currentInvite?.token) throw new Error('Не удалось создать реванш.');
    state.activeGame = null;
    showScreen('home');
    showOwnerWaiting(currentInvite, 'Предложение реванша отправлено.');
    scheduleSync(0);
  } catch (error) {
    toast(error.message || 'Не удалось предложить реванш.');
    button.disabled = false;
    button.textContent = 'Предложить реванш';
  }
}

async function syncNow({ announce = true } = {}){
  if (syncBusy || document.visibilityState !== 'visible') return null;
  if (String(state.activeGame?.status || '') === 'active') return null;

  syncBusy = true;
  try {
    const result = await inviteRequest('sync', { token:String(currentInvite?.token || '') });
    syncState(result);
    processInviteEvents(result.invite_events, Number(result.unread_count || 0), announce);

    if (result?.active_game?.id && String(result.active_game.status || '') === 'active') {
      enterGame(result.active_game);
      return result;
    }

    const nextInvite = chooseSyncInvite(result);
    if (nextInvite?.token) {
      currentInvite = nextInvite;
      updateOpenInviteSheet();
      if (isTerminal(nextInvite.status) && openSheetInviteToken() !== String(nextInvite.token || '')) {
        currentInvite = null;
      }
    } else if (currentInvite && !isDraft(currentInvite)) {
      currentInvite = null;
    }

    return result;
  } catch (error) {
    return null;
  } finally {
    syncBusy = false;
  }
}

function chooseSyncInvite(result){
  const active = result?.invite || null;
  const tracked = result?.tracked_invite || null;
  /* A new actionable invitation must always outrank an old tracked terminal token. */
  if (active?.token) return active;
  if (tracked?.token) return tracked;
  return null;
}

function processInviteEvents(items, unreadCount, announce){
  const events = Array.isArray(items) ? items : [];
  dispatchNotificationCount(unreadCount);

  if (!eventBaselineReady || !announce || !appReady) {
    for (const item of events) {
      const id = String(item?.id || '');
      if (id) seenInviteEventIds.add(id);
    }
    eventBaselineReady = true;
    return;
  }

  const fresh = events
    .filter(item => {
      const id = String(item?.id || '');
      return id && !item?.read && !seenInviteEventIds.has(id);
    })
    .reverse();

  for (const item of fresh) {
    const id = String(item.id || '');
    seenInviteEventIds.add(id);
    document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
      detail:{ item, unreadCount },
    }));
  }
}

function updateOpenInviteSheet(){
  if (!currentInvite?.token) return;
  const openToken = openSheetInviteToken();
  if (openToken !== String(currentInvite.token || '')) return;
  if (openSheetInviteState() === inviteSheetState(currentInvite)) return;

  const status = String(currentInvite.status || '');
  if (status === 'pending' && currentInvite.is_owner) {
    showOwnerWaiting(currentInvite);
    return;
  }
  if (status === 'accepted') {
    if (currentInvite.is_owner) showOwnerReady(currentInvite);
    else showInviteeWaiting(currentInvite);
    return;
  }
  if (isTerminal(status)) showTerminalInvite(currentInvite);
}

function showIncomingInvite(invite){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(invite.inviter_name || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отклонить</button>
    </div>
  `);
}

function showOwnerWaiting(invite, message = 'Ждём ответа игрока. Коины пока не списываются.'){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>${invite.source === 'rematch' ? 'Реванш предложен' : 'Приглашение отправлено'}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">${escapeHtml(message)}</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить приглашение</button>
  `);
}

function showOwnerReady(invite){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>Соперник согласен</h2><p>${escapeHtml(invite.invitee_name || 'Игрок')} готов играть.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Запустите матч до ${escapeHtml(formatTime(invite.ready_deadline_at))}.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="start" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Начать игру</button>
      <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить</button>
    </div>
  `);
}

function showInviteeWaiting(invite){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>Приглашение принято</h2><p>Ждём запуска матча от ${escapeHtml(invite.inviter_name || 'игрока')}.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(invite.ready_deadline_at))}.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить участие</button>
  `);
}

function showTerminalInvite(invite){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>${escapeHtml(invite.status_label || terminalTitle(invite.status))}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note">Это приглашение больше нельзя использовать.</div>
    <button class="btn primary full" data-close-sheet type="button">Понятно</button>
  `);
}

function openCurrentInvite(){
  if (!currentInvite?.token) return;
  if (currentInvite.status === 'accepted') {
    if (currentInvite.is_owner) showOwnerReady(currentInvite);
    else showInviteeWaiting(currentInvite);
  } else if (currentInvite.status === 'pending') {
    if (currentInvite.is_owner) showOwnerWaiting(currentInvite);
    else showIncomingInvite(currentInvite);
  } else if (isTerminal(currentInvite.status)) {
    showTerminalInvite(currentInvite);
  }
}

function enterGame(game){
  if (!game?.id || String(game.status || '') !== 'active') return;
  currentInvite = null;
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

function scheduleSync(delay = SYNC_INTERVAL_MS){
  window.clearTimeout(syncTimer);
  if (!appReady && delay > 0) return;
  syncTimer = window.setTimeout(async () => {
    await syncNow({ announce:true });
    scheduleSync(SYNC_INTERVAL_MS);
  }, Math.max(0, delay));
}

function scheduleResultEnhancement(){
  window.clearTimeout(resultEnhanceTimer);
  resultEnhanceTimer = window.setTimeout(enhanceResultSheet, 40);
}

function enhanceResultSheet(){
  const newOpponent = document.getElementById('newOpponent');
  const goHome = document.getElementById('goHome');
  if (!newOpponent || !goHome || document.querySelector('[data-create-rematch]')) return;

  const game = state.activeGame;
  if (game && String(game.status || '') === 'finished') lastFinishedGame = game;
  const finished = game && String(game.status || '') === 'finished' ? game : lastFinishedGame;
  if (!finished?.id || finished.is_bot_game || !Array.isArray(finished.players) || finished.players.length !== 2) return;

  const button = document.createElement('button');
  button.className = 'btn primary full';
  button.type = 'button';
  button.dataset.createRematch = String(finished.id);
  button.textContent = 'Предложить реванш';
  newOpponent.classList.remove('primary');
  newOpponent.classList.add('ghost');
  newOpponent.insertAdjacentElement('beforebegin', button);
}

function dispatchNotificationsRefresh(){
  document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'));
}

function dispatchNotificationCount(unreadCount){
  if (!Number.isFinite(Number(unreadCount))) return;
  document.dispatchEvent(new CustomEvent('mgw:notification-count', {
    detail:{ unreadCount:Number(unreadCount) },
  }));
}

function syncState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function setInviteButtonsDisabled(disabled){
  document.querySelectorAll('[data-invite-action]').forEach(button => { button.disabled = disabled; });
}

function actionText(action){
  return {
    accept:'Принимаем…',
    start:'Запускаем…',
    decline:'Отклоняем…',
    cancel:'Отменяем…',
  }[action] || 'Подождите…';
}

function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn' || id.startsWith('play') || Boolean(target?.closest?.('[data-invite-friend]'));
}

function openSheetInviteToken(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
}

function openSheetInviteState(){
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-state]')?.dataset.inviteState || '');
}

function inviteSheetState(invite){
  const role = invite?.is_owner ? 'owner' : (invite?.is_invitee ? 'invitee' : 'guest');
  return `${String(invite?.status || '')}:${role}`;
}

function inviteMarker(invite){
  return `<span data-invite-sheet data-invite-token="${escapeHtml(invite?.token || '')}" data-invite-state="${escapeHtml(inviteSheetState(invite))}" hidden></span>`;
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite?.room_label || roomLabel(invite?.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(inviteBoardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

async function inviteRequest(action, payload = {}){
  return postJson(INVITES_URL, { action, ...payload });
}

async function postJson(url, payload){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}

function incomingToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function isTerminal(status){
  return ['declined', 'cancelled', 'expired', 'timed_out'].includes(String(status || ''));
}

function isDraft(invite){
  return String(invite?.status || '') === 'draft';
}

function terminalTitle(status){
  return {
    declined:'Приглашение отклонено',
    cancelled:'Приглашение отменено',
    expired:'Срок приглашения истёк',
    timed_out:'Время ожидания истекло',
  }[String(status || '')] || 'Приглашение закрыто';
}

function gameTitle(gameType){
  return GAME_OPTIONS[gameType]?.title || 'Игра';
}

function boardLabel(gameType, size){
  if (gameType === 'four_in_a_row') return `${size}×${size - 1}${size === 7 ? ' · классика' : ''}`;
  if (gameType === 'domino') return 'Классика 0–6';
  return `${size}×${size}`;
}

function inviteBoardLabel(invite){
  if (String(invite?.game_type || '') === 'four_in_a_row') {
    return `${Number(invite?.board_columns || invite?.board_size || 0)}×${Number(invite?.board_rows || 0)}`;
  }
  return boardLabel(String(invite?.game_type || ''), Number(invite?.board_size || 0));
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
}

function formatTime(value){
  const date = new Date(String(value || ''));
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}

function initials(name){
  const cleaned = String(name || 'И').replace(/^@/, '').replace(/[_-]+/g, ' ').trim();
  const parts = cleaned.split(/\s+/).filter(Boolean);
  return (parts[0]?.[0] || 'И') + (parts[1]?.[0] || parts[0]?.[1] || '');
}

function avatarHue(value){
  let hash = 0;
  for (const char of String(value || '')) hash = ((hash << 5) - hash + char.charCodeAt(0)) | 0;
  return Math.abs(hash) % 360;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
