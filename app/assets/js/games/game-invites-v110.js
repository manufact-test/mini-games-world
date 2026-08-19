import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { openSheet, closeSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { renderBalances } from '../ui.js?v=27';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const WATCH_URL = `${window.location.origin}/bot/invite-watch.php`;
const IDLE_SYNC_INTERVAL_MS = 1500;
const ACTIVE_SYNC_INTERVAL_MS = 500;
const WATCH_INTERVAL_MS = 400;
const SHARE_CALLBACK_TIMEOUT_MS = 12000;
const SHARE_WARM_DELAY_MS = 40;
const SHARE_WARM_KEEPALIVE_MS = 180000;
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
let watchTimer = null;
let watchBusy = false;
let seenWatchSignals = new Set();
let currentInvite = null;
let deepLinkHandled = false;
let eventBaselineReady = false;
let seenInviteEventIds = new Set();
let resultObserver = null;
let resultEnhanceTimer = null;
let lastFinishedGame = null;
let shareWarmSequence = 0;
let shareWarmTimer = null;
let shareWarmExpiryTimer = null;
let shareWarm = null;
let shareWarmSerial = Promise.resolve();
let shareAttempt = null;
let shareClickPending = false;
let socialInviteTarget = null;
let playerPickerRequestGeneration = 0;
let directInviteRequestGeneration = 0;
const directInviteCancelIntents = new Set();
const rematchPendingGameIds = new Set();
let inviteStartPending = false;
let inviteUiTransitionGeneration = 0;

export function initGameInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('pointerdown', handleInvitePointerDown, true);
  document.addEventListener('click', handleDocumentClick, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      syncNow({ announce:true });
      scheduleSync(0);
      scheduleWatch(0);
    }
  });
  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    scheduleSync(0);
    scheduleWatch(0);
  }, { once:true });
  document.addEventListener('mgw:game-dismissed', () => {
    window.setTimeout(() => syncNow({ announce:true }), 80);
    scheduleWatch(80);
  });
  document.addEventListener('mgw:sheet-closed', () => {
    playerPickerRequestGeneration += 1;
    socialInviteTarget = null;
    if (isPassiveOwnerPending(currentInvite)) currentInvite = null;
    if (!shareAttempt?.nativePending) cancelWarmShareDraft();
  });
  document.addEventListener('mgw:before-game-launch', event => {
    if (hasActionableInvite()) {
      event.preventDefault();
      openCurrentInvite();
      return;
    }
    cancelWarmShareDraft();
  }, true);

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
        scheduleWatch(0);
      });
      tg.onEvent('shareMessageSent', () => settleNativeShare(true));
      tg.onEvent('shareMessageFailed', event => {
        settleNativeShare(false, String(event?.error || 'UNKNOWN_ERROR'));
      });
    } catch (error) {
      // Older Telegram clients do not expose this event.
    }
  }
}

export function openSocialPlayerInvite(inviteeId, opponentName = 'Игрок'){
  const id = String(inviteeId || '').trim();
  const name = String(opponentName || 'Игрок').trim() || 'Игрок';
  if (!id) return;
  if (hasActionableInvite()) {
    openCurrentInvite();
    return;
  }

  socialInviteTarget = { id, name };
  haptic('light');
  openSheet(`
    <div class="sheet-head">
      <div><h2>Пригласить ${escapeHtml(name)}</h2><p>Выберите игру.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="choice-grid" data-social-invite-games>
      ${Object.entries(GAME_OPTIONS).map(([gameType, option]) => `<button class="choice" data-social-invite-game="${escapeHtml(gameType)}" type="button">${escapeHtml(option.title)}</button>`).join('')}
    </div>
  `);
  document.querySelectorAll('[data-social-invite-game]').forEach(button => {
    button.addEventListener('click', () => openInviteSetup(String(button.dataset.socialInviteGame || 'tictactoe')));
  });
}

export async function openIncomingInviteIfPresent(){
  const token = incomingToken();
  if (token && !deepLinkHandled) {
    deepLinkHandled = true;
    try {
      const result = await inviteRequest('open_link', { token });
      syncState(result);
      currentInvite = result.invite || null;
      announceLinkedInviteNotification(result, token);
    } catch (error) {
      toast(error.message || 'Приглашение уже недоступно.');
    }
  }

  await syncNow({ announce:false });
  scheduleSync(nextSyncInterval());
  scheduleWatch(0);
}

