window.__MGW_BUILD__ = 'v110-mvp16-shell-nav-topbar-v1149';

import { initTelegramApp } from './telegram/telegram-app.js?v=27';
import { initRuntimeStatus } from './runtime-status.js?v=86';
import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { APP_CONFIG } from './config.js?v=38';
import { currentScreen, onScreenEnter, registerScreenCleanup, showScreen } from './router.js?v=27';
import { hidePreloader } from './components/preloader.js?v=42';
import { initSheet } from './components/sheet.js?v=1109';
import { toast } from './components/toast.js?v=1109';
import { initAccountShortcuts } from './components/account-shortcuts.js?v=48';
import { initUserCopy } from './components/user-copy.js?v=62';
import { initShieldKingVisuals } from './components/shield-king-visuals.js?v=127&sk=4&icons=c1efd5af&shell=nav';
import { showHomeActivity, showBootFailure, dispatchAppReady } from './components/boot-state.js?v=87';
import { initTypography } from './utils/typography.js?v=39';
import { renderUser, renderBalances, clearTimer } from './ui.js?v=89';
import { initHomeScreen, setRoom } from './screens/home-screen.js?v=74';
import { initStoreScreen, openStoreSheet } from './screens/store-screen.js?v=34';
import { initStoreOrder } from './screens/store-order.js?v=38';
import { initStoreOrders } from './screens/store-orders.js?v=36';
import { initNotificationsScreen } from './screens/notifications-screen-v110r12.js?v=1139&semantic=3&scroll=stable';
import { initWeeklyMatchInfo, syncWeeklyMatchButton } from './screens/weekly-match-info.js?v=79&complete=green';
import { initSearchScreen } from './screens/search-screen-v102.js?v=103';
import { initGameScreen, enterGame } from './screens/game-screen-v102-safe.js?v=102';
import { initProfileScreen } from './screens/profile-screen-v110.js?v=1108';
import { applyCanonicalMgwProfile } from './profile/mgw-profile-model.js?v=1';
import { initGameRules } from './games/game-rules.js?v=75';
import { initGameCardCopy } from './games/game-card-copy.js?v=83&sk=5&icons=c1efd5af&delivery=static';
import { initGameInvites } from './games/game-invites-v110.js?v=1137&ux=1';
import { openIncomingInviteFromTelegram } from './games/invite-link-entry-v110r12.js?v=1123';
import { initSearchInviteReconciliation } from './games/search-invite-reconciliation-v110r12.js?v=1124';
import { initDominoChainLayout } from './games/domino/chain-layout.js?v=82';
import { initTicTacToeEntry } from './games/tictactoe/entry.js?v=75';
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
import { t } from '@mgw/i18n';

const SHELL_ROUTES = new Set(['home', 'tournaments', 'store', 'profile']);
let statsRefreshing = false;
let statsRouteLifecycleInitialized = false;
let shellChromeInitialized = false;
let balanceObserver = null;

initTelegramApp();
initV110Presence();
initRuntimeStatus();
initTypography();
initSheet();
initUserCopy();
initAppShellChrome();
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
initStoreScreen();
initStoreOrder();
initStoreOrders();
initWeeklyMatchInfo();
initHomeScreen();
initAccountShortcuts();
initProfileScreen();
initGameRules();
initStatsRouteLifecycle();

document.addEventListener('mgw:v99-game-found', event => {
  const game = event.detail?.game || null;
  if (game?.id && !currentV99PassiveLock()?.locked) {
    enterGame(game, event.detail?.me || null);
  }
});

