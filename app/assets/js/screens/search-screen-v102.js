import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { closeSheet } from '../components/sheet.js?v=68';
import { registerScreenCleanup, showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=89';
import { APP_CONFIG } from '../config.js?v=38';
import { haptic } from '../telegram/telegram-app.js?v=27';
import { t, formatNumber } from '@mgw/i18n';
import { enterGame, clearGameView } from './game-screen-v102-safe.js?v=102';
import {
  currentV99PassiveLock,
  rememberV99PassiveLock,
  clearV99PassiveLock,
} from '../production-v99-session-transport.js?v=99';

const START_IDS = new Set([
  'startSearchBtn',
  'startFourSearchBtn',
  'startBattleshipSearchBtn',
  'startCheckersSearchBtn',
  'startReversiSearchBtn',
  'startChessSearchBtn',
  'startGoSearchBtn',
  'startDominoSearchBtn',
]);
function lockPattern(){
  return new RegExp([
    'search.lock_markers.active_game_other_device',
    'search.lock_markers.searching_other_device',
    'search.lock_markers.game_open_other_device',
  ].map(key => escapeRegExp(t(key))).join('|'), 'iu');
}

const searchRuntime = window.__MGW_V100_SEARCH_RUNTIME__ ||= {
  initialized:false,
  epoch:0,
  active:false,
  starting:false,
  pollBusy:false,
  startPromise:null,
  stopPromise:null,
  lastLockToastAt:0,
};
searchRuntime.starting = Boolean(searchRuntime.starting);
searchRuntime.startPromise ||= null;
searchRuntime.stopPromise ||= null;

export function initSearchScreen(){
  if (searchRuntime.initialized) return;
  searchRuntime.initialized = true;

  registerScreenCleanup('search', handleSearchScreenLeave);

  document.addEventListener('mgw:locale-changed', () => {
    if (!document.getElementById('screen-search')?.classList.contains('active')) return;
    const gameType = String(state.selectedGame || 'tictactoe');
    const info = document.getElementById('searchInfo');
    if (info) {
      const context = normalizeContext({ gameType, size:selectedSizeFor(gameType) });
      info.textContent = context.label;
      info.dataset.mgwSearchContext = '1';
    }
  });

  document.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element)) return;
    const button = origin.closest('button, [role="button"]');
    if (!(button instanceof Element)) return;

    if (button.id === 'cancelSearch' || button.id === 'changeSearch') {
      event.preventDefault();
      event.stopImmediatePropagation();
      cancelSearch();
      return;
    }

    if (button instanceof HTMLButtonElement && START_IDS.has(button.id) && !button.disabled) {
      event.preventDefault();
      event.stopImmediatePropagation();
      button.disabled = true;
      void beginSearch(searchContext(button.id));
      return;
    }

    if (button.matches('[data-invite-action="start"]') && currentV99PassiveLock()?.locked) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showExplicitLock();
    }
  }, true);

  document.addEventListener('mgw:v100-search-request', event => {
    const context = normalizeContext(event.detail || {});
    void beginSearch(context);
  });
}

function handleSearchScreenLeave({ to } = {}){
  state.timers.search = clearTimer(state.timers.search);
  if (String(to || '') === 'game') return;
  if (!searchRuntime.active && !searchRuntime.starting) return;

  const pendingStart = searchRuntime.startPromise;
  ++searchRuntime.epoch;
  searchRuntime.active = false;
  searchRuntime.starting = false;
  searchRuntime.pollBusy = false;
  enableVisibleStartControls();
  void stopSearchAuthoritatively(pendingStart);
}

