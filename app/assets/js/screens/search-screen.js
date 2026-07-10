import { state } from '../state.js?v=29';
import { api } from '../api/client.js?v=29';
import { toast } from '../components/toast.js?v=29';
import { showScreen } from '../router.js?v=29';
import { clearTimer, renderBalances } from '../ui.js?v=29';
import { startGamePolling } from './game-screen.js?v=29';
import { APP_CONFIG } from '../config.js?v=29';

export function initSearchScreen(){
  document.getElementById('cancelSearch')?.addEventListener('click', cancelSearch);
  document.getElementById('changeSearch')?.addEventListener('click', cancelSearch);
}

export function startSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  state.polling.search = false;
  state.timers.search = setInterval(checkSearch, APP_CONFIG.searchIntervalMs);
  checkSearch();
}

async function checkSearch(){
  if (state.polling.search) return;
  state.polling.search = true;

  try {
    const result = await api.gameState();

    if (result.user) { state.user = result.user; state.session = result.session || state.session; renderBalances(state.user); }

    if (result.game && result.game.status === 'active') {
      state.activeGame = result.game;
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
    handleSearchError(error);
  } finally {
    state.polling.search = false;
  }
}

function handleSearchError(error){
  if (error?.status === 429 || error?.retryable) {
    const now = Date.now();
    if (!state.rateLimitNoticeAt || now - state.rateLimitNoticeAt > 12000) {
      toast('Сервер занят. Поиск продолжается автоматически.');
      state.rateLimitNoticeAt = now;
    }
    return;
  }

  toast(error.message || 'Не удалось обновить поиск.');
}

async function cancelSearch(){
  try { await api.leaveSearch(); } catch(e) {}
  state.timers.search = clearTimer(state.timers.search);
  state.polling.search = false;
  showScreen('home');
}