function handleInvitePointerDown(event){
  const trigger = event.target instanceof Element ? event.target.closest('[data-invite-friend]') : null;
  if (!trigger || hasActionableInvite()) return;
  const gameType = String(trigger.dataset.inviteFriend || 'tictactoe');
  scheduleWarmShareDraft(defaultInviteContext(gameType), 0);
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
  if (launchTarget && hasActionableInvite() && isGameLaunchControl(launchTarget)) {
    event.preventDefault();
    event.stopImmediatePropagation();
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
  if (hasActionableInvite()) return openCurrentInvite();

  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  let boardSize = Number(preserved?.boardSize || option.defaultSize);
  const bet = Number(APP_CONFIG.matchBet);

  haptic('light');
  openSheet(`
    <span data-invite-setup hidden></span>
    <div class="sheet-head">
      <div>
        <h2>Пригласить в «${escapeHtml(option.title)}»</h2>
        <p>Выберите вариант игры.</p>
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
      <div class="choice-grid single-choice" data-invite-bets>
        <button class="choice active" data-invite-bet="${bet}" type="button">${bet} коинов</button>
      </div>
    </div>

    <div class="stack invite-actions">
      ${socialInviteTarget
        ? `<button class="btn primary full" data-send-social-invite type="button">Пригласить ${escapeHtml(socialInviteTarget.name)}</button>`
        : `<button class="btn primary full" data-open-player-picker type="button">Пригласить игрока</button>
           <button class="btn ghost full" data-create-link-invite type="button">Поделиться ссылкой</button>`}
    </div>
    <div class="invite-method-note">${socialInviteTarget ? 'Приглашение получит выбранный игрок.' : 'Игроку из списка приглашение сразу придёт в приложение. Ссылка нужна для нового человека.'}</div>
  `);

  const currentContext = () => normalizeInviteContext({ gameType, boardSize, bet });
  document.querySelectorAll('[data-invite-size]').forEach(button => button.addEventListener('click', () => {
    boardSize = Number(button.dataset.inviteSize || option.defaultSize);
    document.querySelectorAll('[data-invite-size]').forEach(item => item.classList.toggle('active', item === button));
    if (!socialInviteTarget) scheduleWarmShareDraft(currentContext());
  }));

  const selectedSocialTarget = socialInviteTarget ? { ...socialInviteTarget } : null;
  if (selectedSocialTarget) {
    document.querySelector('[data-send-social-invite]')?.addEventListener('click', event => {
      socialInviteTarget = null;
      void createDirectInvite(currentContext(), selectedSocialTarget.id, event.currentTarget, selectedSocialTarget.name);
    });
  } else {
    document.querySelector('[data-open-player-picker]')?.addEventListener('click', event => {
      openPlayerPicker(currentContext(), event.currentTarget);
    });
    document.querySelector('[data-create-link-invite]')?.addEventListener('click', event => createLinkDraft(currentContext(), event.currentTarget));
    scheduleWarmShareDraft(currentContext(), 0);
  }
}

async function openPlayerPicker(context, sourceButton = null){
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
      <div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))}</p></div>
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

async function createDirectInvite(context, inviteeId, button, opponentNameOverride = ''){
  if (!inviteeId || button.disabled) return;
  haptic('light');
  const opponentName = String(opponentNameOverride || button.querySelector('strong')?.textContent || 'Игрок').trim() || 'Игрок';
  const requestGeneration = ++directInviteRequestGeneration;

  showDirectInvitePending(context, opponentName, requestGeneration);

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    syncState(result);
    currentInvite = result.invite || null;
    if (!currentInvite?.token) throw new Error('Не удалось создать приглашение.');

    if (directInviteCancelIntents.has(requestGeneration)) {
      await settleQueuedDirectInviteCancel(currentInvite, requestGeneration);
      return;
    }

    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {
      finalizeDirectInvitePendingSurface(currentInvite, requestGeneration);
    } else if (isPassiveOwnerPending(currentInvite)) {
      currentInvite = null;
    }

    dispatchNotificationCount(result.unread_count);
    scheduleSync(0);
    window.setTimeout(cancelWarmShareDraft, 180);
  } catch (error) {
    if (directInviteCancelIntents.has(requestGeneration)) {
      directInviteCancelIntents.delete(requestGeneration);
      currentInvite = null;
      scheduleSync(0);
      scheduleWatch(0);
      return;
    }
    toast(error.message || 'Не удалось отправить приглашение.');
    if (isDirectInvitePendingSurfaceOpen(requestGeneration)) {
      if (opponentNameOverride) {
        socialInviteTarget = { id:String(inviteeId), name:opponentName };
        openInviteSetup(context.gameType, context);
      } else {
        await openPlayerPicker(context);
      }
    }
  }
}

