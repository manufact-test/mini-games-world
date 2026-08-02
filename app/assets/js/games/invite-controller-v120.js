import { state } from '../state.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { openSheet, closeSheet } from '../components/sheet.js?v=1109';
import { toast } from '../components/toast.js?v=1109';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { startGamePolling } from '../screens/game-screen.js?v=74';
import { renderBalances } from '../ui.js?v=27';
import {
  createInviteControllerState,
  beginControllerRequest,
  canApplyControllerResponse,
  applyInviteSnapshot,
  applyNotificationSnapshot,
  beginEntryResolution,
  applyEntrySnapshot,
  failEntryResolution,
  shouldStartBackgroundLoops,
  shouldAnnounceNotification,
  markNotificationAnnounced,
  removeInviteNotifications,
  upsertNotification,
  sortedNotifications,
  findNotificationByToken,
  isActionableActiveInvite,
  normalizeNotification,
  normalizeInvite,
} from './invite-controller-state-v120.js?v=1200';

const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const NOTIFICATIONS_URL = `${window.location.origin}/bot/notifications.php`;
const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const INVITE_SYNC_MS = 700;
const NOTIFICATION_SYNC_MS = 20000;
const TOAST_MS = 8000;
const SHARE_CALLBACK_TIMEOUT_MS = 12000;
const DRAFT_TTL_MS = 180000;
const MAX_OPPONENTS = 10;

const GAME_OPTIONS = {
  tictactoe:{ title:'Крестики-нолики', sizes:[3,5,9], defaultSize:3 },
  four_in_a_row:{ title:'4 в ряд', sizes:[6,7,8], defaultSize:7 },
  battleship:{ title:'Морской бой', sizes:[10], defaultSize:10 },
  checkers:{ title:'Шашки', sizes:[8], defaultSize:8 },
  reversi:{ title:'Реверси', sizes:[6,8,10], defaultSize:8 },
  chess:{ title:'Шахматы', sizes:[8], defaultSize:8 },
  go:{ title:'Го', sizes:[9,13], defaultSize:9 },
  domino:{ title:'Домино', sizes:[7], defaultSize:7 },
};

let model = createInviteControllerState('');
let initialized = false;
let appReady = false;
let loopsStarted = false;
let inviteTimer = null;
let notificationTimer = null;
let inviteBusy = false;
let notificationBusy = false;
let actionBusyToken = '';
let setupContext = null;
let draftInvite = null;
let draftExpiryTimer = null;
let keepDraftOnClose = false;
let shareAttempt = null;
let toastTimer = null;
let toastItem = null;
let sheetMode = '';
let sheetToken = '';
let sheetGeneration = 0;
let pinnedNotifications = new Map();
let resultObserver = null;
let resultEnhanceTimer = null;
let lastFinishedGame = null;

export function initInviteController(){
  if (initialized) return;
  initialized = true;
  model = createInviteControllerState(incomingToken());
  beginEntryResolution(model);
  ensureNotificationToast();

  window.addEventListener('click', handleClick, true);
  document.addEventListener('mgw:before-game-launch', handleBeforeGameLaunch, true);
  document.addEventListener('mgw:sheet-closed', handleSheetClosed);
  document.addEventListener('mgw:game-dismissed', () => scheduleInviteSync(0));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible' || !appReady) return;
    if (shouldStartBackgroundLoops(model)) {
      void syncInvites({ announce:true });
      void refreshNotifications({ announce:true });
    }
  });
  document.addEventListener('mgw:app-ready', () => {
    appReady = true;
    if (shouldStartBackgroundLoops(model)) startLoops();
  }, { once:true });

  const tg = getTelegram();
  if (typeof tg?.onEvent === 'function') {
    try {
      tg.onEvent('activated', () => {
        if (!appReady || !shouldStartBackgroundLoops(model)) return;
        void syncInvites({ announce:true });
        void refreshNotifications({ announce:true });
      });
      tg.onEvent('shareMessageSent', () => settleNativeShare(true));
      tg.onEvent('shareMessageFailed', event => settleNativeShare(false, String(event?.error || 'UNKNOWN_ERROR')));
    } catch (error) {
      // Older Telegram clients do not expose these events.
    }
  }

  const sheet = document.getElementById('sheet');
  if (sheet && typeof MutationObserver === 'function') {
    resultObserver = new MutationObserver(scheduleResultEnhancement);
    resultObserver.observe(sheet, { childList:true, subtree:true });
  }
}

export async function openInviteEntry(){
  const token = String(model.entryToken || '');
  if (!token) {
    if (appReady) startLoops();
    return false;
  }
  if (!model.entryPending && model.entryResolved) return Boolean(model.entryInvite?.token);

  try {
    const result = await inviteRequest('open_link', { token });
    applyCommonResult(result);
    const invite = applyEntrySnapshot(model, result);
    mergeInviteEvents(result?.invite_events, false);
    setUnreadCount(model.unreadCount);

    if (!invite?.token) {
      toast('Приглашение уже недоступно.');
      return false;
    }

    showIncomingInvite(invite, { entry:true });
    return true;
  } catch (error) {
    failEntryResolution(model);
    toast(localizedInviteError(error, 'Не удалось открыть приглашение. Откройте ссылку ещё раз.'));
    return false;
  } finally {
    if (appReady) startLoops();
  }
}

function startLoops(){
  if (loopsStarted || !appReady || !shouldStartBackgroundLoops(model)) return;
  loopsStarted = true;
  void syncInvites({ announce:false });
  void refreshNotifications({ announce:false });
  scheduleInviteSync(INVITE_SYNC_MS);
  scheduleNotificationSync(NOTIFICATION_SYNC_MS);
}

