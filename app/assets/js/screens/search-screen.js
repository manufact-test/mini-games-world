import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=27';
import { startGamePolling } from './game-screen.js?v=54';
import { gameTypeOf } from '../games/game-router.js?v=54';
import { APP_CONFIG } from '../config.js?v=38';

export function initSearchScreen(){
  document.getElementById('cancelSearch')?.addEventListener('click', cancelSearch);
  document.getElementById('changeSearch')?.addEventListener('click', cancelSearch);
}

export function startSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  state.timers.search = setInterval(checkSearch, APP_CONFIG.searchIntervalMs);
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
      state.activeGame = result.game;
      state.selectedGame = gameTypeOf(result.game);
      state.timers.search = clearTimer(state.timers.search);
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }

    if (!result.game && result.user && result.user.status !== 'searching') {
      state.timers.search = clearTimer(state.timers.search);
      showScreen('home');
      toast('Поиск остановлен. Соперник не найден или связь прервалась.');
    }
  } catch (error) {
    toast(error.message);
  }
}

async function cancelSearch(){
  try { await api.leaveSearch(); } catch(e) {}
  state.timers.search = clearTimer(state.timers.search);
  showScreen('home');
}
