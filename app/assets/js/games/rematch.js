import { state } from '../state.js?v=27';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { toast } from '../components/toast.js?v=41';
import { getInitData, haptic } from '../telegram/telegram-app.js?v=27';
import { getSessionId } from '../session.js?v=27';
import { showScreen } from '../router.js?v=27';
import { renderBalances } from '../ui.js?v=27';

const REMATCH_URL = `${window.location.origin}/bot/rematch.php`;
const INVITES_URL = `${window.location.origin}/bot/invites.php`;

let initialized = false;
let observer = null;
let pendingToken = '';

export function initRematch(){
  if (initialized) return;
  initialized = true;

  const sheet = document.getElementById('sheet');
  if (!sheet) return;

  observer = new MutationObserver(enhanceResultSheet);
  observer.observe(sheet, { childList:true, subtree:true });
  enhanceResultSheet();
}

function enhanceResultSheet(){
  const newOpponent = document.getElementById('newOpponent');
  const goHome = document.getElementById('goHome');
  if (!newOpponent || !goHome || document.getElementById('rematchOpponent')) return;

  const game = state.activeGame;
  if (!game || String(game.status || '') !== 'finished' || Boolean(game.is_bot_game)) return;
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

async function createRematch(game, button){
  if (!game?.id || button.disabled) return;

  haptic('light');
  button.disabled = true;
  button.textContent = 'Отправляем предложение…';

  try {
    const result = await postJson(REMATCH_URL, { gameId:String(game.id) });
    syncState(result);

    const invite = result.invite || {};
    pendingToken = String(invite.token || '');
    if (!pendingToken) throw new Error('Не удалось создать предложение реванша.');

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
    <div class="sheet-head">
      <div>
        <h2>Реванш предложен</h2>
        <p>${escapeHtml(opponentName)} получил уведомление.</p>
      </div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>

    ${inviteSummary(invite)}
    <div class="small-note invite-status-note">
      Ждём ответа соперника. После согласия у вас будет 90 секунд, чтобы начать матч.
    </div>

    <button class="btn ghost full" id="cancelRematchInvite" type="button">Отменить предложение</button>
  `);

  document.getElementById('cancelRematchInvite')?.addEventListener('click', cancelRematch);
}

async function cancelRematch(){
  if (!pendingToken) {
    closeSheet();
    return;
  }

  const button = document.getElementById('cancelRematchInvite');
  if (button) {
    button.disabled = true;
    button.textContent = 'Отменяем…';
  }

  try {
    const result = await postJson(INVITES_URL, { action:'cancel', token:pendingToken });
    syncState(result);
    pendingToken = '';
    closeSheet();
    toast('Предложение реванша отменено.');
  } catch (error) {
    toast(error.message || 'Не удалось отменить предложение.');
    if (button) {
      button.disabled = false;
      button.textContent = 'Отменить предложение';
    }
  }
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
  if (gameType === 'four_in_a_row') {
    return `${size}×${Number(invite.board_rows || Math.max(5, size - 1))}`;
  }
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
