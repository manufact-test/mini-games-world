window.__MGW_BUILD__ = 'v110-mvp14r12-invite-notification-presence-stability';

import { initTelegramApp } from './telegram/telegram-app.js?v=27';
import { initRuntimeStatus } from './runtime-status.js?v=86';
import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { hidePreloader } from './components/preloader.js?v=42';
import { initSheet } from './components/sheet.js?v=1109';
import { toast } from './components/toast.js?v=1109';
import { initAccountShortcuts } from './components/account-shortcuts.js?v=48';
import { initUserCopy } from './components/user-copy.js?v=62';
import { showHomeActivity, showBootFailure, dispatchAppReady } from './components/boot-state.js?v=87';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=89';
import { renderRoomCard, initHomeScreen, setRoom } from './screens/home-screen.js?v=74';
import { initStoreScreen } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen-v110r12.js?v=1120';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=74';
import { initSearchScreen } from './screens/search-screen-v102.js?v=102';
import { initGameScreen, enterGame } from './screens/game-screen-v102-safe.js?v=102';
import { initProfileScreen } from './screens/profile-screen-v110.js?v=1108';
import { initGameRules } from './games/game-rules.js?v=75';
import { initGameCardCopy } from './games/game-card-copy.js?v=80';
import { initInviteTerminalActions } from './games/invite-terminal-actions-v110r12.js?v=1120';
import { initGameInvites, openIncomingInviteIfPresent } from './games/game-invites-v110.js?v=1114';
import { initDominoChainLayout } from './games/domino/chain-layout.js?v=82';
import { initTicTacToeEntry } from './games/tictactoe/entry.js?v=74';
import { initFourInARowEntry } from './games/four-in-a-row/entry.js?v=74';
import { initBattleshipEntry } from './games/battleship/entry.js?v=74';
import { initCheckersEntry } from './games/checkers/entry.js?v=74';
import { initReversiEntry } from './games/reversi/entry.js?v=74';
import { initChessEntry } from './games/chess/entry.js?v=74';
import { initGoEntry } from './games/go/entry.js?v=74';
import { initDominoEntry } from './games/domino/entry.js?v=74';
import { currentV99PassiveLock } from './production-v99-session-transport.js?v=99';
import { initV110ReadonlyGameSync } from './production-v110-readonly-game-sync.js?v=1107';
import { initV110Presence } from './production-v110-presence.js?v=1121';
import { beginStatsRequest, applyStatsSnapshot } from './stats-owner-v110.js?v=1121';

let statsRefreshing = false;

initTelegramApp();
initV110Presence();
initRuntimeStatus();
initTypography();
initSheet();
initUserCopy();
initGameCardCopy();
initNotificationsScreen();

initGameScreen();
initV110ReadonlyGameSync();
initInviteTerminalActions();
initGameInvites();
initSearchScreen();
initDominoChainLayout();

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
initProfileScreen();
initGameRules();

document.addEventListener('mgw:v99-game-found', event => {
  const game = event.detail?.game || null;
  if (game?.id && !currentV99PassiveLock()?.locked) {
    enterGame(game, event.detail?.me || null);
  }
});

async function boot(){
  try {
    setRoom(APP_CONFIG.defaultRoom);
    const statsTicket = beginStatsRequest('api');
    const result = await api.bootstrap();
    state.user = result.user;
    state.session = result.session || state.session;
    renderUser(state.user);
    renderBalances(state.user);
    applyStatsSnapshot(statsTicket, result.stats);
    showHomeActivity();
    renderRoomCard();
    syncWeeklyMatchButton(result.weekly_match || null);
    dispatchAppReady();

    if (result.active_game?.id && !currentV99PassiveLock()?.locked) {
      enterGame(result.active_game, result.me || null);
    } else {
      await openIncomingInviteIfPresent();
    }

    startStatsPolling();
  } catch (error) {
    showBootFailure();
    toast(error?.message || 'Не удалось загрузить профиль. Закройте Mini Games World и откройте снова из Telegram.');
  } finally {
    hidePreloader();
  }
}

function startStatsPolling(){
  state.timers.stats = clearTimer(state.timers.stats);
  state.timers.stats = window.setInterval(refreshStatsIfVisible, APP_CONFIG.statsIntervalMs);
}

async function refreshStatsIfVisible(){
  if (statsRefreshing || !canRefreshHomeStats()) return;
  statsRefreshing = true;
  const statsTicket = beginStatsRequest('api');
  try {
    const result = await api.stats();
    if (result?.stats) applyStatsSnapshot(statsTicket, result.stats);
    if (result?.session) state.session = result.session;
  } catch (error) {
    // Background stats never interrupt the visible interface.
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