function handleBeforeGameLaunch(event){
  if (!isActionableActiveInvite(model.activeInvite)) {
    discardUnusedDraft();
    return;
  }
  event.preventDefault();
  openActiveInvite();
}

function handleClick(event){
  const target = event.target instanceof Element ? event.target : null;
  if (!target) return;

  const toastElement = target.closest('#notificationToast');
  if (toastElement?.classList.contains('show')) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const exact = cloneValue(toastElement.__mgwNotificationItem || toastItem);
    dismissNotificationToast();
    void openNotificationsSheet({ seed:exact ? [exact] : [], source:'toast' });
    return;
  }

  const bell = target.closest('#notificationsOpen');
  if (bell) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void openNotificationsSheet({ seed:sortedNotifications(model), source:'bell' });
    return;
  }

  const actionButton = target.closest('[data-invite-action][data-invite-token]');
  if (actionButton instanceof HTMLButtonElement) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void performInviteAction(
      String(actionButton.dataset.inviteAction || ''),
      String(actionButton.dataset.inviteToken || ''),
      actionButton
    );
    return;
  }

  const rematchButton = target.closest('[data-create-rematch]');
  if (rematchButton instanceof HTMLButtonElement) {
    event.preventDefault();
    event.stopImmediatePropagation();
    void createRematch(String(rematchButton.dataset.createRematch || ''), rematchButton);
    return;
  }

  const sizeButton = target.closest('[data-invite-size]');
  if (sizeButton instanceof HTMLButtonElement && setupContext) {
    event.preventDefault();
    setupContext.boardSize = Number(sizeButton.dataset.inviteSize || setupContext.boardSize);
    document.querySelectorAll('[data-invite-size]').forEach(item => item.classList.toggle('active', item === sizeButton));
    return;
  }

  const betButton = target.closest('[data-invite-bet]');
  if (betButton instanceof HTMLButtonElement && setupContext) {
    event.preventDefault();
    setupContext.bet = Number(betButton.dataset.inviteBet || setupContext.bet);
    document.querySelectorAll('[data-invite-bet]').forEach(item => item.classList.toggle('active', item === betButton));
    return;
  }

  if (target.closest('[data-open-player-picker]')) {
    event.preventDefault();
    discardUnusedDraft();
    void openPlayerPicker(cloneValue(setupContext));
    return;
  }

  const linkButton = target.closest('[data-create-link-invite]');
  if (linkButton instanceof HTMLButtonElement) {
    event.preventDefault();
    void createLinkDraft(cloneValue(setupContext), linkButton);
    return;
  }

  const opponentButton = target.closest('[data-direct-opponent]');
  if (opponentButton instanceof HTMLButtonElement) {
    event.preventDefault();
    void createDirectInvite(cloneValue(setupContext), String(opponentButton.dataset.directOpponent || ''), opponentButton);
    return;
  }

  if (target.closest('[data-back-to-invite-setup]')) {
    event.preventDefault();
    openInviteSetup(String(setupContext?.gameType || 'tictactoe'), setupContext);
    return;
  }

  if (target.closest('[data-fallback-share]')) {
    event.preventDefault();
    openFallbackShare(draftInvite);
    return;
  }

  if (target.closest('[data-copy-invite-link]')) {
    event.preventDefault();
    void copyInviteLink(String(draftInvite?.share_url || ''));
    return;
  }

  if (target.closest('[data-discard-draft]')) {
    event.preventDefault();
    const context = cloneValue(setupContext);
    void discardDraft(draftInvite).finally(() => {
      draftInvite = null;
      keepDraftOnClose = false;
      openInviteSetup(String(context?.gameType || 'tictactoe'), context);
    });
    return;
  }

  const inviteButton = target.closest('[data-invite-friend]');
  if (inviteButton) {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (isActionableActiveInvite(model.activeInvite)) openActiveInvite();
    else openInviteSetup(String(inviteButton.dataset.inviteFriend || 'tictactoe'));
  }
}

function handleSheetClosed(){
  sheetMode = '';
  sheetToken = '';
  sheetGeneration += 1;
  pinnedNotifications.clear();
  dismissNotificationToast();
  if (!keepDraftOnClose && !shareAttempt?.pending) discardUnusedDraft();
}

function openInviteSetup(gameType, preserved = null){
  if (isActionableActiveInvite(model.activeInvite)) return openActiveInvite();
  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  const room = String(preserved?.room || (state.room === 'gold' ? 'gold' : 'match')) === 'gold' ? 'gold' : 'match';
  setupContext = normalizeInviteContext({
    gameType,
    room,
    boardSize:Number(preserved?.boardSize || option.defaultSize),
    bet:Number(preserved?.bet || defaultBet(room)),
  });
  keepDraftOnClose = false;
  sheetMode = 'setup';
  sheetToken = '';
  haptic('light');

  openSheet(`
    <span data-invite-controller="v120" data-invite-setup hidden></span>
    <div class="sheet-head">
      <div><h2>Пригласить в «${escapeHtml(option.title)}»</h2><p>${escapeHtml(roomLabel(room))}. Выберите условия.</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="setup-scroll">
      <div class="small-note">Коины спишутся только после запуска матча.</div>
      <div class="section-title"><h2>Вариант игры</h2></div>
      <div class="choice-grid field-size-grid">
        ${option.sizes.map(size => `<button class="choice ${size === setupContext.boardSize ? 'active' : ''}" data-invite-size="${size}" type="button">${escapeHtml(boardLabel(gameType, size))}</button>`).join('')}
      </div>
      <div class="section-title"><h2>Стоимость участия</h2></div>
      <div class="choice-grid ${room === 'gold' ? '' : 'single-choice'}">
        ${(room === 'gold' ? APP_CONFIG.goldBets : [APP_CONFIG.matchBet]).map(value => `<button class="choice ${Number(value) === setupContext.bet ? 'active' : ''} ${room === 'gold' ? 'gold' : ''}" data-invite-bet="${Number(value)}" type="button">${Number(value)} коинов</button>`).join('')}
      </div>
    </div>
    <div class="stack invite-actions">
      <button class="btn ${room === 'gold' ? 'gold' : 'primary'} full" data-open-player-picker type="button">Пригласить игрока</button>
      <button class="btn ghost full" data-create-link-invite type="button">Поделиться ссылкой</button>
    </div>
    <div class="invite-method-note">Игроку из списка приглашение придёт в приложение. Ссылка нужна для приглашения через Telegram.</div>
  `);
}

