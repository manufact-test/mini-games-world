window.__MGW_BUILD__ = 'v110-mvp14-invite-transition-ux-v1137';

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
import { initShieldKingVisuals } from './components/shield-king-visuals.js?v=125&sk=2';
import { showHomeActivity, showBootFailure, dispatchAppReady } from './components/boot-state.js?v=87';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=89';
import { renderRoomCard, initHomeScreen, setRoom } from './screens/home-screen.js?v=74';
import { initStoreScreen } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen-v110r12.js?v=1137&ux=1';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=74';
import { initSearchScreen } from './screens/search-screen-v102.js?v=103';
import { initGameScreen, enterGame } from './screens/game-screen-v102-safe.js?v=102';
import { initProfileScreen } from './screens/profile-screen-v110.js?v=1108';
import { initGameRules } from './games/game-rules.js?v=75';
import { initGameCardCopy } from './games/game-card-copy.js?v=81&sk=2';
import { initGameInvites } from './games/game-invites-v110.js?v=1137&ux=1';
import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v110r12.js?v=1123';
import { initSearchInviteReconciliation } from './games/search-invite-reconciliation-v110r12.js?v=1124';
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
import { initV110ReadonlyGameSync } from './production-v110-readonly-game-sync.js?v=1107&b=bc9d7b435f1a';
import { initV110Presence } from './production-v110-presence.js?v=1121&b=f5a28b030c69';
import { beginStatsRequest, applyStatsSnapshot } from './stats-owner-v110.js?v=1121';

let statsRefreshing = false;

initTelegramApp();
initV110Presence();
initRuntimeStatus();
initTypography();
initSheet();
initUserCopy();
initShieldKingVisuals();
initGameCardCopy();
initNotificationsScreen();

initGameScreen();
initV110ReadonlyGameSync();
initGameInvites();
initSearchScreen();
initSearchInviteReconciliation();
initDominoChainLayout();

initTicTacToeEntry();
initFourInARowEntry();
initBattleshipEntry();
initCheckersEntry();
initReversiEntry();
initChessEntry();
initGoEntry();
initDominoEntry();

initHomeScreen();
initStoreScreen();
initStoreOrder();
initStoreOrders();
initWeeklyMatchInfo();
initProfileScreen();
initGameRules();

boot();

async function boot(){
  try {
    const result = await api.me();
    state.user = result.user;
    state.session = result.session || null;
    renderUser(state.user);
    renderBalances(state.user);
    setRoom(state.room || 'match');
    await refreshStats();
    syncWeeklyMatchButton();
    dispatchAppReady();
  } catch (error) {
    showBootFailure(error);
  } finally {
    hidePreloader();
  }
}

async function refreshStats(){
  if (statsRefreshing) return;
  statsRefreshing = true;
  const request = beginStatsRequest();
  try {
    const result = await api.stats();
    applyStatsSnapshot(request, result);
    showHomeActivity(result.stats || result);
  } catch (error) {
    // Stats are secondary: keep the app usable when this request fails.
  } finally {
    statsRefreshing = false;
  }
}

window.addEventListener('mgw:session-update', event => {
  state.session = event.detail || state.session;
});

window.addEventListener('mgw:game-enter', event => {
  const game = event.detail?.game || event.detail;
  if (game) enterGame(game);
});

window.addEventListener('mgw:invite-link', event => {
  openIncomingInviteFromTelegram(event.detail || {});
});

window.addEventListener('mgw:balance-refresh', () => {
  if (state.user) renderBalances(state.user);
});

window.addEventListener('mgw:stats-refresh', refreshStats);

window.addEventListener('beforeunload', () => {
  clearTimer();
  currentV99PassiveLock();
});