async function settleQueuedDirectInviteCancel(invite, requestGeneration){
  const token = String(invite?.token || '');
  if (!token) return;
  try {
    const result = await inviteRequest('cancel', { token });
    syncState(result);
    const unreadCount = Number(result?.unread_count);
    consumeInviteNotification(token, Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : null);
    if (Number.isFinite(unreadCount)) dispatchNotificationCount(Math.max(0, unreadCount));
    currentInvite = null;
    scheduleSync(0);
    scheduleWatch(0);
  } catch (error) {
    currentInvite = invite;
    showOwnerWaiting(invite, 'Не удалось отменить приглашение. Попробуйте ещё раз.');
    toast(error.message || 'Не удалось отменить приглашение.');
  } finally {
    directInviteCancelIntents.delete(requestGeneration);
  }
}

async function createLinkDraft(context, button){
  if (shareClickPending || shareAttempt?.nativePending) return;
  shareClickPending = true;
  haptic('light');

  try {
    const result = await obtainPreparedShareResult(context);
    syncState(result);
    const draftInvite = result?.invite || null;
    const draftToken = String(draftInvite?.token || '');
    if (!draftToken) throw new Error('Не удалось подготовить ссылку.');

    const tg = getTelegram();
    const preparedId = String(draftInvite.prepared_message_id || '');
    if (preparedId && typeof tg?.shareMessage === 'function') {
      openNativeShare(tg, draftInvite, context);
      return;
    }

    // Keep the accepted in-app fallback owner. A missing/unsupported native
    // prepared-share surface must never auto-jump the user into t.me/share/url.
    currentInvite = draftInvite;
    showPreparedLink(draftInvite, context);
  } catch (error) {
    if (String(error?.name || '') !== 'AbortError') {
      toast(error.message || 'Не удалось подготовить приглашение.');
    }
  } finally {
    shareClickPending = false;
  }
}

function scheduleWarmShareDraft(context, delay = SHARE_WARM_DELAY_MS){
  window.clearTimeout(shareWarmTimer);
  shareWarmTimer = window.setTimeout(() => {
    void warmShareDraft(context).catch(() => null);
  }, Math.max(0, Number(delay || 0)));
}

function warmShareDraft(context){
  const normalized = normalizeInviteContext(context);
  const key = inviteContextKey(normalized);
  if (shareWarm?.key === key && ['queued','loading','ready'].includes(String(shareWarm.status || ''))) {
    if (shareWarm.status === 'ready') armWarmShareExpiry(shareWarm);
    return shareWarm.promise;
  }

  const previous = shareWarm;
  const entry = {
    id:++shareWarmSequence,
    key,
    context:normalized,
    status:'queued',
    result:null,
    promise:null,
  };
  shareWarm = entry;

  if (previous?.status === 'ready' && previous.result?.invite?.token) {
    window.clearTimeout(shareWarmExpiryTimer);
    void discardDraft(previous.result.invite);
  }
  entry.promise = shareWarmSerial = shareWarmSerial
    .catch(() => null)
    .then(async () => {
      if (shareWarm?.id !== entry.id) return null;
      entry.status = 'loading';
      // PreparedInlineMessage is warmed before the user taps Share so the
      // accepted Telegram shareMessage surface remains the visible owner.
      const result = await inviteRequest('create_link_draft', { ...normalized, prepareMessage:true }, { prefetch:true });
      if (!result?.invite?.token) throw new Error('Не удалось подготовить ссылку.');
      if (shareWarm?.id !== entry.id) {
        void discardDraft(result.invite);
        return null;
      }
      entry.result = result;
      entry.status = 'ready';
      armWarmShareExpiry(entry);
      return result;
    })
    .catch(error => {
      entry.status = 'failed';
      if (shareWarm?.id === entry.id) shareWarm = null;
      throw error;
    });

  return entry.promise;
}

function cancelWarmShareDraft(){
  window.clearTimeout(shareWarmTimer);
  window.clearTimeout(shareWarmExpiryTimer);
  shareWarmTimer = null;
  shareWarmExpiryTimer = null;
  const warm = shareWarm;
  shareWarm = null;
  if (warm?.status === 'ready' && warm.result?.invite?.token) {
    void discardDraft(warm.result.invite);
  }
}

