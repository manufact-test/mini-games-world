window.__MGW_BUILD__ = 'v96-mvp14-root-cause-stabilization';
window.__MGW_HOTFIX_BUILD__ = 'v115-mvp14-d1-feedback-integration';
import { initFirstInteractionReadinessEarly, warmFirstInteractionData } from './first-interaction-readiness-v103.js?v=103';
import { initRequestGuard } from './api/request-guard.js?v=88';
import { initResidualUiGameRaceFixEarly, initResidualUiGameRaceFixAfter } from './residual-ui-game-race-fix.js?v=91';
import { initInteractionLatencyCoordinator } from './interaction-latency-coordinator-v101.js?v=101';
import { initTelegramApp } from './telegram/telegram-app.js?v=27';
import { initV115Presence } from './presence-v115.js?v=115';
import { initRuntimeStatus } from './runtime-status.js?v=86';
import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { hidePreloader } from './components/preloader.js?v=42';
import { initSheet } from './components/sheet.js?v=68';
import { toast } from './components/toast.js?v=41';
import { initAccountShortcuts } from './components/account-shortcuts.js?v=48';
import { initUserCopy } from './components/user-copy.js?v=62';
import { showHomeActivity, showBootFailure, dispatchAppReady } from './components/boot-state.js?v=87';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=89';
import { renderRoomCard, initHomeScreen, setRoom, renderStats } from './screens/home-screen.js?v=74';
import { initStoreScreen } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen-v99.js?v=99';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=74';
import { initSearchScreen } from './screens/search-screen.js?v=74';
import { initGameScreen, startGamePolling } from './screens/game-screen.js?v=74';
import { initProfileScreen } from './screens/profile-screen.js?v=92';
import { initGameRules } from './games/game-rules.js?v=75';
import { initGameCardCopy } from './games/game-card-copy.js?v=80';
import { initInviteTerminalActions } from './games/invite-terminal-actions-v115.js?v=115';
import { initGameInvites } from './games/game-invites.js?v=85';
import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v115.js?v=115';
import { initGameFinishStability } from './games/game-finish-stability.js?v=80';
import { initDominoChainLayout } from './games/domino/chain-layout.js?v=82';
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

/* The canonical notifications screen remains the only bell/network renderer. */
initNotificationsScreen();
initFirstInteractionReadinessEarly();
initRequestGuard();
initResidualUiGameRaceFixEarly();
initInteractionLatencyCoordinator();
initResidualUiGameRaceFixAfter();
initTelegramApp();
/* Presence starts before bootstrap for both ordinary and invitation launches. */
initV115Presence();
initRuntimeStatus();
initTypography();
initSheet();
initUserCopy();
initGameCardCopy();
/* Decline/cancel owns the earliest capture boundary and succeeds silently. */
initInviteTerminalActions();
/* The canonical coordinator owns direct invitations, accept/start and Share. */
initGameInvites();
initGameFinishStability();
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
initSearchScreen();
initGameScreen();
initProfileScreen();
initGameRules();

async function boot(){
  try {
    setRoom(APP_CONFIG.defaultRoom);
    const result = await api.bootstrap();
    state.user = result.user;
    state.stats = mergePresenceOnline(result.stats);
    state.session = result.session || state.session;
    renderUser(state.user);
    renderBalances(state.user);
    renderStats(state.stats);
    showHomeActivity();
    renderRoomCard();
    syncWeeklyMatchButton(result.weekly_match || null);

    const firstInteraction = await warmFirstInteractionData().catch(() => ({
      profileReady:false,
      historyReady:false,
      notificationsReady:false,
      ordersReady:false,
      opponentsReady:false,
    }));
    window.__MGW_FIRST_INTERACTION_READY__ = firstInteraction;
    dispatchAppReady();

    if (isSessionLocked(state.session)) {
      toast(sessionMessage(state.session));
    } else if (result.active_game) {
      state.activeGame = result.active_game;
      showScreen('game');
      startGamePolling(result.active_game.id);
    } else {
      await openIncomingInviteFromTelegram();
    }

    startStatsPolling();
  } catch (error) {
    showBootFailure();
    toast(error.message || 'Не удалось загрузить профиль. Закройте Mini Games World и откройте снова из Telegram.');
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
    state.stats = mergePresenceOnline(result.stats);
    state.session = result.session || state.session;
    renderStats(state.stats);
  } catch (error) {
    // Background statistics must never interrupt a match or another user action.
  } finally {
    statsRefreshing = false;
  }
}

function mergePresenceOnline(stats){
  const next = stats && typeof stats === 'object' ? { ...stats } : {};
  const ownedOnline = Number(window.__MGW_V115_PRESENCE_ONLINE__);
  if (Number.isFinite(ownedOnline)) next.online_players = ownedOnline;
  return next;
}

function canRefreshHomeStats(){
  if (document.visibilityState !== 'visible') return false;
  const activeScreen = document.querySelector('.screen.active');
  if (String(activeScreen?.dataset.screen || '') !== 'home') return false;
  return !document.getElementById('sheetOverlay')?.classList.contains('active');
}

boot();
