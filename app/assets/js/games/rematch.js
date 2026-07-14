import { state } from '../state.js?v=27';
import { openSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { renderBalances } from '../ui.js?v=27';

const REMATCH_URL = `${window.location.origin}/bot/rematch.php`;

let initialized = false;
let observer = null;
let enhanceTimer = null;
let lastFinishedGame = null;

export function initRematch(){
  if (initialized) return;
  initialized = true;

  document.addEventListener('mgw:game-finished', () => {
    rememberFinishedGame(state.activeGame);
    scheduleEnhance();
  });

  const sheet = document.getElementById('sheet');
  if (!sheet) return;

  observer = new MutationObserver(() => {
    rememberFinishedGame(state.activeGame);
    scheduleEnhance();
  });
  observer.observe(sheet, { childList:true, subtree:true });
  scheduleEnhance();
}

function rememberFinishedGame(game){
  if (!game || String(game.status || '') !== 'finished') return;
  if (!Array.isArray(game.players) || game.players.length !== 2) return;
  lastFinishedGame = game;
}

function scheduleEnhance(){
  window.clearTimeout(enhanceTimer);
  enhanceTimer = window.setTimeout(enhanceResultSheet, 40);
}

function enhanceResultSheet(){
  const newOpponent = document.getElementById('newOpponent');
  const goHome = document.getElementById('goHome');
  if (!newOpponent || !goHome || document.getElementById('rematchOpponent')) return;

  const game = finishedGameCandidate();
  if (!game || Boolean(game.is_bot_game)) return;
  if (!Array.isArray(game.players) || game.players.length !== 2) return;

  const button = document.createElement('button');
  button.id = 'rematchOpponent';
  button.className = 'btn primary full';
  button.type = 'button';
  button.textContent = 'Предложить реванш';
  button.addEventListener('click', () => createRematch(game, button));

  newOpponent.classList.remove('primary');
  newOpponent.classList.add('ghost');
  newOpponent.insertAdjacentElement('beforebegin', button);
}

function finishedGameCandidate(){
  const current = state.activeGame;
  if (current && String(current.status || '') === 'finished') {
    rememberFinishedGame(current);
    return current;
  }
  return lastFinishedGame;
}

async function createRematch(game, button){
  if (!game?.id || button.disabled) return;

  haptic('light');
  button.disabled = true;
  button.textContent = 'Отправляем предложение…';

  try {
    const result = await postJson(REMATCH_URL, { gameId:String(game.id) });
    syncState(result);

    const invite = result.invite || {};
    if (!invite.token) throw new Error('Не удалось создать предложение реванша.');

    document.dispatchEvent(new CustomEvent('mgw:invite-adopt', { detail:{ result } }));
    state.activeGame = null;
    showScreen('home');
    showRematchWaiting(invite, String(result.opponent_name || invite.invitee_name || 'Соперник'));
  } catch (error) {
    toast(error.message || 'Не удалось предложить реванш.');
    button.disabled = false;
    button.textContent = 'Предложить реванш';
  }
}

function showRematchWaiting(invite, opponentName){
  openSheet(`
    <span data-invite-sheet data-invite-token="${escapeHtml(invite.token || '')}" hidden></span>
    <div class="sheet-head">
      <div>
        <h2>Реванш предложен</h2>
        <p>${escapeHtml(opponentName)} получит уведомление.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">Ждём ответа соперника. После согласия у вас будет 90 секунд, чтобы начать матч.</div>
    <button class="btn ghost full" data-live-invite-action="cancel" data-invite-token="${escapeHtml(invite.token || '')}" type="button">Отменить предложение</button>
  `);
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

function boardLabel(invite){
  const gameType = String(invite.game_type || '');
  const size = Number(invite.board_size || 0);
  if (gameType === 'domino') return 'Классика 0–6';
  if (gameType === 'four_in_a_row') return `${size}×${Number(invite.board_rows || Math.max(5, size - 1))}`;
  return `${size}×${size}`;
}

function roomLabel(room){
  return String(room || '') === 'gold' ? 'Gold-комната' : 'Матч-комната';
}

function syncState(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
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
    throw new Error(data?.error || 'Сервис реванша временно недоступен.');
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