export async function beginSearch(rawContext){
  if (state.activeGame?.id && String(state.activeGame.status || '') === 'active') {
    enableVisibleStartControls();
    return { game:state.activeGame };
  }

  /* A startPromise that belongs to a cancelled epoch is allowed to drain only
   * through the already-created stopPromise. It must not block the next user
   * intent, otherwise the setup button stays disabled until that old request
   * completes. A live start without a stop owner is still protected normally. */
  if (searchRuntime.active || searchRuntime.starting || (searchRuntime.startPromise && !searchRuntime.stopPromise)) {
    enableVisibleStartControls();
    return null;
  }

  const lock = currentV99PassiveLock();
  if (lock?.locked) {
    enableVisibleStartControls();
    showExplicitLock();
    return null;
  }

  disableVisibleStartControls();
  const context = normalizeContext(rawContext);
  const epoch = ++searchRuntime.epoch;
  searchRuntime.active = true;
  searchRuntime.starting = true;
  searchRuntime.pollBusy = false;
  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = null;
  state.selectedGame = context.gameType;
  rememberBoardSelection(context.gameType, context.size);
  clearGameView();
  closeSheet();

  const info = document.getElementById('searchInfo');
  if (info) {
    info.textContent = context.label;
    info.dataset.mgwSearchContext = '1';
  }
  showScreen('search');
  haptic('light');

  /* Leaving an active match and starting the next search are two authoritative
   * mutations that must stay ordered. The v110 match lifecycle exposes its one
   * release barrier, while this search owner establishes the new visible intent
   * immediately. This removes the frozen setup button without ever racing a new
   * start_search against the old leave_game transaction. */
  const matchRelease = currentMatchReleaseBarrier();
  if (matchRelease) {
    let releaseResult = null;
    try { releaseResult = await matchRelease; } catch (error) {}

    if (epoch !== searchRuntime.epoch || !searchRuntime.active) {
      if (epoch === searchRuntime.epoch) searchRuntime.starting = false;
      enableVisibleStartControls();
      return null;
    }

    if (!releaseResult?.released) {
      searchRuntime.active = false;
      searchRuntime.starting = false;
      enableVisibleStartControls();
      return null;
    }

    if (state.activeGame?.id && String(state.activeGame.status || '') === 'active') {
      searchRuntime.active = false;
      searchRuntime.starting = false;
      enableVisibleStartControls();
      return { game:state.activeGame };
    }
  }

  /* A repeated search is a new user intent immediately. The old authoritative
   * cancellation may still be finishing, but it must never keep the setup
   * button visibly stuck or commit this new intent after the user cancels it.
   * We therefore enter the existing optimistic search screen first, await the
   * single old stop owner, then re-check this epoch before starting anything. */
  const pendingStop = searchRuntime.stopPromise;
  if (pendingStop) {
    try { await pendingStop; } catch (error) {}

    if (epoch !== searchRuntime.epoch || !searchRuntime.active) {
      if (epoch === searchRuntime.epoch) searchRuntime.starting = false;
      enableVisibleStartControls();
      return null;
    }

    if (state.activeGame?.id && String(state.activeGame.status || '') === 'active') {
      searchRuntime.active = false;
      searchRuntime.starting = false;
      enableVisibleStartControls();
      return { game:state.activeGame };
    }
  }

  if (epoch !== searchRuntime.epoch || !searchRuntime.active) {
    if (epoch === searchRuntime.epoch) searchRuntime.starting = false;
    enableVisibleStartControls();
    return null;
  }

  const startPromise = api.startSearch(context.size, context.gameType);
  searchRuntime.startPromise = startPromise;

  try {
    const result = await startPromise;
    if (epoch !== searchRuntime.epoch || !searchRuntime.active) return null;

    rememberUserAndSession(result);
    if (result?.session?.locked) {
      rememberV99PassiveLock(result.session);
      cancelLocalSearch();
      showExplicitLock();
      return null;
    }
    clearV99PassiveLock();

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      searchRuntime.active = false;
      state.timers.search = clearTimer(state.timers.search);
      enterGame(result.game, result.me || null);
      return result;
    }

    state.timers.search = window.setInterval(() => pollSearch(epoch), APP_CONFIG.searchIntervalMs);
    void pollSearch(epoch);
    return result;
  } catch (error) {
    if (epoch !== searchRuntime.epoch) return null;
    cancelLocalSearch();
    void stopSearchAuthoritatively(null);
    if (lockPattern().test(String(error?.message || ''))) {
      rememberV99PassiveLock({ message:error.message });
      showExplicitLock();
      return null;
    }
    toast(error?.message || t('search.start_failed'));
    return null;
  } finally {
    if (searchRuntime.startPromise === startPromise) searchRuntime.startPromise = null;
    if (epoch === searchRuntime.epoch) searchRuntime.starting = false;
    enableVisibleStartControls();
  }
}

function cancelSearch(){
  const pendingStart = searchRuntime.startPromise;
  ++searchRuntime.epoch;
  cancelLocalSearch();
  haptic('light');
  void stopSearchAuthoritatively(pendingStart);
}

function stopSearchAuthoritatively(pendingStart){
  if (searchRuntime.stopPromise) return searchRuntime.stopPromise;

  let stopPromise;
  stopPromise = (async () => {
    if (pendingStart) {
      try { await pendingStart; } catch (error) {}
    }

    try {
      const result = await api.leaveSearch();
      rememberUserAndSession(result);

      if (String(result?.user?.status || '') === 'playing') {
        try {
          const authoritativeState = await api.gameState();
          rememberUserAndSession(authoritativeState);
          if (authoritativeState?.game?.id && String(authoritativeState.game.status || '') === 'active') {
            searchRuntime.active = false;
            state.timers.search = clearTimer(state.timers.search);
            enterGame(authoritativeState.game, authoritativeState.me || null);
            return authoritativeState;
          }
        } catch (error) {}
      }

      return result;
    } catch (error) {
      return null;
    } finally {
      document.dispatchEvent(new CustomEvent('mgw:search-stopped', {
        detail:{ authoritative:true },
      }));
      if (searchRuntime.stopPromise === stopPromise) searchRuntime.stopPromise = null;
      enableVisibleStartControls();
    }
  })();

  searchRuntime.stopPromise = stopPromise;
  return stopPromise;
}