async function openPlayerPicker(context){
  if (!context) return;
  setupContext = normalizeInviteContext(context);
  sheetMode = 'picker';
  openSheet(`
    <span data-invite-controller="v120" hidden></span>
    <div class="sheet-head"><div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-loading"><div>👥</div><strong>Загружаем соперников…</strong></div>
  `);

  try {
    const result = await postJson(OPPONENTS_URL, {});
    const items = (Array.isArray(result?.items) ? result.items : []).slice(0, MAX_OPPONENTS);
    items.sort((a,b) => Number(Boolean(b.online)) - Number(Boolean(a.online)));
    renderPlayerPicker(items);
  } catch (error) {
    openSheet(`
      <span data-invite-controller="v120" hidden></span>
      <div class="sheet-head"><div><h2>Не удалось загрузить игроков</h2></div><button class="close" data-close-sheet type="button">×</button></div>
      <div class="small-note">${escapeHtml(localizedInviteError(error, 'Попробуйте ещё раз.'))}</div>
      <button class="btn ghost full" data-back-to-invite-setup type="button">Назад</button>
    `);
  }
}

function renderPlayerPicker(items){
  const list = items.length
    ? `<div class="invite-player-list">${items.map(playerCard).join('')}</div>`
    : '<div class="notifications-empty invite-empty-state"><div>👥</div><strong>Доступных игроков пока нет</strong><span>Вернитесь назад и отправьте ссылку.</span></div>';
  openSheet(`
    <span data-invite-controller="v120" hidden></span>
    <div class="sheet-head"><div><h2>Выберите игрока</h2><p>${escapeHtml(gameTitle(setupContext?.gameType))} · ${escapeHtml(roomLabel(setupContext?.room))}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${list}
    <button class="btn ghost full" data-back-to-invite-setup type="button">Назад к условиям</button>
  `);
}

async function createDirectInvite(context, inviteeId, button){
  if (!context || !inviteeId || button.disabled) return;
  button.disabled = true;
  haptic('light');
  const opponentName = String(button.querySelector('strong')?.textContent || 'Игрок').trim() || 'Игрок';
  showDirectInvitePending(context, opponentName);

  try {
    const result = await inviteRequest('create_direct', { ...context, inviteeId });
    applyCommonResult(result);
    applyInviteSnapshot(model, result, { announce:false });
    mergeInviteEvents(result?.invite_events, false);
    if (!model.activeInvite?.token) throw new Error('Не удалось создать приглашение.');
    showOwnerWaiting(model.activeInvite, result?.telegram_sent
      ? 'Игрок получил приглашение в приложении и сообщение от бота.'
      : 'Игрок получил приглашение в приложении.');
  } catch (error) {
    toast(localizedInviteError(error, 'Не удалось отправить приглашение.'));
    await openPlayerPicker(context);
  }
}

async function createLinkDraft(context, button){
  if (!context || button.disabled || shareAttempt?.pending) return;
  button.disabled = true;
  const originalText = String(button.textContent || 'Поделиться ссылкой');
  button.textContent = 'Готовим ссылку…';
  haptic('light');

  try {
    const result = await inviteRequest('create_link_draft', context);
    applyCommonResult(result);
    const invite = normalizeInvite(result?.invite);
    if (!invite?.token) throw new Error('Не удалось подготовить ссылку.');
    draftInvite = { ...invite, ...cloneValue(result.invite) };
    armDraftExpiry();
    button.disabled = false;
    button.textContent = originalText;

    const tg = getTelegram();
    const preparedId = String(draftInvite.prepared_message_id || '');
    if (preparedId && typeof tg?.shareMessage === 'function') {
      openNativeShare(tg, draftInvite, context);
      return;
    }
    showPreparedLink(draftInvite, context);
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    toast(localizedInviteError(error, 'Не удалось подготовить приглашение.'));
  }
}

function openNativeShare(tg, invite, context){
  const preparedId = String(invite?.prepared_message_id || '');
  if (!preparedId) return showPreparedLink(invite, context);
  shareAttempt = {
    token:String(invite.token || ''),
    invite:cloneValue(invite),
    context:normalizeInviteContext(context),
    pending:true,
    settled:false,
    timeout:null,
  };
  shareAttempt.timeout = window.setTimeout(() => {
    if (!shareAttempt || shareAttempt.settled) return;
    shareAttempt.pending = false;
    shareAttempt = null;
  }, SHARE_CALLBACK_TIMEOUT_MS);

  try {
    tg.shareMessage(preparedId, result => settleNativeShare(Boolean(result), result === false ? 'USER_DECLINED' : ''));
  } catch (error) {
    window.clearTimeout(shareAttempt.timeout);
    shareAttempt = null;
    showPreparedLink(invite, context);
  }
}