function armWarmShareExpiry(entry){
  window.clearTimeout(shareWarmExpiryTimer);
  shareWarmExpiryTimer = window.setTimeout(() => {
    if (shareWarm?.id !== entry?.id || shareAttempt?.nativePending) return;
    const stale = shareWarm;
    shareWarm = null;
    shareWarmExpiryTimer = null;
    if (stale?.status === 'ready' && stale.result?.invite?.token) void discardDraft(stale.result.invite);
  }, SHARE_WARM_KEEPALIVE_MS);
}

function restoreWarmShareDraft(attempt){
  const invite = cloneInvite(attempt?.invite);
  const token = String(invite?.token || '');
  const preparedId = String(invite?.prepared_message_id || '');
  if (!token || !preparedId) {
    scheduleWarmShareDraft(attempt?.context || defaultInviteContext('tictactoe'), 0);
    return;
  }

  const context = normalizeInviteContext(attempt.context);
  const result = { invite };
  const entry = {
    id:++shareWarmSequence,
    key:inviteContextKey(context),
    context,
    status:'ready',
    result,
    promise:Promise.resolve(result),
  };
  shareWarm = entry;
  armWarmShareExpiry(entry);
}

async function obtainPreparedShareResult(context){
  const normalized = normalizeInviteContext(context);
  const key = inviteContextKey(normalized);
  let warm = shareWarm;
  if (!warm || warm.key !== key) {
    await warmShareDraft(normalized);
    warm = shareWarm;
  }
  if (!warm?.promise) throw new Error('Не удалось подготовить ссылку.');
  const result = await warm.promise;
  if (!result?.invite?.token) throw new Error('Не удалось подготовить ссылку.');
  if (shareWarm?.id === warm.id) {
    shareWarm = null;
    window.clearTimeout(shareWarmExpiryTimer);
    shareWarmExpiryTimer = null;
  }
  return result;
}

function openNativeShare(tg, invite, context){
  const preparedId = String(invite?.prepared_message_id || '');
  if (!preparedId) return showPreparedLink(invite, context);

  const attempt = {
    id:++shareWarmSequence,
    invite:cloneInvite(invite),
    context:normalizeInviteContext(context),
    nativePending:true,
    settled:false,
    timeout:null,
  };
  shareAttempt = attempt;
  attempt.timeout = window.setTimeout(() => {
    if (attempt.settled) return;
    attempt.settled = true;
    attempt.nativePending = false;
    if (shareAttempt?.id === attempt.id) shareAttempt = null;
  }, SHARE_CALLBACK_TIMEOUT_MS);

  try {
    tg.shareMessage(preparedId, result => {
      settleNativeShare(Boolean(result), result === false ? 'USER_DECLINED' : '', attempt);
    });
  } catch (error) {
    window.clearTimeout(attempt.timeout);
    shareAttempt = null;
    currentInvite = invite;
    showPreparedLink(invite, context);
  }
}

function settleNativeShare(sent, errorCode = '', targetAttempt = null){
  const attempt = targetAttempt || shareAttempt;
  if (!attempt?.nativePending || attempt.settled) return;
  attempt.settled = true;
  attempt.nativePending = false;
  window.clearTimeout(attempt.timeout);
  if (shareAttempt?.id === attempt.id) shareAttempt = null;

  const token = String(attempt.invite?.token || '');
  if (!token) return;

  if (sent === true) {
    void confirmSharedInvite(attempt);
    return;
  }

  if (String(errorCode || '') === 'USER_DECLINED' || String(errorCode || '') === '') {
    restoreWarmShareDraft(attempt);
    return;
  }

  if (String(errorCode || '') === 'UNSUPPORTED' || String(errorCode || '') === 'MESSAGE_EXPIRED') {
    currentInvite = attempt.invite;
    showPreparedLink(attempt.invite, attempt.context);
    return;
  }

  void discardDraft(attempt.invite);
  toast('Не удалось отправить приглашение. Попробуйте ещё раз.');
}

async function confirmSharedInvite(attempt){
  try {
    const result = await inviteRequest('confirm_shared', { token:String(attempt.invite?.token || '') });
    syncState(result);
    currentInvite = result.invite || attempt.invite;
    showOwnerWaiting(currentInvite);
    scheduleSync(0);
  } catch (error) {
    scheduleSync(0);
  }
}

function discardDraft(invite){
  const token = String(invite?.token || '');
  if (!token) return Promise.resolve();
  return inviteRequest('discard_draft', { token }).catch(() => null);
}

function isInviteSetupOpen(){
  return Boolean(
    document.getElementById('sheetOverlay')?.classList.contains('active')
      && document.querySelector('#sheet [data-invite-setup]')
  );
}

function normalizeInviteContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  return {
    gameType,
    room:'match',
    boardSize:Number(value?.boardSize || option.defaultSize),
    bet:Number(APP_CONFIG.matchBet),
  };
}

function defaultInviteContext(gameType){
  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  return normalizeInviteContext({ gameType, boardSize:option.defaultSize, bet:APP_CONFIG.matchBet });
}

function inviteContextKey(context){
  const normalized = normalizeInviteContext(context);
  return `${normalized.gameType}|${normalized.boardSize}|${normalized.bet}`;
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

async function performInviteAction(action, token, button){
  if (!action || !token || button.disabled) return;
  inviteUiTransitionGeneration += 1;
  haptic('light');
  const originalText = button.textContent;
  const rollbackInvite = inviteForAction(token, button) || cloneInvite(currentInvite);
  if (rollbackInvite?.token) currentInvite = cloneInvite(rollbackInvite);
  const rollbackHtml = String(document.getElementById('sheet')?.innerHTML || '');
  const terminalContext = terminalActionContext(button, action, token);
  const optimisticNotificationTerminal = terminalContext.notificationSurface
    && (action === 'decline' || action === 'cancel');
  const optimisticParticipantCancel = action === 'cancel'
    && !terminalContext.notificationSurface
    && String(rollbackInvite?.token || '') === token
    && (Boolean(rollbackInvite?.is_owner) || Boolean(rollbackInvite?.is_invitee));
  setInviteButtonsDisabled(true);
  button.textContent = actionText(action);
  if (action === 'start') beginInviteStartTransition();

  if (action === 'accept') {
    showInviteeWaiting({
      ...(rollbackInvite || {}),
      token,
      status:'accepted',
      is_owner:false,
      is_invitee:true,
      ready_deadline_at:String(rollbackInvite?.ready_deadline_at || ''),
    });
  } else if (optimisticNotificationTerminal) {
    closeSheet();
    document.dispatchEvent(new CustomEvent('mgw:notification-remove', {
      detail:{ inviteToken:token },
    }));
  } else if (optimisticParticipantCancel) {
    closeSheet();
    showScreen('home');
  }

  try {
    const result = await inviteRequest(action, { token });
    syncState(result);
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result.game);
      if (action === 'start') endInviteStartTransition(false);
      return;
    }
    currentInvite = result.invite || currentInvite;

    if (action === 'accept') {
      if (!reconcileInviteeWaiting(currentInvite)) showInviteeWaiting(currentInvite);
      scheduleSync(0);
      return;
    }
    if (action === 'decline' || action === 'cancel') {
      const terminalInvite = terminalInviteResult(action, token, result?.invite || rollbackInvite);
      const unreadCount = Number(result?.unread_count);
      const selfCancelledParticipant = action === 'cancel'
        && !terminalContext.notificationSurface
        && String(terminalInvite?.token || '') === token
        && (Boolean(terminalInvite?.is_owner) || Boolean(terminalInvite?.is_invitee));

      if (terminalContext.notificationSurface) {
        document.dispatchEvent(new CustomEvent('mgw:notification-remove', {
          detail:{
            inviteToken:token,
            unreadCount:Number.isFinite(unreadCount) ? Math.max(0, unreadCount) : null,
          },
        }));
        dispatchNotificationsRefresh();
      } else if (selfCancelledParticipant) {
        consumeInviteNotification(token, unreadCount);
        if (!optimisticParticipantCancel) {
          closeSheet();
          showScreen('home');
        }
      } else {
        showTerminalInvite(terminalInvite);
      }

      if (Number.isFinite(unreadCount)) dispatchNotificationCount(Math.max(0, unreadCount));
      currentInvite = null;
      scheduleSync(0);
      scheduleWatch(0);
      return;
    }
    if (action === 'start') {
      endInviteStartTransition(true);
      return;
    }

    setInviteButtonsDisabled(false);
    button.textContent = originalText;
  } catch (error) {
    if (action === 'start') endInviteStartTransition(true);
    currentInvite = rollbackInvite;
    if (terminalContext.notificationSurface) dispatchNotificationsRefresh();
    else if (rollbackHtml) openSheet(rollbackHtml);
    toast(error.message || 'Не удалось выполнить действие.');
    setInviteButtonsDisabled(false);
    const restored = [...document.querySelectorAll('[data-invite-action][data-invite-token]')].find(candidate =>
      String(candidate.dataset.inviteAction || '') === action
        && String(candidate.dataset.inviteToken || '') === token
    );
    if (restored instanceof HTMLButtonElement) restored.textContent = originalText;
  }
}

