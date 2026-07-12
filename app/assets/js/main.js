window.__MGW_BUILD__ = 'v61-notification-ux';
import { initTelegramApp } from './telegram/telegram-app.js?v=27';
import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { hidePreloader } from './components/preloader.js?v=42';
import { initSheet } from './components/sheet.js?v=27';
import { toast } from './components/toast.js?v=41';
import { initAccountShortcuts } from './components/account-shortcuts.js?v=48';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=27';
import { renderRoomCard, initHomeScreen, setRoom, renderStats } from './screens/home-screen.js?v=27';
import { initStoreScreen } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen.js?v=61';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=46';
import { initSearchScreen } from './screens/search-screen.js?v=60';
import { initGameScreen, startGamePolling } from './screens/game-screen.js?v=60';
import { initProfileScreen } from './screens/profile-screen.js?v=48';
import { initGameRules } from './games/game-rules.js?v=58';
import { initGameCardCopy } from './games/game-card-copy.js?v=58';
import { initGameInvites } from './games/game-invites.js?v=55';
import { initTicTacToeEntry } from './games/tictactoe/entry.js?v=60';
import { initFourInARowEntry } from './games/four-in-a-row/entry.js?v=60';
import { initBattleshipEntry } from './games/battleship/entry.js?v=60';
import { initCheckersEntry } from './games/checkers/entry.js?v=60';
import { showScreen } from './router.js?v=27';
import { isSessionLocked, sessionMessage } from './session.js?v=27';

initTelegramApp();
initTypography();
initSheet();
initGameCardCopy();
initGameInvites();
initTicTacToeEntry();
initFourInARowEntry();
initBattleshipEntry();
initCheckersEntry();
initStoreScreen();
initStoreOrder();
initStoreOrders();
initNotificationsScreen();
initWeeklyMatchInfo();
initHomeScreen();
initAccountShortcuts();
initSearchScreen();
initGameScreen();
initProfileScreen();
initGameRules();

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
    syncWeeklyMatchButton(result.weekly_match || null);
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