function settleNativeShare(sent, errorCode = ''){
  const attempt = shareAttempt;
  if (!attempt?.pending || attempt.settled) return;
  attempt.settled = true;
  attempt.pending = false;
  window.clearTimeout(attempt.timeout);
  shareAttempt = null;

  if (sent) {
    void confirmSharedInvite(attempt);
    return;
  }
  if (['', 'USER_DECLINED'].includes(String(errorCode || ''))) {
    // Native cancellation is intentionally silent. The prepared draft stays
    // available until the sheet closes or its bounded expiry fires.
    return;
  }
  if (['UNSUPPORTED', 'MESSAGE_EXPIRED'].includes(String(errorCode || ''))) {
    showPreparedLink(attempt.invite, attempt.context);
    return;
  }
  void discardDraft(attempt.invite);
  toast('Не удалось отправить приглашение. Попробуйте ещё раз.');
}

async function confirmSharedInvite(attempt){
  try {
    const result = await inviteRequest('confirm_shared', { token:String(attempt?.token || '') });
    applyCommonResult(result);
    applyInviteSnapshot(model, result, { announce:false });
    draftInvite = null;
    keepDraftOnClose = false;
    if (model.activeInvite?.token) showOwnerWaiting(model.activeInvite, 'Приглашение отправлено. Ждём ответа игрока.');
  } catch (error) {
    // The link itself remains authoritative and can bind the draft when opened.
  }
}

function showPreparedLink(invite, context){
  setupContext = normalizeInviteContext(context);
  draftInvite = cloneValue(invite);
  keepDraftOnClose = true;
  sheetMode = 'prepared-link';
  sheetToken = String(invite?.token || '');
  openSheet(`
    <span data-invite-controller="v120" data-invite-token="${escapeHtml(sheetToken)}" hidden></span>
    <div class="sheet-head"><div><h2>Ссылка подготовлена</h2><p>Отправьте её через Telegram.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${inviteSummary(invite)}
    <div class="small-note">Приглашение начнёт ожидать ответ, когда получатель откроет ссылку.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-fallback-share type="button">Открыть список Telegram</button>
      <button class="btn ghost full" data-copy-invite-link type="button">Скопировать ссылку</button>
      <button class="btn ghost full" data-discard-draft type="button">Отменить</button>
    </div>
  `);
}