async function boot(){
  try {
    const statsTicket = beginStatsRequest('api');
    const result = await api.bootstrap();
    const matchEntryCost = Number(result.match_economy?.entry_cost);
    if (!Number.isFinite(matchEntryCost) || matchEntryCost <= 0) {
      throw new Error('Серверная стоимость участия недоступна.');
    }
    APP_CONFIG.matchBet = matchEntryCost;
    state.selectedBet = matchEntryCost;
    setRoom(APP_CONFIG.defaultRoom);
    const mgwProfileResult = await api.mgwProfile();
    state.mgwProfile = mgwProfileResult.profile || null;
    state.user = applyCanonicalMgwProfile(result.user || {}, state.mgwProfile);
    state.session = result.session || state.session;
    renderUser(state.user);
    renderBalances(state.user);
    applyStatsSnapshot(statsTicket, result.stats);
    showHomeActivity();
    syncWeeklyMatchButton(result.weekly_match || null);
    dispatchAppReady();

    if (result.active_game?.id && !currentV99PassiveLock()?.locked) {
      enterGame(result.active_game, result.me || null);
    } else {
      await openIncomingInviteFromTelegram();
    }

    startStatsPolling();
    syncAppShellChrome();
  } catch (error) {
    showBootFailure();
    toast(error?.message || 'Не удалось загрузить профиль. Закройте Mini Games World и откройте снова из Telegram.');
  } finally {
    hidePreloader();
  }
}

function initAppShellChrome(){
  if (shellChromeInitialized) return;
  shellChromeInitialized = true;

  const app = document.getElementById('app');
  const home = document.getElementById('screen-home');
  const existingTopbar = home?.querySelector(':scope .topbar');
  if (!app || !home || !existingTopbar) return;

  existingTopbar.id = 'appShellTopbar';
  existingTopbar.classList.add('app-shell-topbar');
  existingTopbar.querySelector('.user-status')?.remove();

  const profileTrigger = existingTopbar.querySelector('#profileOpen');
  if (profileTrigger) {
    profileTrigger.setAttribute('aria-label', t('nav.profile'));
    profileTrigger.setAttribute('role', 'button');
    profileTrigger.setAttribute('tabindex', '0');
  }

  const iconRow = existingTopbar.querySelector('.icon-row');
  const bell = existingTopbar.querySelector('#notificationsOpen');
  if (bell) bell.setAttribute('aria-label', t('topbar.notifications'));

  const balance = document.createElement('div');
  balance.className = 'app-shell-balance';
  balance.setAttribute('role', 'status');
  balance.setAttribute('aria-label', t('topbar.balance'));
  balance.innerHTML = '<span class="app-shell-balance-icon" id="topbarBalanceIcon" aria-hidden="true"></span><strong id="topbarBalanceUnified">—</strong>';
  iconRow?.prepend(balance);

  app.insertBefore(existingTopbar, home);
  ensureShellScreens(app);
  ensureBottomNavigation(app);
  startBalanceMirror();

  document.addEventListener('click', handleShellNavigation, true);
  document.addEventListener('mgw:screen-changed', () => syncAppShellChrome());
  document.addEventListener('mgw:app-ready', () => syncAppShellChrome());
  document.addEventListener('mgw:game-finished', () => syncAppShellChrome());
  document.addEventListener('mgw:game-dismissed', () => syncAppShellChrome());
  syncAppShellChrome();
}

function ensureShellScreens(app){
  if (!document.getElementById('screen-tournaments')) {
    const tournaments = document.createElement('section');
    tournaments.className = 'screen app-shell-placeholder-screen';
    tournaments.id = 'screen-tournaments';
    tournaments.dataset.screen = 'tournaments';
    tournaments.innerHTML = `
      <div class="content">
        <section class="app-shell-placeholder" aria-labelledby="tournamentsTitle">
          <h1 class="page-title" id="tournamentsTitle">${escapeHtml(t('shell.tournaments_title'))}</h1>
          <p class="page-sub">${escapeHtml(t('shell.tournaments_note'))}</p>
        </section>
      </div>
    `;
    app.append(tournaments);
  }

  if (!document.getElementById('screen-store')) {
    const store = document.createElement('section');
    store.className = 'screen app-shell-placeholder-screen';
    store.id = 'screen-store';
    store.dataset.screen = 'store';
    store.innerHTML = `
      <div class="content">
        <section class="app-shell-placeholder" aria-labelledby="storeTitle">
          <h1 class="page-title" id="storeTitle">${escapeHtml(t('shell.store_title'))}</h1>
          <p class="page-sub">${escapeHtml(t('shell.store_note'))}</p>
          <button class="btn primary full" id="storeOpen" type="button">${escapeHtml(t('shell.store_open'))}</button>
        </section>
      </div>
    `;
    app.append(store);
  }
}

