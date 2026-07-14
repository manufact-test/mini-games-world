import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getTelegram, getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { renderBalances } from '../ui.js?v=27';
import { refreshNotificationBadge } from '../screens/notifications-screen.js?v=80';

const OPPONENTS_URL = `${window.location.origin}/bot/invite-opponents.php`;
const INVITES_URL = `${window.location.origin}/bot/invites.php`;
const INVITE_SEEN_URL = `${window.location.origin}/bot/invite-seen.php`;
const ACTIVE_INVITE_STORAGE_KEY = 'mgw_active_invite_v2';

let initialized = false;
let sheetObserver = null;
let lastInviteTrigger = null;
let lastGameType = 'tictactoe';
let suppressingIncomingSheet = false;
const seenDirectTokens = new Set();

export function initDirectInvites(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('click', event => {
    const trigger = event.target.closest('[data-invite-friend]');
    if (!trigger) return;
    lastInviteTrigger = trigger;
    lastGameType = String(trigger.dataset.inviteFriend || 'tictactoe');
  }, true);

  const sheet = document.getElementById('sheet');
  if (!sheet) return;

  sheetObserver = new MutationObserver(() => {
    enhanceInviteSetup();
    normalizeIncomingInvitePresentation();
  });
  sheetObserver.observe(sheet, { childList:true, subtree:true });
}

function enhanceInviteSetup(){
  const createLinkButton = document.querySelector('#sheet [data-create-invite]');
  if (!(createLinkButton instanceof HTMLButtonElement)) return;

  if (createLinkButton.dataset.directEnhanced === '1') {
    if (!createLinkButton.disabled && createLinkButton.textContent.trim() === 'Создать и отправить') {
      createLinkButton.textContent = 'Пригласить по ссылке';
    }
    return;
  }

  createLinkButton.dataset.directEnhanced = '1';
  createLinkButton.textContent = 'Пригласить по ссылке';
  createLinkButton.classList.remove('primary', 'gold');
  createLinkButton.classList.add('ghost');

  const directButton = document.createElement('button');
  directButton.className = `btn ${state.room === 'gold' ? 'gold' : 'primary'} full setup-start-btn`;
  directButton.type = 'button';
  directButton.dataset.openDirectInvite = '1';
  directButton.textContent = 'Пригласить игрока';
  directButton.addEventListener('click', openRecentOpponentPicker);

  createLinkButton.insertAdjacentElement('beforebegin', directButton);
}

async function openRecentOpponentPicker(){
  haptic('light');
  const context = currentInviteContext();

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Выберите игрока</h2>
        <p>Приглашение сразу появится у него в колокольчике.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="notifications-loading">
      <div>🎮</div>
      <strong>Загружаем соперников…</strong>
    </div>
  `);

  try {
    const result = await postJson(OPPONENTS_URL, {});
    renderOpponentPicker(Array.isArray(result.items) ? result.items : [], context);
  } catch (error) {
    openSheet(`
      <div class="sheet-head">
        <div><h2>Не удалось загрузить игроков</h2></div>
        <button class="close" data-close-sheet type="button">×</button>
      </div>
      <div class="small-note">${escapeHtml(error.message || 'Попробуйте ещё раз.')}</div>
      <button class="btn ghost full" data-return-invite-setup type="button">Назад</button>
    `);
    document.querySelector('[data-return-invite-setup]')?.addEventListener('click', returnToInviteSetup);
  }
}

function renderOpponentPicker(items, context){
  const list = items.length
    ? `<div class="stack">${items.map(item => `
        <button class="btn ghost full" data-direct-opponent="${escapeHtml(item.id || '')}" type="button">
          ${escapeHtml(item.name || 'Игрок')} · ${escapeHtml(item.activity || 'недавний соперник')}
        </button>
      `).join('')}</div>`
    : `
      <div class="notifications-empty">
        <div>👥</div>
        <strong>Недавних соперников пока нет</strong>
        <span>Для нового человека используйте приглашение по ссылке.</span>
      </div>
    `;

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Выберите игрока</h2>
        <p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${list}
    <button class="btn ghost full" data-return-invite-setup type="button">Назад к условиям</button>
  `);

  document.querySelector('[data-return-invite-setup]')?.addEventListener('click', returnToInviteSetup);
  document.querySelectorAll('[data-direct-opponent]').forEach(button => {
    button.addEventListener('click', () => createDirectInvite(context, String(button.dataset.directOpponent || ''), button));
  });
}

async function createDirectInvite(context, inviteeId, button){
  if (!inviteeId || !(button instanceof HTMLButtonElement) || button.disabled) return;

  const opponentName = button.textContent.split('·')[0].trim() || 'Игрок';
  document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = true; });
  button.textContent = 'Отправляем приглашение…';

  try {
    const result = await postJson(INVITES_URL, {
      action:'create',
      gameType:context.gameType,
      room:context.room,
      bet:context.bet,
      boardSize:context.boardSize,
      inviteeId,
    });

    syncState(result);
    const invite = result.invite || {};
    if (!invite.token) throw new Error('Не удалось создать приглашение.');

    rememberOwnerInvite(invite);
    showDirectInviteSent(invite, opponentName, String(result.delivery || 'in_app'));
  } catch (error) {
    toast(error.message || 'Не удалось отправить приглашение.');
    document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = false; });
    button.textContent = `${opponentName} · повторить`;
  }
}