function openFallbackShare(invite){
  const shareUrl = String(invite?.share_url || '');
  if (!shareUrl) return toast('Ссылка временно недоступна.');
  keepDraftOnClose = true;
  const text = String(invite?.share_text || '').replace(shareUrl, '').trim();
  const url = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent(text)}`;
  try {
    const tg = getTelegram();
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

function discardUnusedDraft(){
  if (!draftInvite?.token || keepDraftOnClose || shareAttempt?.pending) return;
  const stale = draftInvite;
  draftInvite = null;
  void discardDraft(stale);
}

function discardDraft(invite){
  window.clearTimeout(draftExpiryTimer);
  draftExpiryTimer = null;
  const token = String(invite?.token || '');
  if (!token) return Promise.resolve();
  return inviteRequest('discard_draft', { token }).catch(() => null);
}

function armDraftExpiry(){
  window.clearTimeout(draftExpiryTimer);
  draftExpiryTimer = window.setTimeout(() => {
    if (shareAttempt?.pending || keepDraftOnClose) return;
    discardUnusedDraft();
  }, DRAFT_TTL_MS);
}

async function performInviteAction(action, token, button){
  if (!['accept','decline','cancel','start'].includes(action) || !token || actionBusyToken) return;
  actionBusyToken = token;
  haptic('light');
  const terminal = action === 'decline' || action === 'cancel';
  const knownInvite = inviteForToken(token);

  if (terminal) {
    model.suppressedToastTokens.add(token);
    removeInviteNotifications(model, token);
    closeSheet();
    setUnreadCount(model.unreadCount);
  } else if (action === 'accept') {
    showActionLoading('Принимаем приглашение…');
  } else {
    button.disabled = true;
    button.textContent = 'Запускаем…';
  }

  try {
    const result = await inviteRequest(action, { token });
    applyCommonResult(result);
    applyInviteSnapshot(model, result, { announce:false });
    mergeInviteEvents(result?.invite_events, false);
    setUnreadCount(Number(result?.unread_count ?? model.unreadCount));

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      enterGame(result.game);
      return;
    }
    if (action === 'accept') {
      const accepted = normalizeInvite(result?.invite) || normalizeInvite(result?.tracked_invite) || knownInvite;
      if (accepted?.token) showInviteeWaiting({ ...accepted, status:'accepted' });
    } else if (action === 'start') {
      scheduleInviteSync(0);
    }
  } catch (error) {
    if (terminal) {
      void syncInvites({ announce:false });
      void refreshNotifications({ announce:false });
    } else if (knownInvite?.token) {
      showInviteByRole(knownInvite);
    }
    toast(localizedInviteError(error, 'Не удалось выполнить действие.'));
  } finally {
    actionBusyToken = '';
  }
}

async function createRematch(gameId, button){
  if (!gameId || button.disabled) return;
  button.disabled = true;
  button.textContent = 'Предлагаем реванш…';
  try {
    const result = await inviteRequest('rematch', { gameId });
    applyCommonResult(result);
    applyInviteSnapshot(model, result, { announce:false });
    if (result?.game?.id && String(result.game.status || '') === 'active') return enterGame(result.game);
    if (!model.activeInvite?.token) throw new Error('Не удалось создать реванш.');
    state.activeGame = null;
    showScreen('home');
    showOwnerWaiting(model.activeInvite, 'Предложение реванша отправлено.');
  } catch (error) {
    button.disabled = false;
    button.textContent = 'Предложить реванш';
    toast(localizedInviteError(error, 'Не удалось предложить реванш.'));
  }
}

async function syncInvites({ announce = true } = {}){
  if (inviteBusy || !appReady || document.visibilityState !== 'visible' || !shouldStartBackgroundLoops(model)) return null;
  if (String(state.activeGame?.status || '') === 'active') return null;
  inviteBusy = true;
  const ticket = beginControllerRequest(model, 'invites');
  try {
    const result = await inviteRequest('sync', { token:String(model.activeInvite?.token || '') });
    if (!canApplyControllerResponse(model, ticket)) return null;
    applyCommonResult(result);
    const before = normalizeInvite(model.activeInvite);
    const applied = applyInviteSnapshot(model, result, { announce });
    mergeInviteEvents(result?.invite_events, false);
    setUnreadCount(model.unreadCount);

    if (result?.active_game?.id && String(result.active_game.status || '') === 'active') {
      enterGame(result.active_game);
      return result;
    }

    updateInviteSheet(before, model.activeInvite);
    handleFreshNotifications(applied.fresh);
    return result;
  } catch (error) {
    return null;
  } finally {
    inviteBusy = false;
  }
}

async function refreshNotifications({ announce = false } = {}){
  if (notificationBusy || !appReady || document.visibilityState !== 'visible' || !shouldStartBackgroundLoops(model)) return null;
  notificationBusy = true;
  const ticket = beginControllerRequest(model, 'notifications');
  try {
    const result = await notificationRequest(false);
    if (!canApplyControllerResponse(model, ticket)) return null;
    const applied = applyNotificationSnapshot(model, result, { announce });
    setUnreadCount(sheetMode === 'notifications' ? 0 : model.unreadCount);
    if (sheetMode === 'notifications') renderNotificationSheetCurrent();
    handleFreshNotifications(applied.fresh);
    return result;
  } catch (error) {
    if (sheetMode === 'notifications' && !model.notificationsLoaded && !visibleNotifications().length) renderNotificationError();
    return null;
  } finally {
    notificationBusy = false;
  }
}

function mergeInviteEvents(values, announce){
  const fresh = [];
  for (const raw of Array.isArray(values) ? values : []) {
    const item = upsertNotification(model, raw);
    if (announce && item && shouldAnnounceNotification(model, item)) fresh.push(item);
  }
  if (sheetMode === 'notifications') {
    setUnreadCount(0);
    renderNotificationSheetCurrent();
  }
  if (announce) handleFreshNotifications(fresh);
}

function handleFreshNotifications(values){
  for (const raw of Array.isArray(values) ? values : []) {
    const item = normalizeNotification(raw);
    if (!item.id || !shouldAnnounceNotification(model, item)) continue;
    markNotificationAnnounced(model, item.id);

    if (sheetMode === 'invite' && sheetToken && String(item.invite_token || '') === sheetToken
        && ['invite_declined','invite_cancelled'].includes(String(item.type || ''))) {
      showTerminalNotification(item);
      continue;
    }
    if (canShowNotificationToast()) showNotificationToast(item);
  }
}

async function openNotificationsSheet({ seed = [], source = 'bell' } = {}){
  const generation = ++sheetGeneration;
  sheetMode = 'notifications';
  sheetToken = '';
  pinnedNotifications = new Map();
  for (const value of Array.isArray(seed) ? seed : []) {
    const item = normalizeNotification(value);
    if (item.id) pinnedNotifications.set(notificationIdentity(item), item);
  }

  const immediate = visibleNotifications();
  if (immediate.length) renderNotifications(immediate);
  else if (!model.notificationsLoaded) renderNotificationLoading();
  else renderNotifications([]);

  dismissNotificationToast();
  setUnreadCount(0);
  if (source === 'toast') await waitForPaint(generation);
  if (generation !== sheetGeneration || sheetMode !== 'notifications') return;
  await refreshNotifications({ announce:false });
  if (generation !== sheetGeneration || sheetMode !== 'notifications') return;
  void notificationRequest(true).catch(() => null);
}

function visibleNotifications(){
  const merged = new Map();
  for (const item of sortedNotifications(model)) merged.set(notificationIdentity(item), item);
  for (const item of pinnedNotifications.values()) merged.set(notificationIdentity(item), item);
  return [...merged.values()]
    .sort((a,b) => itemTime(b) - itemTime(a) || String(b.id).localeCompare(String(a.id)))
    .slice(0, 40);
}

function renderNotificationSheetCurrent(){
  if (sheetMode !== 'notifications') return;
  const items = visibleNotifications();
  if (items.length || model.notificationsLoaded) renderNotifications(items);
  else renderNotificationLoading();
}

function renderNotificationLoading(){
  openSheet(`
    <span data-invite-controller="v120" data-notifications-sheet hidden></span>
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>
  `);
}

function renderNotifications(values){
  const items = Array.isArray(values) ? values : [];
  const body = items.length
    ? `<div class="notifications-list">${items.map(renderNotification).join('')}</div>`
    : '<div class="notifications-empty"><div>🔔</div><strong>Пока уведомлений нет</strong></div>';
  openSheet(`
    <span data-invite-controller="v120" data-notifications-sheet hidden></span>
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    ${body}
  `);
}

function renderNotificationError(){
  openSheet(`
    <span data-invite-controller="v120" data-notifications-sheet hidden></span>
    <div class="sheet-head"><div><h2>Уведомления</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-empty error"><div>⚠️</div><strong>Не удалось открыть уведомления</strong><span>Попробуйте ещё раз.</span></div>
  `);
}

function renderNotification(item){
  const tone = ['success','danger','warning','info'].includes(item.tone) ? item.tone : 'info';
  const message = notificationMessage(item);
  const actions = Array.isArray(item.actions) ? item.actions : [];
  return `
    <article class="notification-card ${tone}" data-notification-id="${escapeHtml(item.id)}" data-notification-invite-token="${escapeHtml(item.invite_token)}">
      <div class="notification-icon">${notificationIcon(tone, item.type)}</div>
      <div class="notification-copy">
        <div class="notification-head"><strong>${escapeHtml(item.title || 'Уведомление')}</strong><span>${escapeHtml(formatDate(item.created_at))}</span></div>
        ${message ? `<p>${escapeHtml(message)}</p>` : ''}
        ${item.invite_token && actions.length ? `<div class="notification-actions invite-actions">${actions.map(action => `<button class="btn ${['accept','start'].includes(action) ? 'primary' : 'ghost'} full" data-invite-action="${escapeHtml(action)}" data-invite-token="${escapeHtml(item.invite_token)}" type="button">${escapeHtml(actionLabel(action))}</button>`).join('')}</div>` : ''}
      </div>
    </article>
  `;
}

function ensureNotificationToast(){
  let element = document.getElementById('notificationToast');
  if (element) return element;
  element = document.createElement('div');
  element.id = 'notificationToast';
  element.className = 'notification-toast';
  element.setAttribute('role', 'button');
  element.setAttribute('tabindex', '0');
  element.setAttribute('aria-label', 'Открыть уведомления');
  element.innerHTML = '<div class="notification-toast-icon" aria-hidden="true">🔔</div><div class="notification-toast-copy"><strong></strong><span></span></div>';
  (document.getElementById('app') || document.body).appendChild(element);
  element.addEventListener('keydown', event => {
    if (!element.classList.contains('show')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      dismissNotificationToast();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      element.click();
    }
  });
  return element;
}

function showNotificationToast(value){
  if (!canShowNotificationToast()) return false;
  const item = normalizeNotification(value);
  if (!item.id) return false;
  const element = ensureNotificationToast();
  toastItem = cloneValue(item);
  element.__mgwNotificationItem = cloneValue(item);
  window.clearTimeout(toastTimer);
  const tone = ['success','danger','warning','info'].includes(item.tone) ? item.tone : 'info';
  const message = notificationMessage(item);
  element.className = `notification-toast ${tone}`;
  element.querySelector('.notification-toast-icon').textContent = notificationIcon(tone, item.type);
  element.querySelector('.notification-toast-copy strong').textContent = item.title || 'Уведомление';
  const copy = element.querySelector('.notification-toast-copy span');
  copy.textContent = message;
  copy.hidden = !message;
  requestAnimationFrame(() => element.classList.add('show'));
  toastTimer = window.setTimeout(dismissNotificationToast, TOAST_MS);
  haptic(tone === 'danger' ? 'medium' : 'light');
  return true;
}

function dismissNotificationToast(){
  window.clearTimeout(toastTimer);
  toastTimer = null;
  toastItem = null;
  const element = document.getElementById('notificationToast');
  if (!element) return;
  element.__mgwNotificationItem = null;
  element.classList.remove('show','dragging');
  element.style.transform = '';
  element.style.opacity = '';
}

function canShowNotificationToast(){
  if (!appReady || model.entryPending || document.visibilityState !== 'visible') return false;
  if (document.getElementById('sheetOverlay')?.classList.contains('active')) return false;
  const screen = String(document.querySelector('.screen.active')?.dataset.screen || '');
  return ['home','profile'].includes(screen);
}

function updateInviteSheet(previous, next){
  if (sheetMode !== 'invite' || !sheetToken) return;
  const invite = normalizeInvite(next);
  if (invite?.token === sheetToken) {
    showInviteByRole(invite);
    return;
  }
  if (previous?.token === sheetToken && !invite) {
    // The owner may receive a separate decline/cancel notification. Do not
    // replace the visible surface until that authoritative event arrives.
  }
}

function showIncomingInvite(invite, { entry = false } = {}){
  const safe = normalizeInvite(invite) || invite;
  sheetMode = 'invite';
  sheetToken = String(safe?.token || '');
  if (entry) model.suppressedToastTokens.add(sheetToken);
  haptic('light');
  openSheet(`
    ${inviteMarker(safe)}
    <div class="sheet-head"><div><h2>Вас приглашают сыграть</h2><p>От ${escapeHtml(safe?.inviter_name || 'игрока')}</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${inviteSummary(safe)}
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="accept" data-invite-token="${escapeHtml(sheetToken)}" type="button">Принять приглашение</button>
      <button class="btn ghost full" data-invite-action="decline" data-invite-token="${escapeHtml(sheetToken)}" type="button">Отклонить</button>
    </div>
  `);
}

function showOwnerWaiting(invite, message = 'Ждём ответа игрока. Коины пока не списываются.'){
  sheetMode = 'invite';
  sheetToken = String(invite?.token || '');
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head"><div><h2>${invite?.source === 'rematch' ? 'Реванш предложен' : 'Приглашение отправлено'}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">${escapeHtml(message)}</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(sheetToken)}" type="button">Отменить приглашение</button>
  `);
}