function cancelLocalSearch(){
  searchRuntime.active = false;
  searchRuntime.starting = false;
  searchRuntime.pollBusy = false;
  state.timers.search = clearTimer(state.timers.search);
  state.activeGame = null;
  closeSheet();
  clearGameView();
  showScreen('home');
  enableVisibleStartControls();
}

async function pollSearch(epoch){
  if (!searchRuntime.active || epoch !== searchRuntime.epoch || searchRuntime.pollBusy) return;
  searchRuntime.pollBusy = true;
  try {
    const result = await api.gameState();
    if (!searchRuntime.active || epoch !== searchRuntime.epoch) return;
    rememberUserAndSession(result);

    if (result?.session?.locked) {
      rememberV99PassiveLock(result.session);
      cancelLocalSearch();
      return;
    }

    if (result?.game?.id && String(result.game.status || '') === 'active') {
      searchRuntime.active = false;
      state.timers.search = clearTimer(state.timers.search);
      enterGame(result.game, result.me || null);
      return;
    }

    if (result?.user && String(result.user.status || '') !== 'searching') {
      cancelLocalSearch();
    }
  } catch (error) {
    // Search polling retries silently on the next interval.
  } finally {
    searchRuntime.pollBusy = false;
  }
}

function disableVisibleStartControls(){
  for (const id of START_IDS) {
    const button = document.getElementById(id);
    if (button instanceof HTMLButtonElement) button.disabled = true;
  }
}

function enableVisibleStartControls(){
  for (const id of START_IDS) {
    const button = document.getElementById(id);
    if (button instanceof HTMLButtonElement) button.disabled = false;
  }
}

function showExplicitLock(){
  const lock = currentV99PassiveLock();
  const now = Date.now();
  if (now - searchRuntime.lastLockToastAt < 1800) return;
  searchRuntime.lastLockToastAt = now;
  toast(String(lock?.message || t('search.lock_default')));
}

function currentMatchReleaseBarrier(){
  const barrier = window.__MGW_V110_MATCH_LIFECYCLE__?.releaseBarrier || null;
  return barrier && typeof barrier.then === 'function' ? barrier : null;
}

function searchContext(buttonId){
  const options = {
    startSearchBtn:{ gameType:'tictactoe', size:Number(state.selectedBoardSize || 3) },
    startFourSearchBtn:{ gameType:'four_in_a_row', size:Number(state.selectedFourBoardSize || 7) },
    startBattleshipSearchBtn:{ gameType:'battleship', size:10 },
    startCheckersSearchBtn:{ gameType:'checkers', size:8 },
    startReversiSearchBtn:{ gameType:'reversi', size:Number(state.selectedReversiBoardSize || 8) },
    startChessSearchBtn:{ gameType:'chess', size:8 },
    startGoSearchBtn:{ gameType:'go', size:Number(state.selectedGoBoardSize || 9) },
    startDominoSearchBtn:{ gameType:'domino', size:7 },
  };
  return normalizeContext(options[buttonId] || options.startSearchBtn);
}

function normalizeContext(value){
  const gameType = String(value?.gameType || 'tictactoe');
  const size = Number(value?.size || defaultSize(gameType));
  const bet = Number(APP_CONFIG.matchBet);
  const title = String(value?.title || titleFor(gameType));
  return {
    gameType,
    size,
    title,
    label:t('search.match_label', { game:title, bet:formatNumber(bet) }) + (gameType === 'domino' ? '' : t('search.board_suffix', { size })),
  };
}

function selectedSizeFor(type){
  return {
    tictactoe:Number(state.selectedBoardSize || 3),
    four_in_a_row:Number(state.selectedFourBoardSize || 7),
    battleship:10,
    checkers:8,
    reversi:Number(state.selectedReversiBoardSize || 8),
    chess:8,
    go:Number(state.selectedGoBoardSize || 9),
    domino:7,
  }[type] || defaultSize(type);
}

function defaultSize(type){
  return {
    tictactoe:3,
    four_in_a_row:7,
    battleship:10,
    checkers:8,
    reversi:8,
    chess:8,
    go:9,
    domino:7,
  }[type] || 3;
}

function titleFor(type){
  const key = {
    tictactoe:'tictactoe',
    four_in_a_row:'four_in_a_row',
    battleship:'battleship',
    checkers:'checkers',
    reversi:'reversi',
    chess:'chess',
    go:'go',
    domino:'domino',
  }[type];
  return key ? t(`game_invites.game_titles.${key}`) : t('game_invites.game_fallback');
}

function rememberBoardSelection(type, size){
  if (type === 'tictactoe') state.selectedBoardSize = size;
  else if (type === 'four_in_a_row') state.selectedFourBoardSize = size;
  else if (type === 'reversi') state.selectedReversiBoardSize = size;
  else if (type === 'go') state.selectedGoBoardSize = size;
}

function rememberUserAndSession(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function escapeRegExp(value){
  return String(value ?? '').replace(/[.*+?^$\{\}()|[\]\\]/g, '\\$&');
}
