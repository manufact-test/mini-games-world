import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances, roomName } from '../ui.js?v=89';
import { APP_CONFIG } from '../config.js?v=38';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { enterGame, clearGameView } from './game-screen-v99.js?v=99';
import {
  currentV99PassiveLock,
  rememberV99PassiveLock,
  clearV99PassiveLock,
} from '../production-v99-session-transport.js?v=99';

const START_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);
const LOCK_PATTERN = /(активная игра на другом устройстве|ищете матч на другом устройстве|игра уже открыта на другом устройстве)/iu;

const searchRuntime = window.__MGW_V99_SEARCH_RUNTIME__ ||= {
  initialized:false,
  epoch:0,
  active:false,
  pollBusy:false,
  lastLockToastAt:0,
};

export function initSearchScreen(){
  if (searchRuntime.initialized) return;
  searchRuntime.initialized = true;

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    if (button.id === 'cancelSearch' || button.id === 'changeSearch') {
      event.preventDefault();
      event.stopImmediatePropagation();
      cancelSearch();
      return;
    }

    if (button instanceof HTMLButtonElement && START_IDS.has(button.id) && !button.disabled) {
      event.preventDefault();
      event.stopImmediatePropagation();
      beginSearch(searchContext(button.id));
      return;
    }

    if (button.matches('[data-invite-action="start"]') && currentV99PassiveLock()?.locked) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showExplicitLock();
    }
  }, true);

  document.addEventListener('mgw:v99-search-request', event => {
    const context = normalizeContext(event.detail || {});
    beginSearch(context);
  });
}

export async function beginSearch(rawContext){
  const lock = currentV99PassiveLock();
  if (lock?.locked) {
    showExplicitLock();
    return;
  }

  const context = normalizeContext(rawContext);
  const epoch = ++searchRuntime.epoch;
  searchRuntime.active = true;
  searchRuntime.pollBusy = false;
  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = null;
  state.selectedGame = context.gameType;
  state.room = context.room;
  state.selectedBet = context.bet;
  rememberBoardSelection(context.gameType, context.size);
  clearGameView();
  closeSheet();

  const info = document.getElementById('searchInfo');
  if (info) info.textContent = context.label;
  showScreen('search');
  haptic('light');

  try {
    const result = await api.startSearch(context.room, context.bet, context.size, context.gameType);
    if (epoch !== searchRuntime.epoch || !searchRuntime.active) {
      api.leaveSearch().catch(() => null);
      return;
    }

    rememberUserAndSession(result);
    if (result?.session?.locked) {
      rememberV99PassiveLock(result.session);
      cancelLocalSearch();
      showExplicitLock();
      return;
    }
    clearV99PassiveLock();

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      searchRuntime.active = false;
      state.timers.search = clearTimer(state.timers.search);
      enterGame(result.game, result.me || null);
      return;
    }

    state.timers.search = window.setInterval(() => pollSearch(epoch), APP_CONFIG.searchIntervalMs);
    pollSearch(epoch);
  } catch (error) {
    if (epoch !== searchRuntime.epoch) return;
    cancelLocalSearch();
    if (LOCK_PATTERN.test(String(error?.message || ''))) {
      rememberV99PassiveLock({ message:error.message });
      showExplicitLock();
      return;
    }
    toast(error?.message || 'Не удалось начать поиск.');
  }
}

function cancelSearch(){
  ++searchRuntime.epoch;
  cancelLocalSearch();
  haptic('light');
  api.leaveSearch().then(rememberUserAndSession).catch(() => null);
}

function cancelLocalSearch(){
  searchRuntime.active = false;
  searchRuntime.pollBusy = false;
  state.timers.search = clearTimer(state.timers.search);
  state.activeGame = null;
  closeSheet();
  clearGameView();
  showScreen('home');
}

async function pollSearch(epoch){
  if (!searchRuntime.active || epoch !== searchRuntime.epoch || searchRuntime.pollBusy) return;
  searchRuntime.pollBusy = true;
  try {
    const result = await api.gameState();
    if (!searchRuntime.active || epoch !== searchRuntime.epoch) return;
    rememberUserAndSession(result);

    if (result?.session?.locked) {
      rememberV99PassiveLock(result.session);
      cancelLocalSearch();
      return;
    }

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      searchRuntime.active = false;
      state.timers.search = clearTimer(state.timers.search);
      enterGame(result.game, result.me || null);
      return;
    }

    if (result?.user && String(result.user.status || '') !== 'searching') {
      cancelLocalSearch();
    }
  } catch (error) {
    // Search polling retries silently on the next interval.
  } finally {
    searchRuntime.pollBusy = false;
  }
}

function showExplicitLock(){
  const lock = currentV99PassiveLock();
  const now = Date.now();
  if (now - searchRuntime.lastLockToastAt < 1800) return;
  searchRuntime.lastLockToastAt = now;
  toast(String(lock?.message || 'У вас уже идёт активная игра на другом устройстве.'));
}

function searchContext(buttonId){
  const room = state.room === 'gold' ? 'gold' : 'match';
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(state.selectedBet || APP_CONFIG.goldBets[0]);
  const options = {
    startSearchBtn:{ gameType:'tictactoe', size:Number(state.selectedBoardSize || 3), title:'Крестики-нолики' },
    startFourSearchBtn:{ gameType:'four_in_a_row', size:Number(state.selectedFourBoardSize || 7), title:'4 в ряд' },
    startBattleshipSearchBtn:{ gameType:'battleship', size:10, title:'Морской бой' },
    startCheckersSearchBtn:{ gameType:'checkers', size:8, title:'Шашки' },
    startReversiSearchBtn:{ gameType:'reversi', size:Number(state.selectedReversiBoardSize || 8), title:'Реверси' },
    startChessSearchBtn:{ gameType:'chess', size:8, title:'Шахматы' },
    startGoSearchBtn:{ gameType:'go', size:Number(state.selectedGoBoardSize || 9), title:'Го' },
    startDominoSearchBtn:{ gameType:'domino', size:7, title:'Домино' },
  };
  const selected = options[buttonId] || options.startSearchBtn;
  return normalizeContext({ ...selected, room, bet });
}

function normalizeContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const room = String(value?.room || state.room || 'match') === 'gold' ? 'gold' : 'match';
  const size = Number(value?.size || defaultSize(gameType));
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(value?.bet || state.selectedBet || APP_CONFIG.goldBets[0]);
  const title = String(value?.title || titleFor(gameType));
  return {
    gameType,
    room,
    size,
    bet,
    title,
    label:`${title} · ${roomName(room)} · участие ${bet} коинов${gameType === 'domino' ? '' : ` · поле ${size}×${size}`}`,
  };
}

function defaultSize(type){
  return {
    tictactoe:3,
    four_in_a_row:7,
    battleship:10,
    checkers:8,
    reversi:8,
    chess:8,
    go:9,
    domino:7,
  }[type] || 3;
}

function titleFor(type){
  return {
    tictactoe:'Крестики-нолики',
    four_in_a_row:'4 в ряд',
    battleship:'Морской бой',
    checkers:'Шашки',
    reversi:'Реверси',
    chess:'Шахматы',
    go:'Го',
    domino:'Домино',
  }[type] || 'Игра';
}

function rememberBoardSelection(type, size){
  if (type === 'tictactoe') state.selectedBoardSize = size;
  else if (type === 'four_in_a_row') state.selectedFourBoardSize = size;
  else if (type === 'reversi') state.selectedReversiBoardSize = size;
  else if (type === 'go') state.selectedGoBoardSize = size;
}

function rememberUserAndSession(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}