function showOwnerReady(invite){
  sheetMode = 'invite';
  sheetToken = String(invite?.token || '');
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head"><div><h2>Соперник согласен</h2><p>${escapeHtml(invite?.invitee_name || 'Игрок')} готов играть.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Запустите матч до ${escapeHtml(formatTime(invite?.ready_deadline_at))}.</div>
    <div class="stack invite-actions">
      <button class="btn primary full" data-invite-action="start" data-invite-token="${escapeHtml(sheetToken)}" type="button">Начать игру</button>
      <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(sheetToken)}" type="button">Отменить</button>
    </div>
  `);
}

function showInviteeWaiting(invite){
  sheetMode = 'invite';
  sheetToken = String(invite?.token || '');
  openSheet(`
    ${inviteMarker(invite)}
    <div class="sheet-head"><div><h2>Приглашение принято</h2><p>Ждём запуска матча от ${escapeHtml(invite?.inviter_name || 'игрока')}.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ожидание до ${escapeHtml(formatTime(invite?.ready_deadline_at))}.</div>
    <button class="btn ghost full" data-invite-action="cancel" data-invite-token="${escapeHtml(sheetToken)}" type="button">Отменить участие</button>
  `);
}

function showInviteByRole(invite){
  const safe = normalizeInvite(invite) || invite;
  const status = String(safe?.status || '');
  if (status === 'pending' && safe?.is_owner) showOwnerWaiting(safe);
  else if (status === 'pending' && safe?.is_invitee) showIncomingInvite(safe);
  else if (status === 'accepted' && safe?.is_owner) showOwnerReady(safe);
  else if (status === 'accepted' && safe?.is_invitee) showInviteeWaiting(safe);
}

function showTerminalNotification(item){
  sheetMode = 'terminal';
  sheetToken = String(item?.invite_token || '');
  openSheet(`
    <span data-invite-controller="v120" data-invite-token="${escapeHtml(sheetToken)}" hidden></span>
    <div class="sheet-head"><div><h2>${escapeHtml(item?.title || 'Приглашение закрыто')}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    ${notificationMessage(item) ? `<div class="small-note">${escapeHtml(notificationMessage(item))}</div>` : ''}
    <button class="btn primary full" data-close-sheet type="button">Понятно</button>
  `);
}

function showActionLoading(text){
  sheetMode = 'action';
  openSheet(`
    <span data-invite-controller="v120" hidden></span>
    <div class="sheet-head"><div><h2>${escapeHtml(text)}</h2></div><button class="close" data-close-sheet type="button">×</button></div>
    <div class="notifications-loading"><div>🎮</div><strong>Подождите…</strong></div>
  `);
}

function showDirectInvitePending(context, opponentName){
  sheetMode = 'invite';
  sheetToken = '';
  openSheet(`
    <span data-invite-controller="v120" hidden></span>
    <div class="sheet-head"><div><h2>Приглашение отправлено</h2><p>${escapeHtml(opponentName)} получит его в приложении.</p></div><button class="close" data-close-sheet type="button">×</button></div>
    ${contextSummary(context)}
    <div class="small-note invite-status-note">Доставляем приглашение игроку…</div>
  `);
}

function openActiveInvite(){
  if (isActionableActiveInvite(model.activeInvite)) showInviteByRole(model.activeInvite);
}

function enterGame(game){
  if (!game?.id || String(game.status || '') !== 'active') return;
  discardUnusedDraft();
  model.activeInvite = null;
  state.activeGame = game;
  closeSheet();
  showScreen('game');
  startGamePolling(game.id);
}

function scheduleInviteSync(delay = INVITE_SYNC_MS){
  window.clearTimeout(inviteTimer);
  if (!loopsStarted) return;
  inviteTimer = window.setTimeout(async () => {
    await syncInvites({ announce:true });
    scheduleInviteSync(INVITE_SYNC_MS);
  }, Math.max(0, Number(delay || 0)));
}

function scheduleNotificationSync(delay = NOTIFICATION_SYNC_MS){
  window.clearTimeout(notificationTimer);
  if (!loopsStarted) return;
  notificationTimer = window.setTimeout(async () => {
    await refreshNotifications({ announce:true });
    scheduleNotificationSync(NOTIFICATION_SYNC_MS);
  }, Math.max(0, Number(delay || 0)));
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

function applyCommonResult(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function inviteForToken(token){
  const target = String(token || '');
  if (String(model.entryInvite?.token || '') === target) return cloneValue(model.entryInvite);
  if (String(model.activeInvite?.token || '') === target) return cloneValue(model.activeInvite);
  const notification = findNotificationByToken(model, target);
  if (!notification) return null;
  return {
    token:target,
    status:String(notification.invite_status || ''),
    is_owner:Boolean(notification.invite_is_owner),
    is_invitee:!notification.invite_is_owner,
    game_title:String(notification.game_title || ''),
  };
}

async function inviteRequest(action, payload = {}){
  return postJson(INVITES_URL, { action, ...payload });
}

async function notificationRequest(markRead){
  return postJson(NOTIFICATIONS_URL, { markRead:Boolean(markRead) });
}

async function postJson(url, payload){
  const response = await fetch(url, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), ...payload }),
    priority:'high',
    cache:'no-store',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) {
    if (response.status === 429) throw new Error('Связь перегружена. Попробуйте ещё раз через несколько секунд.');
    throw new Error(data?.error || 'Сервис временно недоступен.');
  }
  return data;
}

function setUnreadCount(value){
  const safe = Math.max(0, Math.trunc(Number(value || 0)));
  model.unreadCount = safe;
  const button = document.getElementById('notificationsOpen');
  if (!button) return;
  button.dataset.unread = safe > 99 ? '99+' : String(safe);
  button.classList.toggle('has-unread', safe > 0);
  button.setAttribute('aria-label', safe > 0 ? `Уведомления: ${safe} новых` : 'Уведомления');
}

function waitForPaint(generation){
  return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => {
    resolve(generation === sheetGeneration);
  })));
}

function incomingToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function normalizeInviteContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const room = String(value?.room || '') === 'gold' ? 'gold' : 'match';
  const option = GAME_OPTIONS[gameType] || GAME_OPTIONS.tictactoe;
  return {
    gameType,
    room,
    boardSize:Number(value?.boardSize || option.defaultSize),
    bet:Number(value?.bet || defaultBet(room)),
  };
}

function defaultBet(room){
  if (room !== 'gold') return Number(APP_CONFIG.matchBet);
  const selected = Number(state.selectedBet);
  return Number(APP_CONFIG.goldBets.includes(selected) ? selected : APP_CONFIG.goldBets[0]);
}

function playerCard(item){
  const id = String(item?.id || '');
  const name = String(item?.name || 'Игрок');
  const statusClass = item?.busy ? 'busy' : (item?.online ? 'online' : 'offline');
  return `<button class="invite-player-card" data-direct-opponent="${escapeHtml(id)}" type="button"><span class="invite-player-avatar" style="--invite-avatar-hue:${avatarHue(id)}" aria-hidden="true">${escapeHtml(initials(name))}</span><span class="invite-player-copy"><strong>${escapeHtml(name)}</strong><span><i class="invite-player-dot ${statusClass}"></i>${escapeHtml(item?.activity || 'недавний соперник')}</span></span><span class="invite-player-arrow" aria-hidden="true">›</span></button>`;
}

function inviteMarker(invite){
  const role = invite?.is_owner ? 'owner' : (invite?.is_invitee ? 'invitee' : 'guest');
  return `<span data-invite-controller="v120" data-invite-sheet data-invite-token="${escapeHtml(invite?.token || '')}" data-invite-state="${escapeHtml(String(invite?.status || ''))}:${role}" hidden></span>`;
}

function inviteSummary(invite){
  return `<div class="topup-success"><div><span>Игра</span><strong>${escapeHtml(invite?.game_title || 'Игра')}</strong></div><div><span>Комната</span><strong>${escapeHtml(invite?.room_label || roomLabel(invite?.room))}</strong></div><div><span>Вариант</span><strong>${escapeHtml(inviteBoardLabel(invite))}</strong></div><div><span>Ставка</span><strong>${Number(invite?.bet || 0)} коинов</strong></div></div>`;
}

function contextSummary(context){
  return `<div class="topup-success"><div><span>Игра</span><strong>${escapeHtml(gameTitle(context?.gameType))}</strong></div><div><span>Комната</span><strong>${escapeHtml(roomLabel(context?.room))}</strong></div><div><span>Вариант</span><strong>${escapeHtml(boardLabel(String(context?.gameType || ''), Number(context?.boardSize || 0)))}</strong></div><div><span>Ставка</span><strong>${Number(context?.bet || 0)} коинов</strong></div></div>`;
}

function actionLabel(action){
  return { accept:'Принять приглашение', decline:'Отклонить', start:'Начать игру', cancel:'Отменить' }[String(action || '')] || 'Открыть';
}

function notificationIdentity(item){
  const token = String(item?.invite_token || '');
  const type = String(item?.type || '');
  return token && type.startsWith('invite_') ? `${token}|${type}` : String(item?.id || '');
}

function notificationIcon(tone, type){
  if (String(type || '').startsWith('invite_')) return '🎮';
  if (tone === 'success') return '✓';
  return tone === 'danger' || tone === 'warning' ? '!' : 'i';
}

function notificationMessage(item){
  let message = String(item?.message || '').trim();
  const technical = [
    /\s*Баланс уже обновлён\.?/giu,
    /\s*Баланс не изменён\.?/giu,
    /\s*Баланс:\s*-?[\d\s]+\s*→\s*-?[\d\s]+\.?/giu,
    /\s*Статус (?:уже )?обновлён[^.]*\.?/giu,
    /\s*Откройте Mini App[^.]*\.?/giu,
  ];
  for (const pattern of technical) message = message.replace(pattern, ' ');
  return message.replace(/\s+/g, ' ').replace(/\s+([.,!?])/g, '$1').replace(/\.{2,}/g, '.').trim();
}

function localizedInviteError(error, fallback){
  const message = String(error?.message || '').trim();
  if (!message || /database|sql|pdo|storage|runtime|driver|exception|failed:/i.test(message)) return fallback;
  return message;
}

function gameTitle(gameType){ return GAME_OPTIONS[gameType]?.title || 'Игра'; }
function roomLabel(room){ return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната'; }
function boardLabel(gameType, size){
  if (gameType === 'four_in_a_row') return `${size}×${size - 1}${size === 7 ? ' · классика' : ''}`;
  if (gameType === 'domino') return 'Классика 0–6';
  return `${size}×${size}`;
}
function inviteBoardLabel(invite){
  if (String(invite?.game_type || '') === 'four_in_a_row') return `${Number(invite?.board_columns || invite?.board_size || 0)}×${Number(invite?.board_rows || 0)}`;
  return boardLabel(String(invite?.game_type || ''), Number(invite?.board_size || 0));
}
function formatTime(value){
  const date = new Date(String(value || ''));
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' });
}
function formatDate(value){
  const date = new Date(String(value || ''));
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).format(date);
}
function itemTime(item){
  const parsed = Date.parse(String(item?.created_at || ''));
  return Number.isFinite(parsed) ? parsed : 0;
}
function initials(name){
  const parts = String(name || 'И').replace(/^@/, '').replace(/[_-]+/g, ' ').trim().split(/\s+/).filter(Boolean);
  return (parts[0]?.[0] || 'И') + (parts[1]?.[0] || parts[0]?.[1] || '');
}
function avatarHue(value){
  let hash = 0;
  for (const char of String(value || '')) hash = ((hash << 5) - hash + char.charCodeAt(0)) | 0;
  return Math.abs(hash) % 360;
}
function cloneValue(value){
  if (!value || typeof value !== 'object') return value;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
function escapeHtml(value){
  return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
