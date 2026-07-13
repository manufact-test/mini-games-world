window.__MGW_BUILD__ = 'v79-mvp12-invite-notification-actions';
import { initRequestGuard } from './api/request-guard.js?v=64';
import { initTelegramApp } from './telegram/telegram-app.js?v=27';
import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { hidePreloader } from './components/preloader.js?v=42';
import { initSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { initAccountShortcuts } from './components/account-shortcuts.js?v=48';
import { initUserCopy } from './components/user-copy.js?v=62';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=27';
import { renderRoomCard, initHomeScreen, setRoom, renderStats } from './screens/home-screen.js?v=74';
import { initStoreScreen } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen.js?v=62';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=74';
import { initSearchScreen } from './screens/search-screen.js?v=74';
import { initGameScreen, startGamePolling } from './screens/game-screen.js?v=74';
import { initProfileScreen } from './screens/profile-screen.js?v=48';
import { initGameRules } from './games/game-rules.js?v=75';
import { initGameCardCopy } from './games/game-card-copy.js?v=72';
import { initGameInvites, openIncomingInviteIfPresent } from './games/game-invites.js?v=78';
import { initInviteNotificationActions } from './games/invite-notification-actions.js?v=79';
import { initInviteNotificationDedupe } from './games/invite-notification-dedupe.js?v=79';
import { initTicTacToeEntry } from './games/tictactoe/entry.js?v=74';
import { initFourInARowEntry } from './games/four-in-a-row/entry.js?v=74';
import { initBattleshipEntry } from './games/battleship/entry.js?v=74';
import { initCheckersEntry } from './games/checkers/entry.js?v=74';
import { initReversiEntry } from './games/reversi/entry.js?v=74';
import { initChessEntry } from './games/chess/entry.js?v=74';
import { initGoEntry } from './games/go/entry.js?v=74';
import { initDominoEntry } from './games/domino/entry.js?v=74';
import { showScreen } from './router.js?v=27';
import { isSessionLocked, sessionMessage } from './session.js?v=27';

let statsRefreshing = false;

initRequestGuard();
initTelegramApp();
initTypography();
initSheet();
initUserCopy();
initGameCardCopy();

// The notification center must register before the invite guard so a bell click
// marks the notification as read and shows its action button instead of reopening a stale sheet.
initNotificationsScreen();
initInviteNotificationActions();
initInviteNotificationDedupe();
initGameInvites();

initTicTacToeEntry();
initFourInARowEntry();
initBattleshipEntry();
initCheckersEntry();
initReversiEntry();
initChessEntry();
initGoEntry();
initDominoEntry();
initStoreScreen();
initStoreOrder();
initStoreOrders();
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
    } else {
      await openIncomingInviteIfPresent();
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
  state.timers.stats = setInterval(refreshStatsIfVisible, APP_CONFIG.statsIntervalMs);
}

async function refreshStatsIfVisible(){
  if (statsRefreshing || !canRefreshHomeStats()) return;
  statsRefreshing = true;

  try {
    const result = await api.stats();
    state.stats = result.stats;
    state.session = result.session || state.session;
    renderStats(state.stats);
  } catch (error) {
    // Background statistics must never interrupt a match or another user action.
  } finally {
    statsRefreshing = false;
  }
}

function canRefreshHomeStats(){
  if (document.visibilityState !== 'visible') return false;
  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

boot();