function showDirectInviteSent(invite, opponentName, delivery){
  const deliveryText = delivery === 'telegram'
    ? `${opponentName} получил сообщение от бота.`
    : `${opponentName} получит приглашение в колокольчике.`;

  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" hidden></span>
    <div class="sheet-head">
      <div>
        <h2>Приглашение отправлено</h2>
        <p>${escapeHtml(deliveryText)}</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ждём ответа игрока. Коины пока не списываются.</div>
    <button class="btn ghost full" data-cancel-direct-invite type="button">Отменить приглашение</button>
  `);

  document.querySelector('[data-cancel-direct-invite]')?.addEventListener('click', event => {
    cancelDirectInvite(String(invite.token || ''), event.currentTarget);
  });
}

async function cancelDirectInvite(token, button){
  if (!token || !(button instanceof HTMLButtonElement) || button.disabled) return;
  button.disabled = true;
  button.textContent = 'Отменяем…';

  try {
    const result = await postJson(INVITES_URL, { action:'cancel', token });
    syncState(result);
    forgetOwnerInvite(token);
    closeSheet();
    toast('Приглашение отменено.');
  } catch (error) {
    toast(error.message || 'Не удалось отменить приглашение.');
    button.disabled = false;
    button.textContent = 'Отменить приглашение';
  }
}

function normalizeIncomingInvitePresentation(){
  const heading = String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
  if (heading !== 'Вас приглашают сыграть') return;

  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  const token = String(marker?.dataset.inviteToken || '');
  if (!token) return;

  const launchedToken = incomingToken();
  if (launchedToken === token) {
    markDirectInviteSeen(token);
    return;
  }

  if (suppressingIncomingSheet) return;
  suppressingIncomingSheet = true;
  closeSheet();

  window.setTimeout(() => {
    suppressingIncomingSheet = false;
    refreshNotificationBadge(true);
  }, 0);
}

async function markDirectInviteSeen(token){
  if (!token || seenDirectTokens.has(token)) return;
  seenDirectTokens.add(token);

  try {
    await postJson(INVITE_SEEN_URL, { token });
    refreshNotificationBadge(false);
  } catch (error) {
    // The invitation itself remains usable even if read-state syncing fails.
  }
}

function currentInviteContext(){
  const sizeButton = document.querySelector('#sheet [data-invite-size].active');
  const betButton = document.querySelector('#sheet [data-invite-bet].active');
  return {
    gameType:lastGameType,
    room:state.room === 'gold' ? 'gold' : 'match',
    boardSize:Number(sizeButton?.dataset.inviteSize || defaultBoardSize(lastGameType)),
    bet:Number(betButton?.dataset.inviteBet || (state.room === 'gold' ? state.selectedBet : 10) || 10),
  };
}

function returnToInviteSetup(){
  closeSheet();
  window.setTimeout(() => lastInviteTrigger?.click(), 0);
}

function rememberOwnerInvite(invite){
  try {
    localStorage.setItem(ACTIVE_INVITE_STORAGE_KEY, JSON.stringify({
      token:String(invite.token || ''),
      status:String(invite.status || 'pending'),
      role:'owner',
    }));
  } catch (error) {
    // Current-session polling is enough when storage is unavailable.
  }
}

function forgetOwnerInvite(token){
  try {
    const stored = JSON.parse(localStorage.getItem(ACTIVE_INVITE_STORAGE_KEY) || 'null');
    if (String(stored?.token || '') === token) localStorage.removeItem(ACTIVE_INVITE_STORAGE_KEY);
  } catch (error) {
    // Nothing else is required.
  }
}

function syncState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function inviteSummary(invite){
  return `
    <div class="topup-success">
      <div><span>Игра</span><strong>${escapeHtml(invite.game_title || 'Игра')}</strong></div>
      <div><span>Комната</span><strong>${escapeHtml(invite.room_label || roomLabel(invite.room))}</strong></div>
      <div><span>Вариант</span><strong>${escapeHtml(boardLabel(invite))}</strong></div>
      <div><span>Ставка</span><strong>${Number(invite.bet || 0)} коинов</strong></div>
    </div>
  `;
}

function incomingToken(){
  const startParam = String(getTelegram()?.initDataUnsafe?.start_param || '');
  const fromTelegram = startParam.startsWith('invite_') ? startParam.slice(7) : '';
  const fromQuery = new URLSearchParams(window.location.search).get('invite') || '';
  const token = String(fromTelegram || fromQuery).toLowerCase();
  return /^[a-f0-9]{24}$/.test(token) ? token : '';
}

function boardLabel(invite){
  const gameType = String(invite.game_type || '');
  const size = Number(invite.board_size || 0);
  if (gameType === 'domino') return 'Классика 0–6';
  if (gameType === 'four_in_a_row') return `${size}×${Number(invite.board_rows || Math.max(5, size - 1))}`;
  return `${size}×${size}`;
}

function gameTitle(gameType){
  return {
    tictactoe:'Крестики-нолики',
    four_in_a_row:'4 в ряд',
    battleship:'Морской бой',
    checkers:'Шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  }[gameType] || 'Игра';
}

function defaultBoardSize(gameType){
  return {
    tictactoe:3,
    four_in_a_row:7,
    battleship:10,
    checkers:8,
    reversi:8,
    chess:8,
    go:9,
    domino:7,
  }[gameType] || 3;
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
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
    throw new Error(data?.error || 'Сервис приглашений временно недоступен.');
  }
  return data;
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