function beginInviteStartTransition(){
  inviteStartPending = true;
  window.clearTimeout(syncTimer);
  syncTimer = null;
}

function endInviteStartTransition(resumeSync){
  inviteStartPending = false;
  if (resumeSync) scheduleSync(0);
}

function inviteForAction(token, button){
  const current = String(currentInvite?.token || '') === token ? cloneInvite(currentInvite) : null;
  const raw = String(button?.dataset?.inviteSnapshot || '');
  if (!raw) return current;
  try {
    const snapshot = JSON.parse(raw);
    if (!snapshot || typeof snapshot !== 'object' || String(snapshot.token || '') !== token) return current;
    return { ...(current || {}), ...snapshot, token };
  } catch (error) {
    return current;
  }
}

function terminalActionContext(button, action, token){
  const card = button.closest('[data-notification-id][data-notification-invite-token]');
  const notificationSurface = Boolean(
    card
      && card.closest('#sheet')?.querySelector('[data-notifications-owner]')
      && String(card.getAttribute('data-notification-invite-token') || '') === token
  );

  return {
    action,
    token,
    notificationSurface,
    notificationId:String(card?.getAttribute('data-notification-id') || ''),
    notificationType:String(card?.getAttribute('data-notification-type') || ''),
  };
}

function terminalInviteResult(action, token, value){
  const invite = cloneInvite(value) || {};
  const status = String(invite.status || (action === 'decline' ? 'declined' : 'cancelled'));
  return {
    ...invite,
    token,
    status,
    status_label:String(invite.status_label || terminalTitle(status)),
  };
}

function terminalNotificationItem(context, invite){
  const status = String(invite?.status || (context.action === 'decline' ? 'declined' : 'cancelled'));
  const fallbackType = context.action === 'decline'
    ? (String(invite?.source || '') === 'rematch' ? 'invite_rematch_received' : 'invite_received')
    : 'invite_accepted';

  return {
    id:context.notificationId || `local_invite_${context.token}`,
    type:context.notificationType || fallbackType,
    title:String(invite?.status_label || terminalTitle(status)),
    message:'',
    tone:'warning',
    invite_token:context.token,
    invite_status:status,
    invite_is_owner:Boolean(invite?.is_owner),
    actions:[],
    read:true,
    created_at:String(invite?.updated_at || invite?.created_at || new Date().toISOString()),
  };
}

async function createRematch(gameId, button){
  if (!gameId || rematchPendingGameIds.has(gameId)) return;
  rematchPendingGameIds.add(gameId);
  inviteUiTransitionGeneration += 1;
  haptic('light');

  const rollbackHtml = String(document.getElementById('sheet')?.innerHTML || '');
  const finished = String(lastFinishedGame?.id || '') === gameId
    ? lastFinishedGame
    : (String(state.activeGame?.id || '') === gameId ? state.activeGame : null);
  const gameType = String(finished?.game_type || finished?.type || state.selectedGame || 'tictactoe');
  const boardSize = Number(finished?.board_size || GAME_OPTIONS[gameType]?.defaultSize || 3);
  const bet = Number(finished?.bet || APP_CONFIG.matchBet || 0);

  openSheet(`
    <span data-rematch-pending="${escapeHtml(gameId)}" hidden></span>
    <div class="sheet-head">
      <div><h2>Реванш предложен</h2><p>${escapeHtml(gameTitle(gameType))}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${contextSummary({ gameType, boardSize, bet })}
    <div class="small-note invite-status-note">Ждём ответа соперника.</div>
  `);

  try {
    const result = await inviteRequest('rematch', { gameId });
    syncState(result);
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result.game);
      return;
    }

    currentInvite = result.invite || null;
    if (!currentInvite?.token) throw new Error('Не удалось создать реванш.');
    const optimisticSurfaceOpen = String(
      document.querySelector('#sheet [data-rematch-pending]')?.dataset.rematchPending || ''
    ) === gameId;
    state.activeGame = null;
    showScreen('home');
    if (optimisticSurfaceOpen) {
      showOwnerWaiting(currentInvite);
    } else if (isPassiveOwnerPending(currentInvite)) {
      currentInvite = null;
    }
    scheduleSync(0);
  } catch (error) {
    const optimisticSurfaceOpen = String(
      document.querySelector('#sheet [data-rematch-pending]')?.dataset.rematchPending || ''
    ) === gameId;
    if (optimisticSurfaceOpen && rollbackHtml) openSheet(rollbackHtml);
    toast(error.message || 'Не удалось предложить реванш.');
  } finally {
    rematchPendingGameIds.delete(gameId);
  }
}

