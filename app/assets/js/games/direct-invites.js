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
const MAX_VISIBLE_OPPONENTS = 10;

let initialized = false;
let sheetObserver = null;
let lastInviteTrigger = null;
let lastGameType = 'tictactoe';
let suppressingIncomingSheet = false;
const seenDeepLinkTokens = new Set();

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
  const linkButton = document.querySelector('#sheet [data-create-invite]');
  if (!(linkButton instanceof HTMLButtonElement)) return;

  if (linkButton.dataset.directEnhanced === '1') {
    if (!linkButton.disabled && linkButton.textContent.trim() === 'Создать и отправить') {
      linkButton.textContent = 'Поделиться ссылкой с новым игроком';
    }
    return;
  }

  linkButton.dataset.directEnhanced = '1';
  linkButton.textContent = 'Поделиться ссылкой с новым игроком';
  linkButton.classList.remove('primary', 'gold');
  linkButton.classList.add('ghost');

  const directButton = document.createElement('button');
  directButton.className = `btn ${state.room === 'gold' ? 'gold' : 'primary'} full setup-start-btn`;
  directButton.type = 'button';
  directButton.dataset.openDirectInvite = '1';
  directButton.textContent = 'Пригласить игрока';
  directButton.addEventListener('click', openRecentOpponentPicker);

  const note = document.createElement('div');
  note.className = 'invite-method-note';
  note.textContent = 'Знакомому сопернику приглашение сразу придёт в приложение. Ссылка нужна для нового игрока.';

  linkButton.insertAdjacentElement('beforebegin', note);
  note.insertAdjacentElement('beforebegin', directButton);
}

async function openRecentOpponentPicker(){
  haptic('light');
  const context = currentInviteContext();

  openSheet(`
    <div class="sheet-head">
      <div>
        <h2>Выберите игрока</h2>
        <p>${escapeHtml(gameTitle(context.gameType))} · ${escapeHtml(roomLabel(context.room))}</p>
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
    const items = Array.isArray(result.items) ? result.items.slice(0, MAX_VISIBLE_OPPONENTS) : [];
    items.sort((left, right) => Number(Boolean(right.online)) - Number(Boolean(left.online)));
    renderOpponentPicker(items, context);
  } catch (error) {
    renderPickerError(error.message || 'Попробуйте ещё раз.');
  }
}

function renderOpponentPicker(items, context){
  const list = items.length
    ? `<div class="invite-player-list">${items.map(item => playerCard(item)).join('')}</div>`
    : `
      <div class="notifications-empty invite-empty-state">
        <div>👥</div>
        <strong>Недавних соперников пока нет</strong>
        <span>Вернитесь назад и отправьте ссылку новому игроку.</span>
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

function playerCard(item){
  const id = String(item?.id || '');
  const name = String(item?.name || 'Игрок');
  const activity = String(item?.activity || 'недавний соперник');
  const online = Boolean(item?.online);
  const busy = Boolean(item?.busy);
  const statusClass = busy ? 'busy' : (online ? 'online' : 'offline');
  const hue = avatarHue(id);

  return `
    <button class="invite-player-card" data-direct-opponent="${escapeHtml(id)}" type="button">
      <span class="invite-player-avatar" style="--invite-avatar-hue:${hue}" aria-hidden="true">${escapeHtml(initials(name))}</span>
      <span class="invite-player-copy">
        <strong>${escapeHtml(name)}</strong>
        <span><i class="invite-player-dot ${statusClass}"></i>${escapeHtml(activity)}</span>
      </span>
      <span class="invite-player-arrow" aria-hidden="true">›</span>
    </button>
  `;
}

async function createDirectInvite(context, inviteeId, button){
  if (!inviteeId || !(button instanceof HTMLButtonElement) || button.disabled) return;

  const opponentName = String(button.querySelector('.invite-player-copy strong')?.textContent || 'Игрок').trim();
  document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = true; });
  button.classList.add('loading');

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

    document.dispatchEvent(new CustomEvent('mgw:invite-adopt', { detail:{ result } }));
    showDirectInviteSent(invite, opponentName, String(result.delivery || 'in_app'));
  } catch (error) {
    toast(error.message || 'Не удалось отправить приглашение.');
    document.querySelectorAll('[data-direct-opponent]').forEach(item => { item.disabled = false; });
    button.classList.remove('loading');
  }
}

function showDirectInviteSent(invite, opponentName, delivery){
  const deliveryText = delivery === 'telegram'
    ? `${opponentName} получил сообщение от бота и увидит приглашение в приложении.`
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
    <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить приглашение</button>
  `);
}

function normalizeIncomingInvitePresentation(){
  const heading = String(document.querySelector('#sheet .sheet-head h2')?.textContent || '').trim();
  if (heading !== 'Вас приглашают сыграть') return;

  const marker = document.querySelector('#sheet [data-invite-sheet][data-invite-token]');
  const token = String(marker?.dataset.inviteToken || '');
  if (!token) return;

  if (incomingToken() === token) {
    markDeepLinkSeen(token);
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

async function markDeepLinkSeen(token){
  if (!token || seenDeepLinkTokens.has(token)) return;
  seenDeepLinkTokens.add(token);

  try {
    await postJson(INVITE_SEEN_URL, { token });
    refreshNotificationBadge(false);
  } catch (error) {
    // The invitation remains usable even if read-state syncing fails.
  }
}

function renderPickerError(message){
  openSheet(`
    <div class="sheet-head">
      <div><h2>Не удалось загрузить игроков</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(message)}</div>
    <button class="btn ghost full" data-return-invite-setup type="button">Назад</button>
  `);
  document.querySelector('[data-return-invite-setup]')?.addEventListener('click', returnToInviteSetup);
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
