window.__MGW_BUILD__ = 'v29-random-first-and-clean-game-header';
import { initTelegramApp } from './telegram/telegram-app.js?v=29';
import { api } from './api/client.js?v=29';
import { state } from './state.js?v=29';
import { APP_CONFIG } from './config.js?v=29';
import { hidePreloader } from './components/preloader.js?v=29';
import { initSheet } from './components/sheet.js?v=29';
import { toast } from './components/toast.js?v=29';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=29';
import { renderRoomCard, initHomeScreen, setRoom, renderStats } from './screens/home-screen.js?v=29';
import { initSearchScreen } from './screens/search-screen.js?v=29';
import { initGameScreen, startGamePolling } from './screens/game-screen.js?v=29';
import { initProfileScreen } from './screens/profile-screen.js?v=29';
import { showScreen } from './router.js?v=29';
import { isSessionLocked, sessionMessage } from './session.js?v=29';

initTelegramApp();
initSheet();
initHomeScreen();
initSearchScreen();
initGameScreen();
initProfileScreen();

async function boot(){
  try {
    setRoom(APP_CONFIG.defaultRoom);
    const result = await api.bootstrap();
    state.user = result.user;
    state.stats = result.stats;
    state.session = result.session || state.session;
    renderUser(state.user);
    renderBalances(state.user);
    renderStats(state.stats);
    renderRoomCard();
    if (isSessionLocked(state.session)) {
      toast(sessionMessage(state.session));
    } else if (result.active_game) {
      state.activeGame = result.active_game;
      showScreen('game');
      startGamePolling(result.active_game.id);
    }
    startStatsPolling();
  } catch (error) {
    toast(error.message);
  } finally {
    hidePreloader();
  }
}
function startStatsPolling(){
  state.timers.stats = clearTimer(state.timers.stats);
  state.timers.stats = setInterval(async () => {
    try {
      const result = await api.stats();
      state.stats = result.stats;
      state.session = result.session || state.session;
      renderStats(state.stats);
    } catch (error) {}
  }, APP_CONFIG.statsIntervalMs);
}
boot();