function ensureBottomNavigation(app){
  if (document.getElementById('appBottomNav')) return;

  const nav = document.createElement('nav');
  nav.className = 'app-bottom-nav';
  nav.id = 'appBottomNav';
  nav.setAttribute('aria-label', t('shell.navigation_label'));
  nav.innerHTML = [
    ['home', 'nav.home'],
    ['tournaments', 'nav.tournaments'],
    ['store', 'nav.store'],
    ['profile', 'nav.profile'],
  ].map(([route, key]) => `
    <button class="app-bottom-nav-item" data-shell-nav="${route}" type="button" aria-label="${escapeHtml(t(key))}">
      <span class="app-bottom-nav-icon" aria-hidden="true"></span>
      <span class="app-bottom-nav-label">${escapeHtml(t(key))}</span>
    </button>
  `).join('');
  app.append(nav);
}

function handleShellNavigation(event){
  const target = event.target instanceof Element ? event.target.closest('[data-shell-nav]') : null;
  if (!(target instanceof HTMLButtonElement)) return;

  event.preventDefault();
  event.stopImmediatePropagation();
  if (target.disabled) return;

  if (activeMatchLocksShell()) {
    showScreen('game');
    syncAppShellChrome('game');
    return;
  }

  const route = String(target.dataset.shellNav || 'home');
  if (!SHELL_ROUTES.has(route)) return;

  if (route === 'profile') {
    document.dispatchEvent(new CustomEvent('mgw:open-profile'));
    return;
  }

  showScreen(route);
  if (route === 'store') queueMicrotask(() => void openStoreSheet());
}

function syncAppShellChrome(forcedScreen = null){
  const app = document.getElementById('app');
  const topbar = document.getElementById('appShellTopbar');
  const nav = document.getElementById('appBottomNav');
  if (!app || !topbar || !nav) return;

  const screen = String(forcedScreen || currentScreen());
  const shellVisible = SHELL_ROUTES.has(screen);
  const locked = activeMatchLocksShell() || screen === 'game';

  app.dataset.shellScreen = screen;
  app.classList.toggle('has-shell-chrome', shellVisible);
  topbar.hidden = !shellVisible;
  nav.hidden = !shellVisible;
  nav.setAttribute('aria-hidden', shellVisible ? 'false' : 'true');

  nav.querySelectorAll('[data-shell-nav]').forEach(button => {
    const route = String(button.dataset.shellNav || '');
    const active = route === screen;
    button.classList.toggle('active', active);
    button.toggleAttribute('disabled', locked);
    if (active) button.setAttribute('aria-current', 'page');
    else button.removeAttribute('aria-current');
  });
}

function activeMatchLocksShell(){
  const id = String(state.activeGame?.id || '').trim();
  if (!id) return false;
  const status = String(state.activeGame?.status || '').toLowerCase();
  return !['finished', 'cancelled', 'canceled', 'abandoned'].includes(status);
}

function startBalanceMirror(){
  const source = document.getElementById('balanceUnified');
  if (!source) return;

  const sync = () => {
    const target = document.getElementById('topbarBalanceUnified');
    if (target) target.textContent = String(source.textContent || '—').trim() || '—';
  };
  sync();

  balanceObserver?.disconnect();
  balanceObserver = new MutationObserver(sync);
  balanceObserver.observe(source, { childList:true, subtree:true, characterData:true });
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function initStatsRouteLifecycle(){
  if (statsRouteLifecycleInitialized) return;
  statsRouteLifecycleInitialized = true;
  registerScreenCleanup('home', stopStatsPolling);
  onScreenEnter('home', startStatsPolling);
}

function startStatsPolling(){
  stopStatsPolling();
  if (currentScreen() !== 'home') return;
  state.timers.stats = window.setInterval(refreshStatsIfVisible, APP_CONFIG.statsIntervalMs);
}

function stopStatsPolling(){
  state.timers.stats = clearTimer(state.timers.stats);
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