async function syncNow({ announce = true } = {}){
  if (inviteStartPending || syncBusy || document.visibilityState !== 'visible') return null;
  if (String(state.activeGame?.status || '') === 'active') return null;

  const requestedInviteToken = String(currentInvite?.token || '');
  const syncUiTransitionGeneration = inviteUiTransitionGeneration;
  syncBusy = true;
  try {
    const result = await inviteRequest('sync', { token:requestedInviteToken });
    syncState(result);
    if (syncUiTransitionGeneration !== inviteUiTransitionGeneration) return result;
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
    } else if (
      currentInvite
      && !isDraft(currentInvite)
      && String(currentInvite?.token || '') === requestedInviteToken
    ) {
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

function announceLinkedInviteNotification(result, token){
  const unreadCount = Number(result?.unread_count || 0);
  dispatchNotificationCount(unreadCount);
  const item = (Array.isArray(result?.invite_events) ? result.invite_events : []).find(value => {
    return String(value?.invite_token || '') === String(token || '') && !value?.read;
  }) || null;
  const id = String(item?.id || '');
  if (!id) return;
  seenInviteEventIds.add(id);
  document.dispatchEvent(new CustomEvent('mgw:notification-sync', {
    detail:{ item, unreadCount },
  }));
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
  if (isTerminal(status)) {
    consumeInviteNotification(currentInvite.token);
    showTerminalInvite(currentInvite);
  }
}

function showDirectInvitePending(context, opponentName, requestGeneration){
  openSheet(`
    <span data-invite-sheet data-direct-invite-pending="${Number(requestGeneration || 0)}" hidden></span>
    <div class="sheet-head">
      <div><h2>Приглашение отправлено</h2><p>Для ${escapeHtml(opponentName || 'игрока')}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${contextSummary(context)}
    <button class="btn primary full" data-direct-invite-cancel-reserved="${Number(requestGeneration || 0)}" type="button">Отменить приглашение</button>
  `);
  document.querySelector(`[data-direct-invite-cancel-reserved="${Number(requestGeneration || 0)}"]`)?.addEventListener('click', () => {
    requestPendingDirectInviteCancel(requestGeneration);
  });
}

function requestPendingDirectInviteCancel(requestGeneration){
  if (!isDirectInvitePendingSurfaceOpen(requestGeneration)) return;
  directInviteCancelIntents.add(Number(requestGeneration || 0));
  inviteUiTransitionGeneration += 1;
  haptic('light');
  currentInvite = null;
  closeSheet();
  showScreen('home');
}

function isDirectInvitePendingSurfaceOpen(requestGeneration){
  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return false;
  return String(document.querySelector('#sheet [data-direct-invite-pending]')?.dataset.directInvitePending || '')
    === String(Number(requestGeneration || 0));
}

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
  button.removeAttribute('data-direct-invite-cancel-reserved');
  button.dataset.inviteAction = 'cancel';
  button.dataset.inviteToken = token;
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

function contextSummary(context){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(gameTitle(context?.gameType))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel(String(context?.gameType || ''), Number(context?.boardSize || 0)))}</strong></div>
      <div><span>Ставка</span><strong>${Number(context?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function showOwnerWaiting(invite, message = ''){
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head">
      <div><h2>${invite.source === 'rematch' ? 'Реванш предложен' : 'Приглашение отправлено'}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    ${message ? `<div class="small-note invite-status-note">${escapeHtml(message)}</div>` : ''}
    <button class="btn primary full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить приглашение</button>
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
    <div class="small-note invite-status-note">${escapeHtml(inviteeWaitingNote(invite))}</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить участие</button>
  `);
}

function reconcileInviteeWaiting(invite){
  const token = String(invite?.token || '');
  if (!token || openSheetInviteToken() !== token || openSheetInviteState() !== 'accepted:invitee') return false;
  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  const note = document.querySelector('#sheet .invite-status-note');
  if (!marker || !note) return false;
  marker.dataset.inviteState = inviteSheetState(invite);
  note.textContent = inviteeWaitingNote(invite);
  return true;
}

function inviteeWaitingNote(invite){
  const formatted = formatTime(invite?.ready_deadline_at);
  return formatted === '—' ? 'Ожидаем запуск матча.' : `Ожидание до ${formatted}.`;
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
  cancelWarmShareDraft();
  currentInvite = null;
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

function scheduleSync(delay = nextSyncInterval()){
  window.clearTimeout(syncTimer);
  syncTimer = null;
  if (inviteStartPending) return;
  if (!appReady && delay > 0) return;
  syncTimer = window.setTimeout(async () => {
    await syncNow({ announce:true });
    scheduleSync(nextSyncInterval());
  }, Math.max(0, delay));
}

function nextSyncInterval(){
  return currentInvite?.token ? ACTIVE_SYNC_INTERVAL_MS : IDLE_SYNC_INTERVAL_MS;
}

function scheduleWatch(delay = WATCH_INTERVAL_MS){
  window.clearTimeout(watchTimer);
  if (!appReady && delay > 0) return;
  watchTimer = window.setTimeout(async () => {
    await watchIncomingInvite();
    scheduleWatch(WATCH_INTERVAL_MS);
  }, Math.max(0, delay));
}

async function watchIncomingInvite(){
  if (watchBusy || !canWatchInviteSignal()) return null;
  watchBusy = true;
  try {
    const speed = window.__MGW_V101_SPEED__;
    const fetcher = typeof speed?.rawFetch === 'function'
      ? speed.rawFetch
      : window.fetch.bind(window);
    const response = await fetcher(WATCH_URL, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId() }),
      priority:'low',
      cache:'no-store',
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || result.ok === false) return null;

    const invite = result.invite || null;
    const token = String(invite?.token || '');
    const status = String(invite?.status || '');
    const updatedAt = String(invite?.updated_at || '');
    const signalKey = `${token}|${status}|${updatedAt}`;
    if (!token || seenWatchSignals.has(signalKey) || !canWatchInviteSignal()) return null;

    if (seenWatchSignals.size > 100) seenWatchSignals.clear();
    seenWatchSignals.add(signalKey);
    // Runtime-file signal is only a low-latency wake-up. Canonical invite sync
    // remains the single state/UI owner, including while an invite sheet is
    // already open and the same token moves pending -> accepted -> active.
    scheduleSync(0);
    return invite;
  } catch (error) {
    return null;
  } finally {
    watchBusy = false;
  }
}

function canWatchInviteSignal(){
  if (document.visibilityState !== 'visible') return false;
  if (String(state.activeGame?.status || '') === 'active') return false;
  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  const overlayOpen = document.getElementById('sheetOverlay')?.classList.contains('active');
  if (!overlayOpen) return true;
  return Boolean(document.querySelector('#sheet [data-invite-sheet]'));
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

function consumeInviteNotification(inviteToken, unreadCount = null){
  const token = String(inviteToken || '');
  if (!token) return;
  const numericUnread = unreadCount === null || unreadCount === undefined
    ? null
    : Number(unreadCount);
  document.dispatchEvent(new CustomEvent('mgw:notification-consume-invite', {
    detail:{
      inviteToken:token,
      unreadCount:Number.isFinite(numericUnread) ? Math.max(0, numericUnread) : null,
    },
  }));
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

function hasActionableInvite(){
  const status = String(currentInvite?.status || '');
  if (status === 'pending' && isPassiveOwnerPending(currentInvite)) return false;
  return ['pending', 'accepted'].includes(status);
}

function isPassiveOwnerPending(invite){
  return String(invite?.status || '') === 'pending'
    && Boolean(invite?.is_owner);
}

function isGameLaunchControl(target){
  const id = String(target?.id || '');
  return id === 'startSearchBtn' || id.startsWith('play') || Boolean(target?.closest?.('[data-invite-friend]'));
}

function openSheetInviteToken(){
  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return '';
  return String(document.querySelector('#sheet [data-invite-sheet][data-invite-token]')?.dataset.inviteToken || '');
}

function openSheetInviteState(){
  if (!document.getElementById('sheetOverlay')?.classList.contains('active')) return '';
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
      <div><span>Вариант</span><strong>${escapeHtml(inviteBoardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div>
    </div>
  `;
}

async function inviteRequest(action, payload = {}, options = {}){
  return postJson(INVITES_URL, { action, ...payload }, options);
}
async function postJson(url, payload, options = {}){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({
      initData:getInitData(),
      sessionId:getSessionId(),
      ...payload,
    }),
    signal:options.signal,
    priority:'high',
    cache:'no-store',
    mgwPrefetch:Boolean(options.prefetch),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}

function cloneInvite(value){
  if (!value || typeof value !== 'object') return value || null;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
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

function roomLabel(){
  return 'Обычный матч';
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