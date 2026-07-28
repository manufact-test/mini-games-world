import { state } from './state.js?v=27';
import { api } from './api/client.js?v=47';
import { toast } from './components/toast.js?v=41';
import { closeSheet } from './components/sheet.js?v=68';
import { showScreen } from './router.js?v=27';
import { clearTimer, renderBalances } from './ui.js?v=89';
import { haptic } from './telegram/telegram-app.js?v=27';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';
import { enterGame, clearGameView } from './screens/game-screen-v102-safe.js?v=102';

const PLAY_IDS = new Set([
  'playTicTacToe',
  'playFourInARow',
  'playBattleship',
  'playCheckers',
  'playReversi',
  'playChess',
  'playGo',
  'playDomino',
]);

const runtime = window.__MGW_V103_TARGETED_INTERACTIONS__ ||= {
  initialized:false,
  leavePending:false,
  lastLockToastAt:0,
  roomObserver:null,
};

export function initV103TargetedInteractions(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  document.addEventListener('click', interceptClick, true);
  document.addEventListener('mgw:app-ready', ensureWeeklyDetailsButton);
  document.addEventListener('mgw:v99-passive-lock-changed', updatePlayButtons);

  const roomCard = document.getElementById('roomCard');
  if (roomCard && typeof MutationObserver === 'function') {
    runtime.roomObserver = new MutationObserver(() => queueMicrotask(ensureWeeklyDetailsButton));
    runtime.roomObserver.observe(roomCard, { childList:true, subtree:true });
  }

  window.setTimeout(() => {
    ensureWeeklyDetailsButton();
    updatePlayButtons();
  }, 0);
}

function interceptClick(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('button, [role="button"]');
  if (!(button instanceof Element)) return;

  if (button.matches('[data-room]')) {
    window.setTimeout(ensureWeeklyDetailsButton, 0);
    return;
  }

  if (PLAY_IDS.has(String(button.id || '')) && currentLock()) {
    event.preventDefault();
    event.stopImmediatePropagation();
    showLockMessage();
    return;
  }

  if (button.id === 'confirmLeaveGame') {
    const game = state.activeGame;
    if (!game?.id || String(game.status || '') !== 'active') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    void leaveGameImmediately(game);
    return;
  }

  if (button.matches('#gameBoard[data-game-type="tictactoe"] [data-game-cell]')) {
    if (!ticTacToeActionIsCurrent(button)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }
}

function currentLock(){
  if (runtime.leavePending) {
    return { locked:true, message:'Завершаем текущий матч. Подождите немного.' };
  }
  return currentV99PassiveLock()?.locked ? currentV99PassiveLock() : null;
}

function showLockMessage(){
  const now = Date.now();
  if (now - runtime.lastLockToastAt < 1600) return;
  runtime.lastLockToastAt = now;
  const lock = currentLock();
  toast(String(lock?.message || 'У вас уже идёт активная игра на другом устройстве.'));
}

function updatePlayButtons(){
  const locked = Boolean(currentLock());
  for (const id of PLAY_IDS) {
    const button = document.getElementById(id);
    if (!(button instanceof HTMLButtonElement)) continue;
    button.setAttribute('aria-disabled', locked ? 'true' : 'false');
    button.classList.toggle('mgw-session-locked', locked);
  }
}

function ensureWeeklyDetailsButton(){
  const matchActive = document.querySelector('[data-room="match"].active');
  if (!matchActive) return;

  const topUpButton = document.getElementById('topUpMatch');
  const actions = topUpButton?.closest('.room-actions');
  if (!actions) return;

  actions.classList.remove('single');
  if (!document.getElementById('weeklyMatchInfo')) {
    actions.insertAdjacentHTML('beforeend', '<button class="btn ghost" id="weeklyMatchInfo" type="button" aria-label="Подробнее о еженедельных бесплатных коинах">Подробнее</button>');
  }
}

function ticTacToeActionIsCurrent(button){
  const game = state.activeGame;
  if (!game?.id || String(game.game_type || '') !== 'tictactoe') return true;

  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(String(game.id));
  const authoritative = item?.authoritative || game;
  const viewerId = String(item?.viewer?.id || state.user?.id || '');
  const cell = Number(button.dataset.gameCell);
  const board = String(authoritative?.board || '');

  if (!viewerId || !Number.isInteger(cell)) return false;
  if (item?.running || Number(item?.queue?.length || 0) > 0 || item?.surrenderPending) return false;
  if (String(authoritative?.status || '') !== 'active') return false;
  if (String(authoritative?.turn || '') !== viewerId) return false;
  if (cell < 0 || cell >= board.length || board[cell] !== '-') return false;
  return true;
}

async function leaveGameImmediately(game){
  if (runtime.leavePending) return;
  runtime.leavePending = true;
  updatePlayButtons();
  haptic('medium');

  const snapshot = clone(game);
  state.timers.game = clearTimer(state.timers.game);
  closeSheet();
  state.activeGame = null;
  clearGameView();
  showScreen('home');

  try {
    const result = await api.leaveGame(String(snapshot.id));
    if (result?.user) {
      state.user = result.user;
      renderBalances(state.user);
    }
    if (result?.session) state.session = result.session;
    runtime.leavePending = false;
    updatePlayButtons();
    document.dispatchEvent(new CustomEvent('mgw:game-finished', {
      detail:{ game:result?.game || null, source:'v103-immediate-leave' },
    }));
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  } catch (error) {
    runtime.leavePending = false;
    updatePlayButtons();
    enterGame(snapshot, null);
    toast(error?.message || 'Не удалось завершить матч. Игра восстановлена.');
  }
}

function clone(value){
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
