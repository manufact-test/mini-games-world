import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=27';
import { startGamePolling } from './game-screen.js?v=70';
import { gameTypeOf } from '../games/game-router.js?v=70';
import { APP_CONFIG } from '../config.js?v=38';

const searchRuntime = window.__MGW_SEARCH_SCREEN_RUNTIME__ ||= { emptyRoomBotCheckTimer:null, initialized:false };

export function initSearchScreen(){
  if (searchRuntime.initialized) return;
  searchRuntime.initialized = true;
  document.getElementById('cancelSearch')?.addEventListener('click', cancelSearch);
  document.getElementById('changeSearch')?.addEventListener('click', cancelSearch);
}

export function startSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  clearEmptyRoomBotCheck();
  searchRuntime.emptyRoomBotCheckTimer = window.setTimeout(() => {
    searchRuntime.emptyRoomBotCheckTimer = null;
    checkSearch();
    state.timers.search = setInterval(checkSearch, APP_CONFIG.searchIntervalMs);
  }, 3000);
  checkSearch();
}

async function checkSearch(){
  try {
    const result = await api.gameState();
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game && result.game.status === 'active') {
      clearEmptyRoomBotCheck();
      state.activeGame = result.game;
      state.selectedGame = gameTypeOf(result.game);
      state.timers.search = clearTimer(state.timers.search);
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    if (!result.game && result.user && result.user.status !== 'searching') {
      clearEmptyRoomBotCheck();
      state.timers.search = clearTimer(state.timers.search);
      showScreen('home');
      toast('Поиск остановлен. Соперник не найден или связь прервалась.');
    }
  } catch (error) {
    toast(error.message);
  }
}

async function cancelSearch(){
  clearEmptyRoomBotCheck();
  try { await api.leaveSearch(); } catch(e) {}
  state.timers.search = clearTimer(state.timers.search);
  showScreen('home');
}

function clearEmptyRoomBotCheck(){
  if (searchRuntime.emptyRoomBotCheckTimer !== null) {
    window.clearTimeout(searchRuntime.emptyRoomBotCheckTimer);
    searchRuntime.emptyRoomBotCheckTimer = null;
  }
}
