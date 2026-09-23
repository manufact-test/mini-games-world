import { api } from '../api/client.js?v=47';
import { currentScreen, onScreenEnter } from '../router.js?v=27';
import { t, formatNumber } from '@mgw/i18n';
import { state } from '../state.js?v=27';
import { renderBalances } from '../ui.js?v=90-wallet-15-3';
import { enterGame } from './game-screen-v102-safe.js?v=102';

const GAME_TYPES = Object.freeze([
  'tictactoe',
  'four_in_a_row',
  'battleship',
  'checkers',
  'reversi',
  'chess',
  'go',
  'domino',
]);
const DEFAULT_GAME = 'tictactoe';
const CACHE_TTL_MS = 45_000;
const TOURNAMENT_T0_SYNC_INTERVAL_MS = 250;
const TOURNAMENT_T0_SYNC_WINDOW_MS = 6000;
const cache = new Map();
const inFlight = new Map();
const archiveSeasonCache = new Map();
let archiveOverviewCache = null;
let archiveOverviewLoadedAt = 0;
let archiveOverviewPromise = null;
let selectedArchiveSeasonId = '';
let activeGame = DEFAULT_GAME;
let initialized = false;
let dragState = null;
let suppressClickUntil = 0;
let tournamentSnapshot = null;
let tournamentRequest = null;
let tournamentBusy = false;
let tournamentPendingAction = '';
let tournamentRulesAccepted = false;
let tournamentRulesSha256 = '';
let tournamentCountdownTimer = null;
let tournamentHallSnapshot = null;
let tournamentHallRequest = null;
let tournamentHallBusy = false;
let tournamentHallError = '';
let tournamentHallTimer = null;
let tournamentVisibleRefreshTimer = null;
let tournamentMatchSnapshot = null;
let tournamentProgressionSnapshot = null;
let tournamentMatchRequest = null;
let tournamentMatchBusy = false;
let tournamentMatchError = '';
let tournamentLaunchWatchTimer = null;
let tournamentStartBoundaryTimer = null;
let tournamentStartSyncTimer = null;
let tournamentTerminalSyncPromise = null;
let tournamentTerminalReturnPending = false;
const tournamentRoundArchiveOpen = new Map();
const tournamentRoundArchiveScrollTop = new Map();
let tournamentRoundArchiveTournamentId = '';
let tournamentLastRenderedRoundNo = 0;

function lockVisibleBalance(){
  const ids = ['balanceUnified', 'topbarBalanceUnified'];
  const snapshots = new Map();
  const elements = [];

  for (const id of ids) {
    const element = document.getElementById(id);
    if (!(element instanceof HTMLElement)) continue;
    snapshots.set(element, String(element.textContent || '—'));
    elements.push(element);
  }

  if (elements.length === 0 || typeof MutationObserver !== 'function') {
    return () => {};
  }

  let restoring = false;
  const restore = () => {
    if (restoring) return;
    restoring = true;
    try {
      for (const element of elements) {
        const expected = snapshots.get(element);
        if (expected !== undefined && String(element.textContent || '') !== expected) {
          element.textContent = expected;
        }
      }
    } finally {
      restoring = false;
    }
  };

  const observer = new MutationObserver(restore);
  for (const element of elements) {
    observer.observe(element, { childList:true, subtree:true, characterData:true });
  }

  return () => {
    observer.disconnect();
  };
}

export function initTournamentsScreen(){
  if (initialized) return;
  const screen = document.getElementById('screen-tournaments');
  const content = screen?.querySelector('.content');
  if (!(screen instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

  initialized = true;
  screen.dataset.mgwTournaments = 'leaderboards-v2 rating-archive-v1 tournament-archive-v1 official-tournament-registration-v2-rules';
  content.innerHTML = `
    <div class="tournaments-v2" id="tournamentsV2Root">
      <div class="page-head app-shell-page-head tournaments-v2-page-head">
        <div>
          <h1 class="page-title">${escapeHtml(t('shell.tournaments_title'))}</h1>
        </div>
      </div>

      <div class="tournaments-v2-mode-tabs" role="tablist" aria-label="${escapeHtml(t('shell.tournaments_title'))}">
        <button class="tournaments-v2-mode-tab active" type="button" role="tab" aria-selected="true" data-competition-mode="rating">${escapeHtml(t('shell.competition_rating'))}</button>
        <button class="tournaments-v2-mode-tab" type="button" role="tab" aria-selected="false" data-competition-mode="tournaments">${escapeHtml(t('shell.competition_tournaments'))}</button>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="rating">
      <section class="tournaments-v2-board" aria-labelledby="tournamentsLeaderboardTitle">
        <div class="tournaments-v2-board-head">
          <div>
            <h2 id="tournamentsLeaderboardTitle">${escapeHtml(t('profile.leaderboard_title'))}</h2>
            <p>${escapeHtml(t('profile.leaderboard_open_note'))}</p>
          </div>
        </div>

        <div class="tournaments-v2-tabs-shell">
          <button class="tournaments-v2-scroll tournaments-v2-scroll--left" type="button" data-tournaments-scroll="-1" aria-label="Прокрутить игры влево">${scrollIcon('left')}</button>
          <div class="tournaments-v2-tabs" id="tournamentsLeaderboardTabs" aria-label="${escapeHtml(t('profile.leaderboard_title'))}">
            ${GAME_TYPES.map(type => tabMarkup(type, activeGame)).join('')}
          </div>
          <button class="tournaments-v2-scroll tournaments-v2-scroll--right" type="button" data-tournaments-scroll="1" aria-label="Прокрутить игры вправо">${scrollIcon('right')}</button>
        </div>

        <div class="tournaments-v2-body" id="tournamentsLeaderboardBody" aria-live="polite">
          ${loadingMarkup()}
        </div>
      </section>

      <section class="tournaments-v2-board tournaments-v2-archive" aria-labelledby="ratingArchiveTitle">
        <div class="tournaments-v2-board-head">
          <div>
            <h2 id="ratingArchiveTitle">${escapeHtml(t('shell.competition_archive_title'))}</h2>
            <p>${escapeHtml(t('shell.competition_archive_note'))}</p>
          </div>
        </div>
        <div class="tournaments-v2-archive-tabs" role="tablist" aria-label="${escapeHtml(t('shell.competition_archive_title'))}">
          <button class="tournaments-v2-mode-tab active" type="button" role="tab" aria-selected="true" data-rating-history-mode="seasons">${escapeHtml(t('shell.competition_archive_seasons'))}</button>
          <button class="tournaments-v2-mode-tab" type="button" role="tab" aria-selected="false" data-rating-history-mode="tournaments">${escapeHtml(t('shell.competition_archive_tournaments'))}</button>
        </div>
        <div data-rating-history-panel="seasons">
          <div class="tournaments-v2-archive-body" id="ratingArchiveBody">${loadingMarkup()}</div>
        </div>
        <div data-rating-history-panel="tournaments" hidden>
          <div class="tournaments-v2-archive-body" id="tournamentArchiveBody">${loadingMarkup()}</div>
        </div>
      </section>
      </div>

      <div class="tournaments-v2-panel" data-competition-panel="tournaments" hidden>
        <section class="tournaments-v2-board tournaments-v2-tournament-card">
          <div class="tournaments-v2-board-head">
            <div>              <h2>Официальный турнир</h2>
            </div>
          </div>
          <div class="tournaments-v2-tournament-body" id="officialTournamentBody" aria-live="polite">
            ${loadingMarkup()}
          </div>
        </section>
      </div>
    </div>
  `;

  bindModeTabs(screen);
  bindArchiveModeTabs(screen);
  bindArchiveSeasonClicks(screen);
  bindTabs(screen);
  bindScrollButtons(screen);
  bindTournamentActions(screen);
  onScreenEnter('tournaments', () => {
    void activateGame(activeGame);
    void loadArchiveOverview();
    void loadTournamentSnapshot();
  });

  document.addEventListener('mgw:tournament-progression-open', () => {
    const tournamentScreen = document.getElementById('screen-tournaments');
    if (!(tournamentScreen instanceof HTMLElement)) return;
    stopTournamentLaunchWatch();
    stopTournamentStartBoundaryRefresh();
    stopTournamentStartSync();
    // Do not discard the terminal progression already synchronized while the
    // result sheet was open. Clearing it here caused the Hall to briefly fall
    // back to the old "loading readiness/opponent" card and made one failed
    // refresh look like a total tournament load failure.
    tournamentMatchError = '';
    tournamentTerminalReturnPending = true;
    tournamentScreen.querySelectorAll('[data-competition-mode]').forEach(candidate => {
      const active = String(candidate.dataset.competitionMode || '') === 'tournaments';
      candidate.classList.toggle('active', active);
      candidate.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    tournamentScreen.querySelectorAll('[data-competition-panel]').forEach(panel => {
      panel.hidden = String(panel.dataset.competitionPanel || '') !== 'tournaments';
    });
    renderTournamentSnapshot();
    void synchronizeTournamentTerminalProgression()
      .finally(() => { void loadTournamentSnapshot(); });
  });

  document.addEventListener('mgw:game-finished', event => {
    const game = state.activeGame;
    const finishedId = String(event?.detail?.gameId || '');
    if (!game?.id
        || String(game.id) !== finishedId
        || String(game.match_source || '') !== 'tournament') return;
    stopTournamentLaunchWatch();
    stopTournamentStartBoundaryRefresh();
    stopTournamentStartSync();
    tournamentTerminalReturnPending = true;
    void synchronizeTournamentTerminalProgression();
  });

  document.addEventListener('mgw:app-ready', () => {
    window.setTimeout(() => { void warmLeaderboard(DEFAULT_GAME); }, 260);
    window.setTimeout(() => { void warmArchiveOverview(); }, 520);
    window.setTimeout(() => { void warmTournamentStatus(); }, 760);
  }, { once:true });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') {
      stopTournamentHallHeartbeat();
      stopTournamentVisibleRefresh();
      stopTournamentLaunchWatch();
      stopTournamentStartBoundaryRefresh();
      stopTournamentStartSync();
      stopTournamentRenderedCountdownTicker();
      return;
    }
    if (tournamentHallPanelVisible()) {
      // Telegram WebView may have been suspended while the operator assigned a
      // tournament date in Admin. Resume with an immediate authoritative refresh
      // instead of waiting for the next 2s/3s timer phase.
      void loadTournamentSnapshot();
      startTournamentVisibleRefresh();
      if (tournamentHallSnapshot?.hall?.entered === true) {
        startTournamentHallHeartbeat();
      }
    }
  });

  if (currentScreen() === 'tournaments') {
    void activateGame(activeGame);
    void loadArchiveOverview();
  }
  window.requestAnimationFrame(updateScrollAffordances);
}

function bindArchiveModeTabs(screen){
  screen.querySelectorAll('[data-rating-history-mode]').forEach(button => {
    button.addEventListener('click', () => {
      const mode = String(button.dataset.ratingHistoryMode || '');
      if (!['seasons','tournaments'].includes(mode)) return;
      screen.querySelectorAll('[data-rating-history-mode]').forEach(candidate => {
        const active = String(candidate.dataset.ratingHistoryMode || '') === mode;
        candidate.classList.toggle('active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      screen.querySelectorAll('[data-rating-history-panel]').forEach(panel => {
        panel.hidden = String(panel.dataset.ratingHistoryPanel || '') !== mode;
      });
      if (mode === 'seasons') void loadArchiveOverview();
      if (mode === 'tournaments') void loadTournamentArchiveOverview();
    });
  });
}

function bindArchiveSeasonClicks(screen){
  screen.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-rating-archive-season]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const seasonId = String(button.dataset.ratingArchiveSeason || '').trim();
    if (!seasonId) return;
    selectedArchiveSeasonId = seasonId;
    syncArchiveSeasonButtons();
    void loadArchiveSeason(seasonId, activeGame);
  });
}

function bindModeTabs(screen){
  screen.querySelectorAll('[data-competition-mode]').forEach(button => {
    button.addEventListener('click', () => {
      const mode = String(button.dataset.competitionMode || '');
      if (!['rating','tournaments'].includes(mode)) return;
      screen.querySelectorAll('[data-competition-mode]').forEach(candidate => {
        const active = String(candidate.dataset.competitionMode || '') === mode;
        candidate.classList.toggle('active', active);
        candidate.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      screen.querySelectorAll('[data-competition-panel]').forEach(panel => {
        panel.hidden = String(panel.dataset.competitionPanel || '') !== mode;
      });
      if (mode === 'rating') {
        stopTournamentHallHeartbeat();
        stopTournamentVisibleRefresh();
        stopTournamentLaunchWatch();
        stopTournamentStartBoundaryRefresh();
        stopTournamentStartSync();
        stopTournamentRenderedCountdownTicker();
        void activateGame(activeGame);
      }
      if (mode === 'tournaments') {
        void loadTournamentSnapshot();
      }
    });
  });
}

function bindTournamentActions(screen){
  screen.addEventListener('change', event => {
    const input = event.target instanceof Element
      ? event.target.closest('[data-tournament-rules-consent]')
      : null;
    if (!(input instanceof HTMLInputElement)) return;
    tournamentRulesAccepted = input.checked;
    const button = screen.querySelector('[data-tournament-action="register"]');
    if (button instanceof HTMLButtonElement) {
      const tournament = tournamentSnapshot?.tournament;
      const registered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
      const fee = Math.max(0, Number(tournament?.entry_fee?.amount || 50000));
      const available = Math.max(0, Number(tournamentSnapshot?.balance?.available_amount || 0));
      button.disabled = tournamentBusy || (!registered && available < fee) || !tournamentRulesAccepted;
    }
  });

  screen.addEventListener('click', event => {
    const readyButton = event.target instanceof Element
      ? event.target.closest('[data-tournament-ready]')
      : null;
    if (readyButton instanceof HTMLButtonElement) {
      if (!readyButton.disabled) void markTournamentReady();
      return;
    }

    const hallButton = event.target instanceof Element
      ? event.target.closest('[data-tournament-hall-enter]')
      : null;
    if (hallButton instanceof HTMLButtonElement) {
      if (!hallButton.disabled) void enterTournamentHall();
      return;
    }

    const terminalRatingButton = event.target instanceof Element
      ? event.target.closest('[data-tournament-terminal-rating]')
      : null;
    if (terminalRatingButton instanceof HTMLButtonElement) {
      const ratingTab = screen.querySelector('[data-competition-mode="rating"]');
      if (ratingTab instanceof HTMLButtonElement) ratingTab.click();
      return;
    }

    const button = event.target instanceof Element ? event.target.closest('[data-tournament-action]') : null;
    if (!(button instanceof HTMLButtonElement) || tournamentBusy) return;
    const action = String(button.dataset.tournamentAction || '');
    if (action === 'register') void mutateTournament('register');
    if (action === 'leave') void mutateTournament('leave');
  });
}

async function warmTournamentStatus(){
  if (tournamentRequest) return tournamentRequest;
  const previousTournamentId = String(tournamentSnapshot?.tournament?.tournament_id || '');
  tournamentRequest = api.tournamentStatus()
    .then(async result => {
      let nextSnapshot = result?.snapshot && typeof result.snapshot === 'object' ? result.snapshot : {};
      if (tournamentBusy) return tournamentSnapshot;

      // Recovery owner for the only durable-but-unpublished state. A WebView can
      // disappear after reservation/registration verification but before the
      // publication acknowledgement. Only that same registered account may
      // publish its own row when it next opens Tournament.
      if (String(nextSnapshot?.registration?.state || '') === 'registered'
          && nextSnapshot?.registration?.published === false) {
        const published = await api.tournamentRegistrationPublish();
        nextSnapshot = published?.snapshot && typeof published.snapshot === 'object'
          ? published.snapshot
          : nextSnapshot;
      }

      tournamentSnapshot = nextSnapshot;
      const nextTournamentId = String(tournamentSnapshot?.tournament?.tournament_id || '');
      if (previousTournamentId && nextTournamentId !== previousTournamentId) {
        tournamentHallSnapshot = null;
        tournamentHallError = '';
        tournamentMatchSnapshot = null;
        tournamentProgressionSnapshot = null;
        tournamentMatchError = '';
        stopTournamentHallHeartbeat();
        stopTournamentVisibleRefresh();
        stopTournamentLaunchWatch();
        stopTournamentStartSync();
      }
      syncTournamentRulesConsent();
      return tournamentSnapshot;
    })
    .finally(() => { tournamentRequest = null; });
  return tournamentRequest;
}

function tournamentHallPanelVisible(){
  if (currentScreen() !== 'tournaments' || document.visibilityState !== 'visible') return false;
  const panel = document.querySelector('[data-competition-panel="tournaments"]');
  return panel instanceof HTMLElement && !panel.hidden;
}

function stopTournamentHallHeartbeat(){
  if (tournamentHallTimer) window.clearTimeout(tournamentHallTimer);
  tournamentHallTimer = null;
}

function stopTournamentVisibleRefresh(){
  if (tournamentVisibleRefreshTimer) window.clearTimeout(tournamentVisibleRefreshTimer);
  tournamentVisibleRefreshTimer = null;
}

function stopTournamentLaunchWatch(){
  if (tournamentLaunchWatchTimer) window.clearTimeout(tournamentLaunchWatchTimer);
  tournamentLaunchWatchTimer = null;
}

function stopTournamentStartBoundaryRefresh(){
  if (tournamentStartBoundaryTimer) window.clearTimeout(tournamentStartBoundaryTimer);
  tournamentStartBoundaryTimer = null;
}

function stopTournamentStartSync(){
  if (tournamentStartSyncTimer) window.clearTimeout(tournamentStartSyncTimer);
  tournamentStartSyncTimer = null;
}

function scheduleTournamentStartBoundaryRefresh(scheduledStart, registered){
  stopTournamentStartBoundaryRefresh();
  if (!registered
      || !(scheduledStart instanceof Date)
      || scheduledStart.getTime() <= Date.now()
      || !tournamentHallPanelVisible()) return;

  const delay = Math.min(2_147_000_000, Math.max(0, scheduledStart.getTime() - Date.now() + 30));
  tournamentStartBoundaryTimer = window.setTimeout(() => {
    tournamentStartBoundaryTimer = null;
    startTournamentT0SyncBurst(scheduledStart);
  }, delay);
}

function startTournamentT0SyncBurst(scheduledStart){
  stopTournamentStartSync();
  if (!(scheduledStart instanceof Date) || !tournamentHallPanelVisible()) return;
  const stopAt = scheduledStart.getTime() + TOURNAMENT_T0_SYNC_WINDOW_MS;

  const tick = async () => {
    tournamentStartSyncTimer = null;
    if (!tournamentHallPanelVisible()) return;
    if (Date.now() < scheduledStart.getTime()) {
      tournamentStartSyncTimer = window.setTimeout(tick, Math.max(20, scheduledStart.getTime() - Date.now() + 20));
      return;
    }

    try {
      await warmTournamentStatus();

      // Every T0 burst read is fresh. A request started before T0 is allowed to
      // finish, but it cannot become the only source of truth for the bracket.
      const hallResult = await api.tournamentHallStatus();
      tournamentHallSnapshot = hallResult?.snapshot && typeof hallResult.snapshot === 'object'
        ? hallResult.snapshot
        : tournamentHallSnapshot;
      tournamentHallError = '';

      if (tournamentHallSnapshot?.bracket) {
        await refreshTournamentMatchState();
      }
    } catch (error) {
      tournamentMatchError = String(error?.message || 'Не удалось синхронизировать старт турнира.');
    }

    renderTournamentSnapshot();

    const matchReady = Boolean(tournamentMatchSnapshot?.match)
      || Boolean(tournamentProgressionSnapshot?.current_match)
      || Boolean(tournamentProgressionSnapshot?.latest_match);
    if (tournamentHallSnapshot?.bracket && matchReady) return;
    if (Date.now() >= stopAt) return;
    tournamentStartSyncTimer = window.setTimeout(tick, TOURNAMENT_T0_SYNC_INTERVAL_MS);
  };

  void tick();
}

function startTournamentLaunchWatch(){
  if (tournamentLaunchWatchTimer || !tournamentHallPanelVisible()) return;
  const match = tournamentMatchSnapshot?.match;
  const waitingForSharedGame = match
    && match.self_ready === true
    && !String(match.game_id || '').trim();
  if (!waitingForSharedGame) return;

  tournamentLaunchWatchTimer = window.setTimeout(async () => {
    tournamentLaunchWatchTimer = null;
    if (!tournamentHallPanelVisible()) return;
    try {
      await refreshTournamentMatchState();
    } catch (error) {
      tournamentMatchError = String(error?.message || 'Не удалось синхронизировать запуск матча.');
    }
    if (!tournamentHallPanelVisible()) return;
    renderTournamentSnapshot();
    startTournamentLaunchWatch();
  }, 350);
}

function startTournamentVisibleRefresh(){
  if (tournamentVisibleRefreshTimer || !tournamentHallPanelVisible()) return;

  tournamentVisibleRefreshTimer = window.setTimeout(async () => {
    tournamentVisibleRefreshTimer = null;
    const renderBefore = tournamentLiveRenderFingerprint();
    if (!tournamentHallPanelVisible()) return;

    try {
      await warmTournamentStatus();

      const registered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
      const scheduled = String(tournamentSnapshot?.tournament?.state || '') === 'scheduled'
        && Boolean(tournamentSnapshot?.tournament?.scheduled_start_at_utc);
      const scheduledStart = scheduled
        ? parseTournamentUtc(tournamentSnapshot?.tournament?.scheduled_start_at_utc)
        : null;
      const hallOpenByClock = scheduledStart instanceof Date
        && Date.now() >= scheduledStart.getTime() - (15 * 60 * 1000);

      if (registered && scheduled && hallOpenByClock && tournamentHallSnapshot?.hall?.entered !== true) {
        try {
          await warmTournamentHallStatus();
          if (tournamentHallSnapshot?.bracket) {
            await refreshTournamentMatchState();
          }
        } catch (error) {
          tournamentHallError = String(error?.message || 'Не удалось обновить Турнирный зал.');
        }
      } else if (!hallOpenByClock && tournamentHallSnapshot?.hall?.entered !== true) {
        tournamentHallError = '';
      }
    } catch (error) {
      tournamentHallError = String(error?.message || 'Не удалось обновить турнир.');
    }

    if (tournamentLiveRenderFingerprint() !== renderBefore) {
      renderTournamentSnapshot();
    }
    if (tournamentHallSnapshot?.hall?.entered === true) {
      startTournamentHallHeartbeat();
    }
    startTournamentVisibleRefresh();
  }, 2000);
}

async function warmTournamentHallStatus(){
  if (tournamentHallRequest) return tournamentHallRequest;
  tournamentHallRequest = api.tournamentHallStatus()
    .then(result => {
      tournamentHallSnapshot = result?.snapshot && typeof result.snapshot === 'object'
        ? result.snapshot
        : null;
      tournamentHallError = '';
      return tournamentHallSnapshot;
    })
    .finally(() => { tournamentHallRequest = null; });
  return tournamentHallRequest;
}

async function synchronizeTournamentTerminalProgression(){
  if (tournamentTerminalSyncPromise) return tournamentTerminalSyncPromise;
  tournamentTerminalSyncPromise = api.tournamentMatchState()
    .then(result => {
      tournamentMatchSnapshot = result?.snapshot && typeof result.snapshot === 'object'
        ? result.snapshot
        : tournamentMatchSnapshot;
      tournamentProgressionSnapshot = result?.progression && typeof result.progression === 'object'
        ? result.progression
        : tournamentProgressionSnapshot;
      tournamentMatchError = '';
      tournamentTerminalReturnPending = false;
      return result;
    })
    .catch(error => {
      tournamentMatchError = String(error?.message || 'Не удалось синхронизировать результат турнира.');
      throw error;
    })
    .finally(() => { tournamentTerminalSyncPromise = null; });
  return tournamentTerminalSyncPromise;
}

async function refreshTournamentMatchState(){
  if (tournamentMatchRequest) return tournamentMatchRequest;
  tournamentMatchRequest = api.tournamentMatchState()
    .then(result => {
      tournamentMatchSnapshot = result?.snapshot && typeof result.snapshot === 'object'
        ? result.snapshot
        : null;
      tournamentProgressionSnapshot = result?.progression && typeof result.progression === 'object'
        ? result.progression
        : tournamentProgressionSnapshot;
      tournamentMatchError = '';
      if (tournamentProgressionSnapshot?.latest_match?.completed_at_utc
          || tournamentProgressionSnapshot?.current_match
          || tournamentProgressionSnapshot?.tournament_complete === true) {
        tournamentTerminalReturnPending = false;
      }
      if (result?.game?.id && String(result.game.status || '') === 'active') {
        stopTournamentLaunchWatch();
        stopTournamentStartSync();
        enterGame(result.game);
      }
      return tournamentMatchSnapshot;
    })
    .finally(() => { tournamentMatchRequest = null; });
  return tournamentMatchRequest;
}

async function markTournamentReady(){
  if (tournamentMatchBusy) return;
  document.dispatchEvent(new CustomEvent('mgw:prime-launch-feedback'));
  tournamentMatchBusy = true;
  tournamentMatchError = '';
  renderTournamentSnapshot();
  try {
    const result = await api.tournamentMatchReady();
    tournamentMatchSnapshot = result?.snapshot && typeof result.snapshot === 'object'
      ? result.snapshot
      : tournamentMatchSnapshot;
    tournamentProgressionSnapshot = result?.progression && typeof result.progression === 'object'
      ? result.progression
      : tournamentProgressionSnapshot;
    if (result?.game?.id && String(result.game.status || '') === 'active') {
      stopTournamentLaunchWatch();
      stopTournamentStartSync();
      enterGame(result.game);
      return;
    }
    startTournamentLaunchWatch();
  } catch (error) {
    tournamentMatchError = String(error?.message || 'Не удалось подтвердить готовность.');
  } finally {
    tournamentMatchBusy = false;
    if (currentScreen() === 'tournaments') renderTournamentSnapshot();
    startTournamentLaunchWatch();
  }
}

async function enterTournamentHall(){
  if (tournamentHallBusy) return;
  tournamentHallBusy = true;
  tournamentHallError = '';
  renderTournamentSnapshot();
  try {
    const result = await api.tournamentHallEnter();
    tournamentHallSnapshot = result?.snapshot && typeof result.snapshot === 'object'
      ? result.snapshot
      : null;
  } catch (error) {
    tournamentHallError = String(error?.message || 'Не удалось войти в Турнирный зал.');
  } finally {
    tournamentHallBusy = false;
    renderTournamentSnapshot();
    if (tournamentHallSnapshot?.hall?.entered === true) {
      startTournamentHallHeartbeat();
      startTournamentVisibleRefresh();
    }
  }
}

function startTournamentHallHeartbeat(){
  if (tournamentHallTimer
      || tournamentHallSnapshot?.hall?.entered !== true
      || !tournamentHallPanelVisible()) return;

  tournamentHallTimer = window.setTimeout(async () => {
    tournamentHallTimer = null;
    const renderBefore = tournamentLiveRenderFingerprint();
    if (tournamentHallSnapshot?.hall?.entered !== true || !tournamentHallPanelVisible()) return;
    try {
      await warmTournamentStatus();
      if (!tournamentSnapshot?.tournament) {
        tournamentHallSnapshot = null;
        tournamentMatchSnapshot = null;
        tournamentProgressionSnapshot = null;
        tournamentHallError = '';
        tournamentMatchError = '';
        stopTournamentLaunchWatch();
        stopTournamentStartBoundaryRefresh();
        stopTournamentStartSync();
        renderTournamentSnapshot();
        return;
      }

      const result = await api.tournamentHallHeartbeat();
      tournamentHallSnapshot = result?.snapshot && typeof result.snapshot === 'object'
        ? result.snapshot
        : tournamentHallSnapshot;
      tournamentHallError = '';
      if (tournamentHallSnapshot?.bracket) {
        try { await refreshTournamentMatchState(); } catch (error) {
          tournamentMatchError = String(error?.message || 'Не удалось обновить готовность пары.');
        }
      }
    } catch (error) {
      try { await warmTournamentStatus(); } catch (_) {}
      if (!tournamentSnapshot?.tournament) {
        tournamentHallSnapshot = null;
        tournamentMatchSnapshot = null;
        tournamentProgressionSnapshot = null;
        tournamentHallError = '';
        tournamentMatchError = '';
        renderTournamentSnapshot();
        return;
      }
      tournamentHallError = String(error?.message || 'Не удалось обновить присутствие в Турнирный зал.');
    }
    if (tournamentLiveRenderFingerprint() !== renderBefore) {
      renderTournamentSnapshot();
    }
    startTournamentHallHeartbeat();
  }, 3000);
}

function syncTournamentRulesConsent(){
  const tournament = tournamentSnapshot?.tournament;
  const registration = tournamentSnapshot?.registration;
  const rules = tournament?.rules;
  const nextSha = String(rules?.sha256 || '');
  if (nextSha !== tournamentRulesSha256) {
    tournamentRulesSha256 = nextSha;
    tournamentRulesAccepted = false;
  }
  const accepted = registration?.rules_consent?.accepted === true
    && String(registration?.rules_consent?.sha256 || '') === nextSha;
  if (accepted) tournamentRulesAccepted = true;
}

async function loadTournamentSnapshot(){
  const body = document.getElementById('officialTournamentBody');
  if (!(body instanceof HTMLElement)) return;
  if (!tournamentSnapshot) body.innerHTML = loadingMarkup();
  try {
    await warmTournamentStatus();
    const registered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
    const scheduled = String(tournamentSnapshot?.tournament?.state || '') === 'scheduled'
      && Boolean(tournamentSnapshot?.tournament?.scheduled_start_at_utc);
    if (registered && scheduled) {
      try {
        await warmTournamentHallStatus();
        if (tournamentHallSnapshot?.bracket) await refreshTournamentMatchState();
      } catch (_) {}
    } else {
      tournamentHallSnapshot = null;
      tournamentHallError = '';
      tournamentMatchSnapshot = null;
      tournamentProgressionSnapshot = null;
      tournamentMatchError = '';
      stopTournamentHallHeartbeat();
    }
    renderTournamentSnapshot();
    startTournamentVisibleRefresh();
    if (tournamentHallSnapshot?.hall?.entered === true) {
      startTournamentHallHeartbeat();
    }
  } catch (error) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || 'Не удалось загрузить турнир.')}</div>`;
  }
}

async function mutateTournament(action){
  if (tournamentBusy) return;
  const tournament = tournamentSnapshot?.tournament;
  if (!tournament || typeof tournament !== 'object') return;

  if (action === 'register') {
    const rules = tournament?.rules;
    if (!tournamentRulesAccepted
        || !rules
        || !rules.version
        || !rules.language
        || !rules.sha256) {
      renderTournamentSnapshot('Перед регистрацией прочитайте правила и подтвердите согласие.');
      return;
    }
    const alreadyRegistered = String(tournamentSnapshot?.registration?.state || '') === 'registered';
    if (alreadyRegistered) {
      if (!window.confirm('Подтвердить обновлённые правила турнира?')) return;
    } else {
      const fee = formatNumber(Math.max(0, Number(tournament?.entry_fee?.amount || 50000)));
      if (!window.confirm(`Подтвердить правила и зарезервировать ${fee} коинов для участия в официальном турнире?`)) return;
    }
  } else if (action === 'leave') {
    if (!window.confirm('Отменить регистрацию? Зарезервированные 50 000 коинов вернутся в доступный баланс.')) return;
  }

  const releaseVisibleBalance = lockVisibleBalance();
  tournamentBusy = true;
  tournamentPendingAction = action;
  let errorMessage = '';
  let verifiedCommit = null;
  let verifiedUser = null;
  renderTournamentSnapshot();

  try {
    const result = action === 'register'
      ? await api.tournamentRegister({
          accepted:true,
          version:String(tournament?.rules?.version || ''),
          language:String(tournament?.rules?.language || ''),
          sha256:String(tournament?.rules?.sha256 || ''),
        })
      : await api.tournamentLeave();

    const responseSnapshot = result?.snapshot && typeof result.snapshot === 'object'
      ? result.snapshot
      : {};
    const responseUser = result?.user && typeof result.user === 'object'
      ? result.user
      : null;

    // The write may already be committed on the server, but the visible seat and
    // balance stay on their last confirmed values until the independent status
    // read finishes. Do not publish the write response while the spinner is active.
    tournamentPendingAction = 'verify';
    renderTournamentSnapshot();

    const verified = await api.tournamentStatus();
    const verifiedSnapshot = verified?.snapshot && typeof verified.snapshot === 'object'
      ? verified.snapshot
      : responseSnapshot;

    const registrationState = String(verifiedSnapshot?.registration?.state || '');
    if (action === 'register' && registrationState !== 'registered') {
      throw new Error('Регистрация не сохранилась. Попробуйте ещё раз.');
    }
    if (action === 'leave' && registrationState === 'registered') {
      throw new Error('Отмена регистрации не сохранилась. Попробуйте ещё раз.');
    }

    let publicationSnapshot = verifiedSnapshot;
    if (action === 'register' && verifiedSnapshot?.registration?.published === false) {
      tournamentPendingAction = 'publish';
      renderTournamentSnapshot();
      const published = await api.tournamentRegistrationPublish();
      publicationSnapshot = published?.snapshot && typeof published.snapshot === 'object'
        ? published.snapshot
        : verifiedSnapshot;
      if (publicationSnapshot?.registration?.published !== true) {
        throw new Error('Регистрация сохранилась, но ещё не опубликована. Повторите попытку.');
      }
    }

    const verifiedAvailable = Number(publicationSnapshot?.balance?.available_amount);
    verifiedCommit = publicationSnapshot;
    verifiedUser = responseUser
      ? {
          ...responseUser,
          ...(Number.isFinite(verifiedAvailable) ? { balance:verifiedAvailable } : {}),
        }
      : (state.user && typeof state.user === 'object' && Number.isFinite(verifiedAvailable)
          ? { ...state.user, balance:verifiedAvailable }
          : null);
  } catch (error) {
    errorMessage = humanizeTournamentError(error?.message || 'Не удалось изменить регистрацию.');
  } finally {
    // End the pending state first. Only then publish the verified tournament
    // snapshot and balance, so the user never sees money move under a live spinner.
    tournamentBusy = false;
    tournamentPendingAction = '';

    if (!errorMessage && verifiedCommit) {
      tournamentSnapshot = verifiedCommit;
      syncTournamentRulesConsent();
    }
    renderTournamentSnapshot(errorMessage);

    // Release the visible balance only after the pending button has already
    // been removed from the DOM. Any unrelated runtime poll that learned about
    // the server-side reservation while verification was still running was
    // prevented from repainting the header balance early.
    releaseVisibleBalance();

    if (!errorMessage && verifiedUser) {
      state.user = verifiedUser;
      renderBalances(state.user);
    }
  }
}

function tournamentHallMarkup(registered, scheduled, scheduledStart){
  if (!registered || !scheduled || !(scheduledStart instanceof Date)) return '';

  const hall = tournamentHallSnapshot?.hall && typeof tournamentHallSnapshot.hall === 'object'
    ? tournamentHallSnapshot.hall
    : null;
  const bracket = tournamentHallSnapshot?.bracket && typeof tournamentHallSnapshot.bracket === 'object'
    ? tournamentHallSnapshot.bracket
    : null;
  const opensAtMs = scheduledStart.getTime() - (15 * 60 * 1000);
  const started = Date.now() >= scheduledStart.getTime();
  const openByClock = Date.now() >= opensAtMs;
  const entered = hall?.entered === true;

  if (!entered) {
    const buttonLabel = tournamentHallBusy ? 'Входим в зал…' : 'Вход';
    return `
      <section class="tournaments-v2-hall-gate">
        <div>
          <span>Турнирный зал</span>
          <strong>${started ? 'Турнир стартовал' : openByClock ? 'Зал открыт' : 'Откроется за 15 минут до старта'}</strong>
          <small>В зал допускаются только участники этого турнира. Сетка появится в момент старта.</small>
        </div>
        <button type="button" class="tournaments-v2-tournament-action"
          data-tournament-hall-enter
          data-hall-opens-at="${opensAtMs}"
          data-hall-start-at="${scheduledStart.getTime()}"
          ${(!openByClock || tournamentHallBusy) ? 'disabled' : ''}
          ${tournamentHallBusy ? 'aria-busy="true"' : ''}>${escapeHtml(buttonLabel)}</button>
        ${tournamentHallError ? `<div class="tournaments-v2-tournament-error">${escapeHtml(tournamentHallError)}</div>` : ''}
      </section>`;
  }

  const roster = Array.isArray(hall?.roster) ? hall.roster : [];
  const rosterMarkup = roster.map(player => {
    const present = player?.present === true;
    const enteredPlayer = player?.entered === true;
    const status = bracket
      ? (present ? 'В зале на старте' : 'Техническое поражение')
      : present
        ? 'В зале'
        : enteredPlayer
          ? 'Нет активного присутствия'
          : 'Не вошёл';
    return `<div class="tournaments-v2-hall-player${present ? ' is-present' : ''}">
      <i aria-hidden="true"></i>
      <strong>${escapeHtml(String(player?.nickname || 'Игрок'))}</strong>
      <span>${escapeHtml(status)}</span>
    </div>`;
  }).join('');

  const bracketMarkup = bracket
    ? tournamentBracketMarkup(bracket)
    : `<div class="tournaments-v2-hall-waiting">
        <strong>Сетка ещё скрыта</strong>
        <span>Она сформируется случайно ровно на старте турнира.</span>
      </div>`;

  if (bracket) {
    return `
      <section class="tournaments-v2-hall tournaments-v2-hall--started">
        <div class="tournaments-v2-hall-head">
          <div>
            <span>Турнирный зал</span>
            <h3>Сетка турнира</h3>
          </div>
          <b>СТАРТ</b>
        </div>
        ${tournamentHallError ? `<div class="tournaments-v2-tournament-error">${escapeHtml(tournamentHallError)}</div>` : ''}
        ${bracketMarkup}
      </section>`;
  }

  return `
    <section class="tournaments-v2-hall">
      <div class="tournaments-v2-hall-head">
        <div>
          <span>Турнирный зал</span>
          <h3>Вы в турнирном зале</h3>
        </div>
        <b>LIVE</b>
      </div>
      ${tournamentHallError ? `<div class="tournaments-v2-tournament-error">${escapeHtml(tournamentHallError)}</div>` : ''}
      <div class="tournaments-v2-hall-roster">
        <div class="tournaments-v2-hall-section-title"><strong>Участники</strong><span>${escapeHtml(String(roster.length))}</span></div>
        <div class="tournaments-v2-hall-roster-grid">${rosterMarkup}</div>
      </div>
      ${bracketMarkup}
    </section>`;
}

function tournamentBracketMarkup(bracket){
  const matchMarkup = tournamentMatchMarkup();
  const roundsMarkup = tournamentRoundSectionsMarkup(bracket, tournamentProgressionSnapshot);
  return `<div class="tournaments-v2-bracket">
    ${matchMarkup}
    ${roundsMarkup}
  </div>`;
}


function tournamentMatchMarkup(){
  const progression = tournamentProgressionSnapshot && typeof tournamentProgressionSnapshot === 'object'
    ? tournamentProgressionSnapshot
    : null;
  const currentProgression = progression?.current_match && typeof progression.current_match === 'object'
    ? progression.current_match
    : null;
  const latestProgression = progression?.latest_match && typeof progression.latest_match === 'object'
    ? progression.latest_match
    : null;
  const progressedBeyondFirstReady = currentProgression
    && (Number(currentProgression.round_no || 0) > 1
      || Number(currentProgression.attempt_no || 1) > 1
      || String(currentProgression.wait_kind || 'initial_ready') !== 'initial_ready');
  if (progressedBeyondFirstReady) return tournamentProgressionMarkup(currentProgression, progression);
  if (!currentProgression && latestProgression?.completed_at_utc) {
    return tournamentProgressionMarkup(null, progression);
  }

  const match = tournamentMatchSnapshot?.match && typeof tournamentMatchSnapshot.match === 'object'
    ? tournamentMatchSnapshot.match
    : null;
  if (!match) {
    if (tournamentMatchError && !tournamentTerminalReturnPending) {
      return `<section class="tournaments-v2-ready">
        <div class="tournaments-v2-tournament-error">${escapeHtml(tournamentMatchError)}</div>
      </section>`;
    }
    const pendingCopy = tournamentTerminalReturnPending
      ? 'Сохраняем результат турнира…'
      : 'Загружаем готовность вашей пары…';
    return `<section class="tournaments-v2-ready">
      <div class="tournaments-v2-ready-head">
        <div><span>Турнирный матч</span><strong>${escapeHtml(pendingCopy)}</strong></div>
      </div>
    </section>`;
  }

  const players = Array.isArray(match.players) ? match.players : [];
  const playerRows = players.map(player => `
    <div class="tournaments-v2-ready-player${player?.ready === true ? ' is-ready' : ''}">
      <strong>${escapeHtml(String(player?.nickname || 'Игрок'))}${player?.self === true ? ' · вы' : ''}</strong>
      <span>${player?.ready === true ? 'Готов' : 'Ожидаем'}</span>
    </div>
  `).join('');
  const deadline = parseTournamentUtc(match.readiness_deadline_at_utc);
  const expired = String(match.launch_state || '') === 'readiness_expired';
  const launched = String(match.launch_state || '') === 'launched' || Boolean(match.game_id);
  const bothReady = match.both_ready === true;
  const selfReady = match.self_ready === true;
  const canReady = match.can_ready === true && !tournamentMatchBusy;

  let message = 'Подтвердите готовность в течение двух минут.';
  if (expired) message = 'Двухминутное окно готовности завершено.';
  else if (launched) message = 'Матч запущен.';
  else if (bothReady) message = 'Оба готовы · запускаем матч.';
  else if (selfReady) message = 'Вы готовы · ждём соперника.';

  const action = !selfReady && !expired && !launched
    ? `<button type="button" class="tournaments-v2-tournament-action tournaments-v2-ready-action"
        data-tournament-ready ${canReady ? '' : 'disabled'} ${tournamentMatchBusy ? 'aria-busy="true"' : ''}>
        ${tournamentMatchBusy ? 'Подтверждаем…' : 'Я готов'}
      </button>`
    : '';

  return `
    <section class="tournaments-v2-ready">
      <div class="tournaments-v2-ready-head">
        <div><span>Первый матч · пара ${escapeHtml(String(match.pair_no || ''))}</span><strong>${escapeHtml(message)}</strong></div>
        ${deadline && !launched ? `<b data-tournament-ready-countdown data-ready-deadline="${deadline.getTime()}">${escapeHtml(formatReadyCountdown(deadline.getTime() - Date.now()))}</b>` : ''}
      </div>
      <div class="tournaments-v2-ready-players">${playerRows}</div>
      ${tournamentMatchError ? `<div class="tournaments-v2-tournament-error">${escapeHtml(tournamentMatchError)}</div>` : ''}
      ${action}
    </section>
  `;
}

const TOURNAMENT_TECHNICAL_RESULT_LABELS = Object.freeze({
  technical_loss_at_start:'Технический исход · соперник отсутствовал.',
  both_absent_at_start:'Оба участника отсутствовали · победитель не назначен.',
  technical_bye_vacant_slot:'Технический проход · свободный слот.',
  vacant_bracket_slot:'Пара закрыта без участников.',
  player_left:'Технический исход · соперник покинул матч.',
  disconnect_timeout:'Технический исход · 60 секунд на возврат истекли.',
  tournament_disconnect_timeout:'Технический исход · один игрок не вернулся за 3 минуты.',
  tournament_both_absent_timeout:'Оба игрока не вернулись за 3 минуты · победитель не назначен.',
  technical_restart_scheduled:'Технический перезапуск через 1 минуту.',
  technical_restart_exhausted:'Технический сбой повторился · матч закрыт без победителя.',
});

function tournamentTechnicalOutcomeLabel(match){
  const reason = String(match?.result_reason || '');
  return TOURNAMENT_TECHNICAL_RESULT_LABELS[reason] || '';
}

function tournamentSeedFallbackRound(bracket){
  const seeds = Array.isArray(bracket?.seeds) ? bracket.seeds : [];
  const pairs = new Map();
  seeds.forEach(seed => {
    const pairNo = Number(seed?.pair_no || 0);
    if (!pairs.has(pairNo)) pairs.set(pairNo, []);
    pairs.get(pairNo).push(seed);
  });

  const matches = Array.from(pairs.entries())
    .sort((a,b) => a[0] - b[0])
    .map(([pairNo, pair]) => {
      const a = pair[0] || {};
      const b = pair[1] || {};
      const aLoss = a?.technical_loss === true;
      const bLoss = b?.technical_loss === true;
      let resultReason = null;
      let completed = false;
      let winnerMgw = '';

      if (aLoss && bLoss) {
        completed = true;
        resultReason = 'both_absent_at_start';
      } else if (aLoss !== bLoss) {
        completed = true;
        resultReason = 'technical_loss_at_start';
        winnerMgw = String((aLoss ? b : a)?.mgw_id || '');
      }

      const players = [a,b]
        .filter(player => String(player?.mgw_id || '') !== '')
        .map(player => {
          const id = String(player?.mgw_id || '');
          const winner = completed && winnerMgw !== '' && winnerMgw === id;
          return {
            mgw_id:id,
            nickname:String(player?.nickname || 'Игрок'),
            self:false,
            winner,
            loser:completed && !winner,
          };
        });

      return {
        round_no:1,
        pair_no:pairNo,
        attempt_no:1,
        wait_kind:'initial_ready',
        match_kind:'elimination',
        launch_state:completed ? 'completed' : 'waiting_ready',
        result_reason:resultReason,
        completed,
        players,
      };
    });

  return {
    round_no:1,
    completed_count:matches.filter(match => match.completed === true).length,
    total_count:matches.length,
    matches,
  };
}

function tournamentRoundLabel(round, rounds){
  const matches = Array.isArray(round?.matches) ? round.matches : [];
  const roundNo = Number(round?.round_no || 0);
  if (matches.some(match => ['final','third_place'].includes(String(match?.match_kind || '')))) {
    return 'Финальный раунд';
  }

  const ordered = Array.isArray(rounds) ? rounds : [];
  const currentIndex = ordered.findIndex(candidate => Number(candidate?.round_no || 0) === roundNo);
  const nextRound = currentIndex >= 0 ? ordered[currentIndex + 1] : null;
  const nextMatches = Array.isArray(nextRound?.matches) ? nextRound.matches : [];
  const nextIsFinal = nextMatches.some(match => ['final','third_place'].includes(String(match?.match_kind || '')));
  if (nextIsFinal && matches.length === 2) return 'Полуфинал';

  return `Раунд ${roundNo}`;
}

function tournamentRoundCardMarkup(match){
  const pairNo = Number(match?.pair_no || 0);
  const matchKind = String(match?.match_kind || 'elimination');
  const players = Array.isArray(match?.players) ? match.players : [];
  const done = match?.completed === true;
  const hasWinner = players.some(player => player?.winner === true);

  let title = `Пара ${pairNo}`;
  if (matchKind === 'final') title = 'Финал';
  else if (matchKind === 'third_place') title = 'Матч за 3-е место';

  const playerMarkup = players.length
    ? players.map(player => {
        const winner = player?.winner === true;
        let status = player?.self === true ? 'вы' : 'участник';
        if (done) {
          if (matchKind === 'final') {
            status = winner ? 'чемпион' : (hasWinner ? '2 место' : 'без результата');
          } else if (matchKind === 'third_place') {
            status = winner ? '3 место' : (hasWinner ? '4 место' : 'без результата');
          } else {
            status = winner ? 'прошёл дальше' : 'выбыл';
          }
        } else if (String(match?.launch_state || '') === 'launched') {
          status = player?.self === true ? 'вы · играет' : 'играет';
        }

        return `<div class="tournaments-v2-bracket-player${done && !winner ? ' is-loss' : ''}">
          <strong>${escapeHtml(String(player?.nickname || 'Игрок'))}${player?.self === true ? ' · вы' : ''}</strong>
          <span>${escapeHtml(status)}</span>
        </div>`;
      }).join('')
    : '<div class="tournaments-v2-bracket-player is-loss"><strong>Свободный слот</strong><span>без участника</span></div>';

  const technicalOutcome = tournamentTechnicalOutcomeLabel(match);
  let outcome = 'Ожидает запуска.';
  if (technicalOutcome) outcome = technicalOutcome;
  else if (done && hasWinner) {
    const winner = players.find(player => player?.winner === true);
    outcome = matchKind === 'final'
      ? 'Финал завершён.'
      : matchKind === 'third_place'
        ? 'Матч за 3-е место завершён.'
        : `${String(winner?.nickname || 'Игрок')} проходит дальше.`;
  } else if (done) outcome = 'Матч завершён · победитель не назначен.';
  else if (String(match?.launch_state || '') === 'launched') outcome = 'Матч идёт.';
  else if (String(match?.wait_kind || '') === 'technical_restart') outcome = 'Технический перезапуск через 1 минуту.';
  else if (String(match?.wait_kind || '') === 'round_break') outcome = 'Перерыв между раундами.';

  return `<article class="tournaments-v2-bracket-pair">
    <header><span>${escapeHtml(title)}</span></header>
    ${playerMarkup}
    <p>${escapeHtml(outcome)}</p>
  </article>`;
}

function tournamentRoundSectionsMarkup(bracket, progression){
  const progressionRounds = Array.isArray(progression?.rounds)
    ? progression.rounds.filter(round => Number(round?.round_no || 0) > 0)
    : [];
  const rounds = progressionRounds.length ? progressionRounds : [tournamentSeedFallbackRound(bracket)];
  const latestRoundNo = rounds.reduce(
    (max, round) => Math.max(max, Number(round?.round_no || 0)),
    0
  );
  const tournamentId = String(progression?.tournament_id || tournamentSnapshot?.tournament?.tournament_id || '');

  if (tournamentRoundArchiveTournamentId !== tournamentId) {
    tournamentRoundArchiveTournamentId = tournamentId;
    tournamentRoundArchiveOpen.clear();
    tournamentRoundArchiveScrollTop.clear();
    tournamentLastRenderedRoundNo = 0;
  }

  if (latestRoundNo > 0 && latestRoundNo !== tournamentLastRenderedRoundNo) {
    if (tournamentLastRenderedRoundNo > 0) {
      tournamentRoundArchiveOpen.set(tournamentLastRenderedRoundNo, false);
    }
    tournamentRoundArchiveOpen.set(latestRoundNo, true);
    tournamentLastRenderedRoundNo = latestRoundNo;
  }

  return rounds.map(round => {
    const roundNo = Number(round?.round_no || 0);
    const matches = Array.isArray(round?.matches) ? round.matches : [];
    const completed = Math.max(0, Number(round?.completed_count || 0));
    const total = Math.max(matches.length, Number(round?.total_count || 0));
    const heading = tournamentRoundLabel(round, rounds);
    const open = tournamentRoundArchiveOpen.has(roundNo)
      ? tournamentRoundArchiveOpen.get(roundNo) === true
      : roundNo === latestRoundNo;
    const cards = matches.map(tournamentRoundCardMarkup).join('');

    return `<details
      class="tournaments-v2-tournament-rules tournaments-v2-round-archive${roundNo === latestRoundNo ? ' is-current' : ''}"
      data-tournament-round-archive="${escapeHtml(String(roundNo))}"
      ${open ? 'open' : ''}>
      <summary><span>${escapeHtml(heading)} · ${escapeHtml(`${completed}/${total} завершено`)}</span></summary>
      <div class="tournaments-v2-tournament-rules-body">
        <div class="tournaments-v2-bracket-grid">${cards}</div>
      </div>
    </details>`;
  }).join('');
}


const TOURNAMENT_TERMINAL_REWARD_LABELS = Object.freeze({
  golden_ticket:'Golden Ticket',
  champion_crown:'Корона чемпиона · 30 дней',
  winner_badge:'Значок победителя · навсегда',
  champion_cosmetics:'Чемпионский набор · навсегда',
  hall_of_fame:'Зал славы',
  cup_gold:'Золотой кубок',
  silver_frame:'Серебряная рамка · 30 дней',
  finalist_result:'Отметка финалиста · навсегда',
  cup_silver:'Серебряный кубок',
  bronze_mark:'Бронзовая отметка · 30 дней',
  third_place_result:'3-е место · навсегда',
  cup_bronze:'Бронзовый кубок',
});

function tournamentTerminalRewardLabel(entitlement){
  const code = String(entitlement?.reward_code || '');
  return TOURNAMENT_TERMINAL_REWARD_LABELS[code] || code;
}

function tournamentTerminalMarkup(progression){
  const terminal = progression?.terminal_result && typeof progression.terminal_result === 'object'
    ? progression.terminal_result
    : null;
  if (!terminal || terminal.settlement_complete !== true) {
    const reviewHold = String(terminal?.settlement_state || '') === 'review_hold';
    const selfHeld = terminal?.prize_review?.self_held === true;
    return `
      <section class="tournaments-v2-terminal is-pending${reviewHold ? ' is-review-hold' : ''}">
        <div class="tournaments-v2-terminal-kicker">${reviewHold ? 'Призовая проверка' : 'Турнир завершён'}</div>
        <h3>${reviewHold
          ? (selfHeld ? 'Ваша призовая ветка временно удержана' : 'Часть призовой ветки временно удержана')
          : 'Подводим итоги и начисляем награды…'}</h3>
        <p>${reviewHold
          ? 'Зафиксирован серьёзный сигнал. Выплата не потеряна и не передана другому владельцу: после Admin review канонический settlement либо разрешит награду, либо применит дисквалификацию и сдвиг мест.'
          : 'Результат сетки уже зафиксирован. Начисление выполняется идемпотентно и будет повторено автоматически.'}</p>
      </section>
    `;
  }

  const podium = Array.isArray(terminal.podium) ? terminal.podium : [];
  const champion = podium.find(item => Number(item?.placement || 0) === 1);
  const selfResult = terminal.self_result && typeof terminal.self_result === 'object'
    ? terminal.self_result
    : null;
  const podiumMarkup = podium.slice(0,3).map(item => {
    const place = Number(item?.placement || 0);
    const title = place === 1 ? 'Чемпион' : place === 2 ? '2 место' : '3 место';
    const payoutLabel = item?.reward_eligible === false
      ? 'тестовый · без награды'
      : `${formatNumber(Math.max(0, Number(item?.payout_amount || 0)))} коинов`;
    return `<article class="tournaments-v2-terminal-place place-${place}${item?.self === true ? ' is-self' : ''}">
      <b>${escapeHtml(String(place))}</b>
      <div><span>${escapeHtml(title)}</span><strong>${escapeHtml(String(item?.nickname || 'Игрок'))}${item?.self === true ? ' · вы' : ''}</strong></div>
      <small>${escapeHtml(payoutLabel)}</small>
    </article>`;
  }).join('');

  let selfTitle = 'Участие завершено';
  if (String(selfResult?.result_code || '') === 'disqualified') selfTitle = 'Дисквалифицирован';
  else if (Number(selfResult?.placement || 0) > 0) selfTitle = `${Number(selfResult.placement)} место`;
  const payout = Math.max(0, Number(selfResult?.payout_amount || 0));
  const moneyCopy = payout > 0
    ? `Награда: ${formatNumber(payout)} коинов`
    : 'Денежной награды нет.';

  const entitlements = Array.isArray(selfResult?.entitlements) ? selfResult.entitlements : [];
  const rewardsMarkup = entitlements.length
    ? `<div class="tournaments-v2-terminal-rewards">${entitlements.map(item => `<span>${escapeHtml(tournamentTerminalRewardLabel(item))}</span>`).join('')}</div>`
    : '';
  const available = Number(selfResult?.balance?.available_amount);
  const balanceMarkup = Number.isFinite(available)
    ? `<small>Баланс после расчёта: <b>${escapeHtml(formatNumber(Math.max(0, available)))}</b></small>`
    : '';

  return `
    <section class="tournaments-v2-terminal">
      <div class="tournaments-v2-terminal-kicker">Все матчи турнира завершены. Награды начислены</div>
      <div class="tournaments-v2-terminal-hero">
        <div><span>Чемпион</span><h3>${escapeHtml(String(champion?.nickname || 'Не определён'))}</h3></div>
        <b aria-hidden="true">🏆</b>
      </div>
      <div class="tournaments-v2-terminal-podium">${podiumMarkup}</div>
      ${selfResult ? `<div class="tournaments-v2-terminal-self">
        <div><span>Ваш результат</span><strong>${escapeHtml(selfTitle)}</strong><p class="tournaments-v2-terminal-payout">${escapeHtml(moneyCopy)}</p></div>
        ${balanceMarkup}
        ${rewardsMarkup}
      </div>` : ''}
      <button class="tournaments-v2-tournament-action tournaments-v2-terminal-action" type="button" data-tournament-terminal-rating>Перейти к рейтингу</button>
    </section>
  `;
}

function tournamentProgressionMarkup(match, progression){
  const tournamentComplete = progression?.tournament_complete === true;
  if (tournamentComplete) {
    return tournamentTerminalMarkup(progression);
  }

  if (!match) {
    const latest = progression?.latest_match && typeof progression.latest_match === 'object'
      ? progression.latest_match
      : {};
    const latestRound = Number(latest.round_no || 0);
    const activeRoundNo = Number(progression?.active_round?.round_no || 0);
    const eliminated = progression?.participant_eliminated === true;
    let message = 'Ваш матч завершён · ждём остальные матчи раунда.';
    if (eliminated && activeRoundNo > latestRound) {
      message = 'Вы выбыли из турнира · сетка уже перешла в следующий раунд.';
    } else if (eliminated) {
      message = 'Вы выбыли из турнира.';
    } else if (activeRoundNo > latestRound) {
      message = 'Ваш матч завершён · следующий раунд уже сформирован.';
    }
    return `
      <section class="tournaments-v2-ready">
        <div class="tournaments-v2-ready-head">
          <div>
            <span>Раунд ${escapeHtml(String(latest.round_no || ''))}</span>
            <strong>${escapeHtml(message)}</strong>
          </div>
        </div>
      </section>
    `;
  }

  const roundNo = Number(match.round_no || 0);
  const pairNo = Number(match.pair_no || 0);
  const attemptNo = Math.max(1, Number(match.attempt_no || 1));
  const waitKind = String(match.wait_kind || '');
  const matchKind = String(match.match_kind || 'elimination');
  const opensAt = parseTournamentUtc(match.opens_at_utc);
  const waiting = opensAt instanceof Date && opensAt.getTime() > Date.now();
  let stage = `Раунд ${roundNo} · пара ${pairNo}`;
  if (matchKind === 'final') stage = 'Финал';
  else if (matchKind === 'third_place') stage = 'Матч за 3-е место';

  let message = 'Следующий матч готовится к запуску.';
  if (waitKind === 'draw_replay') {
    message = waiting
      ? 'Ничья · переигровка начнётся через минуту. Стороны меняются.'
      : 'Переигровка готова · запускаем матч.';
  } else if (waitKind === 'round_break') {
    message = waiting
      ? 'Раунд завершён · перерыв перед следующим матчем.'
      : 'Перерыв завершён · запускаем следующий матч.';
  }
  if (attemptNo > 1 && waitKind !== 'draw_replay') {
    message = waiting ? 'Повторный матч готовится.' : 'Повторный матч готов · запускаем.';
  }

  return `
    <section class="tournaments-v2-ready">
      <div class="tournaments-v2-ready-head">
        <div>
          <span>${escapeHtml(stage)}${attemptNo > 1 ? ` · попытка ${attemptNo}` : ''}</span>
          <strong>${escapeHtml(message)}</strong>
        </div>
        ${opensAt && waiting ? `<b data-tournament-progression-countdown data-progression-opens-at="${opensAt.getTime()}">${escapeHtml(formatReadyCountdown(opensAt.getTime() - Date.now()))}</b>` : ''}
      </div>
    </section>
  `;
}

function stopTournamentRenderedCountdownTicker(){
  if (tournamentCountdownTimer !== null) {
    window.clearInterval(tournamentCountdownTimer);
    tournamentCountdownTimer = null;
  }
}

function startTournamentRenderedCountdownTicker(body, scheduledStart = null){
  if (!(body instanceof HTMLElement)) return;
  stopTournamentRenderedCountdownTicker();

  let timerId = null;
  const updateCountdown = () => {
    if (!body.isConnected || !tournamentHallPanelVisible()) {
      if (timerId !== null) window.clearInterval(timerId);
      if (tournamentCountdownTimer === timerId) tournamentCountdownTimer = null;
      return;
    }

    const countdown = body.querySelector('[data-tournament-countdown]');
    const countdownLabel = body.querySelector('[data-tournament-countdown-label]');
    if (countdown instanceof HTMLElement && scheduledStart instanceof Date) {
      const remainingMs = scheduledStart.getTime() - Date.now();
      const startedNow = remainingMs <= 0;
      countdown.textContent = startedNow ? 'Турнир начался' : formatTournamentCountdown(remainingMs);
      if (countdownLabel instanceof HTMLElement) countdownLabel.hidden = startedNow;
      countdown.parentElement?.classList.toggle('is-started', startedNow);
    }

    const readyCountdown = body.querySelector('[data-tournament-ready-countdown]');
    if (readyCountdown instanceof HTMLElement) {
      const readyDeadline = Number(readyCountdown.dataset.readyDeadline || 0);
      readyCountdown.textContent = formatReadyCountdown(readyDeadline - Date.now());
    }

    const progressionCountdown = body.querySelector('[data-tournament-progression-countdown]');
    if (progressionCountdown instanceof HTMLElement) {
      const opensAt = Number(progressionCountdown.dataset.progressionOpensAt || 0);
      progressionCountdown.textContent = formatReadyCountdown(opensAt - Date.now());
    }

    const hallButton = body.querySelector('[data-tournament-hall-enter]');
    if (hallButton instanceof HTMLButtonElement && !tournamentHallBusy) {
      const opensAt = Number(hallButton.dataset.hallOpensAt || 0);
      const openNow = opensAt > 0 && Date.now() >= opensAt;
      hallButton.disabled = !openNow;
      hallButton.textContent = 'Вход';
    }
  };

  updateCountdown();
  timerId = window.setInterval(updateCountdown, 1000);
  tournamentCountdownTimer = timerId;
}

function captureTournamentArchiveViewport(body){
  body.querySelectorAll('[data-tournament-round-archive]').forEach(details => {
    if (!(details instanceof HTMLDetailsElement)) return;
    const roundNo = Number(details.dataset.tournamentRoundArchive || 0);
    if (roundNo <= 0) return;
    tournamentRoundArchiveOpen.set(roundNo, details.open);
    const scroller = details.querySelector('.tournaments-v2-tournament-rules-body');
    if (scroller instanceof HTMLElement) {
      tournamentRoundArchiveScrollTop.set(roundNo, scroller.scrollTop);
    }
  });
}

function restoreTournamentArchiveViewport(body){
  window.requestAnimationFrame(() => {
    if (!body.isConnected) return;
    body.querySelectorAll('[data-tournament-round-archive]').forEach(details => {
      if (!(details instanceof HTMLDetailsElement)) return;
      const roundNo = Number(details.dataset.tournamentRoundArchive || 0);
      if (roundNo <= 0) return;

      if (tournamentRoundArchiveOpen.has(roundNo)) {
        details.open = tournamentRoundArchiveOpen.get(roundNo) === true;
      }
      const scroller = details.querySelector('.tournaments-v2-tournament-rules-body');
      if (scroller instanceof HTMLElement && tournamentRoundArchiveScrollTop.has(roundNo)) {
        scroller.scrollTop = Number(tournamentRoundArchiveScrollTop.get(roundNo) || 0);
      }
    });
  });
}

function tournamentLiveRenderFingerprint(){
  const tournament = tournamentSnapshot?.tournament && typeof tournamentSnapshot.tournament === 'object'
    ? tournamentSnapshot.tournament
    : null;
  const registration = tournamentSnapshot?.registration && typeof tournamentSnapshot.registration === 'object'
    ? tournamentSnapshot.registration
    : null;
  const hall = tournamentHallSnapshot?.hall && typeof tournamentHallSnapshot.hall === 'object'
    ? tournamentHallSnapshot.hall
    : null;
  const roster = Array.isArray(hall?.roster)
    ? hall.roster.map(player => ({
        id:String(player?.mgw_id || ''),
        entered:player?.entered === true,
        present:player?.present === true,
      }))
    : [];

  return JSON.stringify({
    tournament:tournament ? {
      id:String(tournament.tournament_id || ''),
      state:String(tournament.state || ''),
      count:Number(tournament.registered_count || 0),
      capacity:Number(tournament.capacity || 0),
      start:String(tournament.scheduled_start_at_utc || ''),
    } : null,
    registration:registration ? {
      state:String(registration.state || ''),
      published:registration.published === true,
      rules:String(registration?.rules_consent?.sha256 || ''),
    } : null,
    hall:hall ? {
      entered:hall.entered === true,
      open:hall.open === true,
      started:hall.started === true,
      roster,
    } : null,
    bracket:tournamentHallSnapshot?.bracket || null,
    match:tournamentMatchSnapshot?.match || null,
    progression:tournamentProgressionSnapshot || null,
    hallError:tournamentHallError,
    matchError:tournamentMatchError,
    terminalPending:tournamentTerminalReturnPending,
    busy:tournamentBusy || tournamentHallBusy || tournamentMatchBusy,
    pendingAction:tournamentPendingAction,
  });
}

function renderTournamentSnapshot(errorMessage = ''){
  stopTournamentRenderedCountdownTicker();
  const body = document.getElementById('officialTournamentBody');
  if (!(body instanceof HTMLElement)) return;
  captureTournamentArchiveViewport(body);
  const snapshot = tournamentSnapshot && typeof tournamentSnapshot === 'object' ? tournamentSnapshot : {};
  const tournament = snapshot.tournament && typeof snapshot.tournament === 'object' ? snapshot.tournament : null;
  if (!tournament) {
    const cancelled = snapshot.last_cancellation && typeof snapshot.last_cancellation === 'object'
      ? snapshot.last_cancellation
      : null;
    if (cancelled) {
      const emergency = String(cancelled.kind || '') === 'emergency';
      const cancelledAt = parseTournamentUtc(cancelled.cancelled_at_utc);
      body.innerHTML = `
        <div class="tournaments-v2-tournament-hero">
          <div>
            <span class="tournaments-v2-tournament-state">${emergency ? 'Аварийная остановка' : 'Турнир отменён'}</span>
            <h3>${escapeHtml(String(cancelled.title || 'Официальный турнир'))}</h3>
            <p>${escapeHtml(gameName(String(cancelled.game_type || DEFAULT_GAME)))}</p>
          </div>
          <div class="tournaments-v2-tournament-entry"><small>Возврат</small><strong>${escapeHtml(formatNumber(Number(cancelled.refund_amount || 50000)))}</strong><span>коинов</span></div>
        </div>
        <div class="tournaments-v2-tournament-own is-registered">
          <strong>Взнос возвращён полностью. Результаты турнира аннулированы.</strong>
        </div>
        <div class="tournaments-v2-tournament-rules-body">
          <p><strong>Причина:</strong> ${escapeHtml(String(cancelled.reason || 'Турнир отменён администратором.'))}</p>
          ${cancelledAt ? `<p><strong>Закрыт:</strong> ${escapeHtml(formatTournamentDateTime(cancelledAt))} по вашему времени.</p>` : ''}
        </div>
      `;
      return;
    }
    body.innerHTML = `<div class="tournaments-v2-empty">Официальный турнир пока не создан или не открыт для участников.</div>`;
    return;
  }

  const registration = snapshot.registration && typeof snapshot.registration === 'object' ? snapshot.registration : null;
  const registered = String(registration?.state || '') === 'registered';
  const state = String(tournament.state || '');
  const open = state === 'registration_open';
  const waitingForDate = state === 'waiting_for_date' || tournament.waiting_for_date === true;
  const scheduled = state === 'scheduled' && Boolean(tournament.scheduled_start_at_utc);
  const scheduledStart = scheduled ? parseTournamentUtc(tournament.scheduled_start_at_utc) : null;
  scheduleTournamentStartBoundaryRefresh(scheduledStart, registered);
  const full = tournament.is_full === true;
  const capacity = Math.max(1, Number(tournament.capacity || 0));
  const count = Math.max(0, Number(tournament.registered_count || 0));
  const pct = Math.max(0, Math.min(100, Math.round((count / capacity) * 100)));
  const fee = Math.max(0, Number(tournament?.entry_fee?.amount || 50000));
  const available = Math.max(0, Number(snapshot?.balance?.available_amount || 0));
  const rewards = tournament.reward_snapshot && typeof tournament.reward_snapshot === 'object'
    ? tournament.reward_snapshot
    : {};
  const first = rewards?.placements?.['1'] || {};
  const second = rewards?.placements?.['2'] || {};
  const third = rewards?.placements?.['3'] || {};
  const rules = tournament.rules && typeof tournament.rules === 'object' ? tournament.rules : {};
  const rulesSnapshot = rules.snapshot && typeof rules.snapshot === 'object' ? rules.snapshot : {};
  const rulesSections = Array.isArray(rulesSnapshot.sections) ? rulesSnapshot.sections : [];
  const rulesReady = Boolean(rules.version && rules.language && rules.sha256 && rulesSections.length);
  const consentAccepted = registration?.rules_consent?.accepted === true
    && String(registration?.rules_consent?.sha256 || '') === String(rules.sha256 || '');
  const rulesMarkup = rulesReady
    ? `<details class="tournaments-v2-tournament-rules">
        <summary><span>Правила турнира</span></summary>
        <div class="tournaments-v2-tournament-rules-body">
          ${rulesSections.map(section => `
            <section>
              <h4>${escapeHtml(String(section?.title || ''))}</h4>
              <ul>${(Array.isArray(section?.items) ? section.items : []).map(item => `<li>${escapeHtml(String(item || ''))}</li>`).join('')}</ul>
            </section>
          `).join('')}
        </div>
      </details>`
    : '<div class="tournaments-v2-tournament-error">Правила турнира временно недоступны.</div>';

  const needsConsent = open && (!registered || !consentAccepted);
  const consentMarkup = needsConsent && rulesReady
    ? `<label class="tournaments-v2-tournament-consent">
        <input type="checkbox" data-tournament-rules-consent${tournamentRulesAccepted ? ' checked' : ''}${tournamentBusy ? ' disabled' : ''}>
        <span>Я прочитал(а) и принимаю правила этого турнира.</span>
      </label>`
    : consentAccepted
      ? `<div class="tournaments-v2-tournament-consent-proof">Правила турнира приняты${registration?.rules_consent?.accepted_at_utc ? ` · ${escapeHtml(formatConsentTime(registration.rules_consent.accepted_at_utc))}` : ''}.</div>`
      : '';

  const insufficient = !registered && available < fee;
  let action = '';
  if (open && !registered && !full) {
    const disabled = tournamentBusy || insufficient || !tournamentRulesAccepted || !rulesReady;
    const label = insufficient
      ? 'Недостаточно коинов'
      : tournamentBusy
        ? (tournamentPendingAction === 'register' ? 'Регистрируем…' : 'Проверяем…')
        : `Зарегистрироваться · ${escapeHtml(formatNumber(fee))}`;
    action = `<button type="button" class="tournaments-v2-tournament-action${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="register"${disabled ? ' disabled' : ''}${tournamentBusy ? ' aria-busy="true"' : ''}>${label}</button>`;
  } else if (open && registered && !full) {
    const cancelLabel = tournamentBusy
      ? (tournamentPendingAction === 'leave' ? 'Отменяем…' : 'Проверяем…')
      : 'Отменить регистрацию';
    const cancelButton = `<button type="button" class="tournaments-v2-tournament-action tournaments-v2-tournament-action--secondary${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="leave"${tournamentBusy ? ' disabled aria-busy="true"' : ''}>${cancelLabel}</button>`;

    if (!consentAccepted) {
      const confirmDisabled = tournamentBusy || !tournamentRulesAccepted || !rulesReady;
      const confirmLabel = tournamentBusy
        ? (tournamentPendingAction === 'register' ? 'Сохраняем согласие…' : 'Проверяем…')
        : 'Подтвердить правила';
      const confirmButton = `<button type="button" class="tournaments-v2-tournament-action${tournamentBusy ? ' is-pending' : ''}" data-tournament-action="register"${confirmDisabled ? ' disabled' : ''}${tournamentBusy ? ' aria-busy="true"' : ''}>${confirmLabel}</button>`;
      action = `${confirmButton}${cancelButton}`;
    } else {
      action = cancelButton;
    }
  }

  const statusText = state === 'draft'
    ? 'Турнир готовится · регистрация ещё не открыта'
    : scheduled
      ? 'Дата назначена · готовимся к старту'
      : waitingForDate
        ? 'Состав набран · ожидаем назначения даты'
        : full
          ? 'Состав заполнен'
          : 'Регистрация открыта';

  const ownStatus = registered
    ? (scheduled
      ? `Вы в составе. Турнир начнётся ${scheduledStart ? formatTournamentDateTime(scheduledStart) + ' по вашему времени' : 'в назначенное время'}.`
      : waitingForDate
        ? 'Вы в составе. Регистрация закрыта — ожидайте назначения даты турнира.'
        : full
          ? 'Вы в составе. Турнир заполнен — место зафиксировано.'
          : 'Вы зарегистрированы. Место закреплено за вами.')
    : scheduled
      ? 'Состав турнира зафиксирован. Регистрация завершена.'
      : waitingForDate || full
        ? 'Регистрация завершена. Свободных мест больше нет.'
        : insufficient
          ? 'Недостаточно коинов для регистрации.'
          : '';

  const tournamentStarted = Boolean(scheduledStart && scheduledStart.getTime() <= Date.now());
  const scheduleMarkup = scheduled && scheduledStart
    ? `<section class="tournaments-v2-tournament-schedule" aria-label="Дата и время турнира">
        <span>Начало турнира · по вашему времени</span>
        <strong>${escapeHtml(formatTournamentDateTime(scheduledStart))}</strong>
        <div class="tournaments-v2-tournament-countdown${tournamentStarted ? ' is-started' : ''}">
          <small data-tournament-countdown-label ${tournamentStarted ? 'hidden' : ''}>До старта</small>
          <b data-tournament-countdown>${escapeHtml(tournamentStarted ? 'Турнир начался' : formatTournamentCountdown(scheduledStart.getTime() - Date.now()))}</b>
        </div>
      </section>`
    : '';

  const hallMarkup = tournamentHallMarkup(registered, scheduled, scheduledStart);

  if (tournamentStarted && registered) {
    body.innerHTML = `
      ${errorMessage ? `<div class="tournaments-v2-tournament-error">${escapeHtml(errorMessage)}</div>` : ''}
      ${hallMarkup}
    `;
    restoreTournamentArchiveViewport(body);
    startTournamentRenderedCountdownTicker(body);
    if (tournamentHallSnapshot?.hall?.entered === true) {
      stopTournamentVisibleRefresh();
      startTournamentHallHeartbeat();
    } else {
      stopTournamentHallHeartbeat();
      startTournamentVisibleRefresh();
    }
    return;
  }

  body.innerHTML = `
    ${errorMessage ? `<div class="tournaments-v2-tournament-error">${escapeHtml(errorMessage)}</div>` : ''}
    <div class="tournaments-v2-tournament-hero">
      <div>
        <span class="tournaments-v2-tournament-state${open && !full ? ' is-open' : ''}">${escapeHtml(statusText)}</span>
        <h3>${escapeHtml(String(tournament.title || 'Официальный турнир'))}</h3>
        <p>${escapeHtml(gameName(String(tournament.game_type || DEFAULT_GAME)))}</p>
      </div>
      <div class="tournaments-v2-tournament-entry"><small>Вход</small><strong>${escapeHtml(formatNumber(fee))}</strong><span>коинов</span></div>
    </div>

    ${scheduleMarkup}
    ${hallMarkup}

    <div class="tournaments-v2-tournament-progress">
      <p class="tournaments-v2-tournament-capacity-copy">${scheduled ? 'Состав турнира зафиксирован. Дата назначена.' : waitingForDate ? 'Состав турнира набран. Регистрация закрыта.' : `В турнире участвуют ${escapeHtml(formatNumber(capacity))} игроков. Регистрация закроется, когда все места будут заняты.`}</p>
      <div class="tournaments-v2-tournament-participants"><span>Участники</span><strong>${escapeHtml(formatNumber(count))} / ${escapeHtml(formatNumber(capacity))}</strong></div>
      <div class="tournaments-v2-tournament-progress-track"><i style="width:${pct}%"></i></div>
    </div>

    <div class="tournaments-v2-tournament-prizes">
      <div><b>1 место</b><strong>${escapeHtml(formatNumber(Number(first.total || 200000)))}</strong><span>+ Golden Ticket</span></div>
      <div><b>2 место</b><strong>${escapeHtml(formatNumber(Number(second.total || 80000)))}</strong><span>серебряная награда</span></div>
      <div><b>3 место</b><strong>${escapeHtml(formatNumber(Number(third.total || 50000)))}</strong><span>бронзовая награда</span></div>
    </div>

    ${rulesMarkup}
    ${consentMarkup}
    ${ownStatus ? `<div class="tournaments-v2-tournament-own${registered ? ' is-registered' : ''}${insufficient ? ' is-insufficient' : ''}"><strong>${escapeHtml(ownStatus)}</strong></div>` : ''}
    ${action}
  `;
  restoreTournamentArchiveViewport(body);

  if (scheduled && scheduledStart) {
    startTournamentRenderedCountdownTicker(body, scheduledStart);
  }

  startTournamentVisibleRefresh();
  if (tournamentHallSnapshot?.hall?.entered === true) {
    startTournamentHallHeartbeat();
  } else {
    stopTournamentHallHeartbeat();
  }
}

function parseTournamentUtc(value){
  const raw = String(value || '').trim();
  if (!raw) return null;
  let normalized = raw.replace(' ', 'T');
  normalized = normalized.replace(/(\.\d{3})\d+/, '$1');
  if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(normalized)) normalized += 'Z';
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

function formatTournamentDateTime(value){
  const date = value instanceof Date ? value : parseTournamentUtc(value);
  if (!date) return String(value || '');
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function formatTournamentCountdown(remainingMs){
  const remaining = Math.max(0, Number(remainingMs || 0));
  if (remaining <= 0) return 'Турнир начался';
  const totalSeconds = Math.ceil(remaining / 1000);
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const time = `${String(hours).padStart(2,'0')}:${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
  return days > 0 ? `${days} дн. ${time}` : time;
}

function formatReadyCountdown(remainingMs){
  const seconds = Math.max(0, Math.ceil(Number(remainingMs || 0) / 1000));
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  return `${String(minutes).padStart(2,'0')}:${String(rest).padStart(2,'0')}`;
}

function formatConsentTime(value){
  const date = new Date(String(value || '').replace(' ', 'T') + (String(value || '').includes('Z') ? '' : 'Z'));
  if (Number.isNaN(date.getTime())) return String(value || '');
  return new Intl.DateTimeFormat('ru-RU', {
    day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit',
  }).format(date);
}

function humanizeTournamentError(message){
  const raw = String(message || '').trim();
  const known = new Map([
    ['Insufficient available balance.', 'Недостаточно доступных коинов для взноса 50 000.'],
    ['Tournament registration requires canonical DB-primary runtime state.', 'Регистрация временно недоступна: игровое состояние ещё не переключено на основной сервер.'],
    ['Tournament registration changed concurrently.', 'Регистрация изменилась одновременно с вашим запросом. Обновите турнир и попробуйте ещё раз.'],
    ['Concurrent balance update was detected.', 'Баланс изменился одновременно с регистрацией. Попробуйте ещё раз.'],
    ['Balance identity does not match the account reference.', 'Не удалось подтвердить игровой баланс аккаунта.'],
    ['Canonical tournament account_ref is required.', 'Не удалось подтвердить игровой аккаунт для регистрации.'],
  ]);
  return known.get(raw) || raw || 'Не удалось изменить регистрацию.';
}

function bindTabs(screen){
  const tabs = screen.querySelector('#tournamentsLeaderboardTabs');
  if (!(tabs instanceof HTMLElement)) return;

  tabs.addEventListener('scroll', updateScrollAffordances, { passive:true });
  window.addEventListener('resize', updateScrollAffordances, { passive:true });

  tabs.addEventListener('wheel', event => {
    if (tabs.scrollWidth <= tabs.clientWidth + 2) return;
    const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
    if (!Number.isFinite(delta) || delta === 0) return;
    tabs.scrollLeft += delta;
    event.preventDefault();
  }, { passive:false });

  tabs.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    dragState = {
      pointerId:event.pointerId,
      startX:event.clientX,
      startScrollLeft:tabs.scrollLeft,
      moved:false,
    };
  });

  tabs.addEventListener('pointermove', event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    const delta = event.clientX - dragState.startX;
    if (!dragState.moved && Math.abs(delta) < 6) return;
    if (!dragState.moved) {
      dragState.moved = true;
      try { tabs.setPointerCapture(event.pointerId); } catch (_) {}
      tabs.classList.add('is-dragging');
    }
    tabs.scrollLeft = dragState.startScrollLeft - delta;
    updateScrollAffordances();
  });

  const finishDrag = event => {
    if (!dragState || event.pointerId !== dragState.pointerId) return;
    if (dragState.moved) suppressClickUntil = performance.now() + 180;
    tabs.classList.remove('is-dragging');
    try {
      if (tabs.hasPointerCapture(event.pointerId)) tabs.releasePointerCapture(event.pointerId);
    } catch (_) {}
    dragState = null;
    updateScrollAffordances();
  };
  tabs.addEventListener('pointerup', finishDrag);
  tabs.addEventListener('pointercancel', finishDrag);

  tabs.addEventListener('click', event => {
    const button = event.target instanceof Element ? event.target.closest('[data-tournaments-game]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    if (performance.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    const nextGame = String(button.dataset.tournamentsGame || '').trim();
    if (!GAME_TYPES.includes(nextGame)) return;
    void activateGame(nextGame);
  });
}

function bindScrollButtons(screen){
  screen.querySelectorAll('[data-tournaments-scroll]').forEach(button => {
    button.addEventListener('click', () => {
      const tabs = document.getElementById('tournamentsLeaderboardTabs');
      if (!(tabs instanceof HTMLElement)) return;
      const direction = Number(button.dataset.tournamentsScroll || 0) < 0 ? -1 : 1;
      const amount = Math.max(180, Math.round(tabs.clientWidth * 0.72));
      tabs.scrollBy({ left:direction * amount, behavior:'smooth' });
    });
  });
}

async function activateGame(gameType){
  const next = GAME_TYPES.includes(gameType) ? gameType : DEFAULT_GAME;
  activeGame = next;
  syncActiveTab();

  const cached = currentCache(next);
  if (cached) {
    renderBoard(cached.board);
    void refreshArchiveForActiveGame();
  } else {
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = loadingMarkup();
  }

  try {
    const board = await warmLeaderboard(next);
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    renderBoard(board);
    void refreshArchiveForActiveGame();
  } catch (error) {
    if (activeGame !== next || currentScreen() !== 'tournaments') return;
    const body = document.getElementById('tournamentsLeaderboardBody');
    if (body) body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function warmLeaderboard(gameType){
  const cached = currentCache(gameType);
  if (cached) return cached.board;
  if (inFlight.has(gameType)) return inFlight.get(gameType);

  const request = api.leaderboard(gameType)
    .then(result => {
      const board = result?.leaderboard && typeof result.leaderboard === 'object'
        ? result.leaderboard
        : {};
      cache.set(gameType, { board, loadedAt:Date.now() });
      return board;
    })
    .finally(() => {
      inFlight.delete(gameType);
    });

  inFlight.set(gameType, request);
  return request;
}

async function warmArchiveOverview(){
  if (archiveOverviewCache && Date.now() - archiveOverviewLoadedAt <= CACHE_TTL_MS * 2) return archiveOverviewCache;
  if (archiveOverviewPromise) return archiveOverviewPromise;
  archiveOverviewPromise = api.ratingArchiveOverview()
    .then(result => {
      const archive = result?.archive && typeof result.archive === 'object' ? result.archive : {};
      archiveOverviewCache = archive;
      archiveOverviewLoadedAt = Date.now();
      return archive;
    })
    .finally(() => { archiveOverviewPromise = null; });
  return archiveOverviewPromise;
}

async function loadTournamentArchiveOverview(){
  const body = document.getElementById('tournamentArchiveBody');
  if (!(body instanceof HTMLElement)) return;
  try {
    const overview = await warmArchiveOverview();
    if (currentScreen() !== 'tournaments') return;
    renderTournamentArchiveOverview(overview);
  } catch (error) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || 'Не удалось загрузить архив турниров.')}</div>`;
  }
}

function renderTournamentArchiveOverview(overview){
  const body = document.getElementById('tournamentArchiveBody');
  if (!(body instanceof HTMLElement)) return;
  const tournaments = overview?.tournaments && typeof overview.tournaments === 'object'
    ? overview.tournaments
    : {};
  const entries = Array.isArray(tournaments.entries) ? tournaments.entries : [];
  const hall = Array.isArray(tournaments.hall_of_fame) ? tournaments.hall_of_fame : [];
  if (tournaments.available !== true || entries.length === 0) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(t('shell.competition_archive_tournaments_empty'))}</div>`;
    return;
  }

  const hallMarkup = hall.length
    ? `<section class="tournaments-v2-tournament-hof">
        <div class="tournaments-v2-hof-title">Зал славы турниров</div>
        <div class="tournaments-v2-tournament-hof-grid">
          ${hall.slice(0,12).map(tournamentHallOfFameCard).join('')}
        </div>
      </section>`
    : '';

  body.innerHTML = `
    ${hallMarkup}
    <div class="tournaments-v2-tournament-archive-list">
      ${entries.map(tournamentArchiveCard).join('')}
    </div>
  `;
}

function tournamentHallOfFameCard(entry){
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  const count = Math.max(1, Number(entry?.championship_count || 1));
  const tournamentDate = parseTournamentUtc(entry?.scheduled_start_at_utc || entry?.settled_at_utc);
  const meta = [
    gameName(String(entry?.game_type || DEFAULT_GAME)),
    tournamentDate ? formatTournamentDateTime(tournamentDate) : '',
  ].filter(Boolean).join(' · ');
  return `<article class="tournaments-v2-tournament-hof-card">
    <span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span>
    <div><strong>${escapeHtml(nickname)}</strong><span>${escapeHtml(meta)}</span></div>
    <b title="Чемпионств">${escapeHtml(formatNumber(count))}× 🏆</b>
  </article>`;
}

function tournamentArchiveCard(entry){
  const top3 = Array.isArray(entry?.top3) ? entry.top3 : [];
  const date = parseTournamentUtc(entry?.scheduled_start_at_utc || entry?.completed_at_utc);
  const dateLabel = date ? formatTournamentDateTime(date) : 'Дата не указана';
  const capacity = Math.max(0, Number(entry?.capacity || 0));
  const podium = top3.length
    ? `<div class="tournaments-v2-tournament-archive-podium">
        ${top3.map(item => {
          const place = Math.max(1, Number(item?.placement || 1));
          const avatar = String(item?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
          return `<div class="place-${place}">
            <b>#${escapeHtml(formatNumber(place))}</b>
            <span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span>
            <strong>${escapeHtml(String(item?.nickname || t('profile.player')))}</strong>
          </div>`;
        }).join('')}
      </div>`
    : '<div class="tournaments-v2-empty">Подиум недоступен.</div>';

  return `<article class="tournaments-v2-tournament-archive-card">
    <header>
      <div><span>Официальный турнир</span><strong>${escapeHtml(String(entry?.title || 'Официальный турнир'))}</strong></div>
      <small>${escapeHtml(dateLabel)}</small>
    </header>
    <p>${escapeHtml(gameName(String(entry?.game_type || DEFAULT_GAME)))}${capacity > 0 ? ` · ${escapeHtml(formatNumber(capacity))} участников` : ''}</p>
    ${podium}
  </article>`;
}

async function loadArchiveOverview(){
  const body = document.getElementById('ratingArchiveBody');
  if (!(body instanceof HTMLElement)) return;
  try {
    const overview = await warmArchiveOverview();
    if (currentScreen() !== 'tournaments') return;
    const seasons = Array.isArray(overview?.seasons) ? overview.seasons : [];
    if (!seasons.length) {
      selectedArchiveSeasonId = '';
      body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(t('shell.competition_archive_empty'))}</div>`;
      return;
    }
    if (!seasons.some(season => String(season?.season_id || '') === selectedArchiveSeasonId)) {
      selectedArchiveSeasonId = String(seasons[0]?.season_id || '');
    }
    body.innerHTML = archiveSeasonSelectorMarkup(seasons) + '<div id="ratingArchiveSeasonBoard"></div>';
    syncArchiveSeasonButtons();
    await loadArchiveSeason(selectedArchiveSeasonId, activeGame);
  } catch (error) {
    body.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function loadArchiveSeason(seasonId, gameType){
  if (!seasonId) return;
  const target = document.getElementById('ratingArchiveSeasonBoard');
  if (!(target instanceof HTMLElement)) return;
  const key = seasonId + '|' + gameType;
  const cached = archiveSeasonCache.get(key);
  if (cached && Date.now() - Number(cached.loadedAt || 0) <= CACHE_TTL_MS * 2) {
    renderArchiveSeason(cached.archive);
    return;
  }
  target.innerHTML = loadingMarkup();
  try {
    const result = await api.ratingArchiveSeason(seasonId, gameType);
    const archive = result?.archive && typeof result.archive === 'object' ? result.archive : {};
    archiveSeasonCache.set(key, { archive, loadedAt:Date.now() });
    if (seasonId !== selectedArchiveSeasonId || gameType !== activeGame) return;
    renderArchiveSeason(archive);
  } catch (error) {
    if (seasonId !== selectedArchiveSeasonId || gameType !== activeGame) return;
    target.innerHTML = `<div class="tournaments-v2-empty">${escapeHtml(error?.message || t('profile.leaderboard_error'))}</div>`;
  }
}

async function refreshArchiveForActiveGame(){
  if (!selectedArchiveSeasonId || currentScreen() !== 'tournaments') return;
  const panel = document.querySelector('[data-rating-history-panel="seasons"]');
  if (!(panel instanceof HTMLElement) || panel.hidden) return;
  await loadArchiveSeason(selectedArchiveSeasonId, activeGame);
}

function archiveSeasonSelectorMarkup(seasons){
  return `<div class="tournaments-v2-archive-season-tabs" role="tablist" aria-label="${escapeHtml(t('shell.competition_archive_seasons'))}">
    ${seasons.map(season => {
      const seasonId = String(season?.season_id || '');
      const label = seasonLabel(season);
      const active = seasonId === selectedArchiveSeasonId;
      return `<button type="button" class="tournaments-v2-archive-season-tab${active ? ' active' : ''}" data-rating-archive-season="${escapeHtml(seasonId)}" role="tab" aria-selected="${active ? 'true' : 'false'}">${escapeHtml(label)}</button>`;
    }).join('')}
  </div>`;
}

function syncArchiveSeasonButtons(){
  document.querySelectorAll('[data-rating-archive-season]').forEach(button => {
    const active = String(button.dataset.ratingArchiveSeason || '') === selectedArchiveSeasonId;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
  });
}

function renderArchiveSeason(archive){
  const target = document.getElementById('ratingArchiveSeasonBoard');
  if (!(target instanceof HTMLElement)) return;
  const entries = Array.isArray(archive?.entries) ? archive.entries : [];
  const season = archive?.season && typeof archive.season === 'object' ? archive.season : {};
  const top3 = hallOfFameForArchive(String(season?.season_id || ''), activeGame, archive);
  target.innerHTML = `
    <div class="tournaments-v2-archive-season-head">
      <div><strong>${escapeHtml(seasonLabel(season))}</strong><span>${escapeHtml(gameName(activeGame))}</span></div>
      <small>${escapeHtml(t('shell.competition_archive_top100'))}</small>
    </div>
    ${top3.length ? `<div class="tournaments-v2-hof"><div class="tournaments-v2-hof-title">${escapeHtml(t('shell.competition_hall_of_fame'))}</div><div class="tournaments-v2-hof-grid">${top3.map(hallOfFameCard).join('')}</div></div>` : ''}
    <div class="tournaments-v2-table-head" aria-hidden="true"><span>№</span><span>Игрок</span><span>Очки</span></div>
    <div class="tournaments-v2-list">
      ${entries.length ? entries.map(leaderboardRow).join('') : `<div class="tournaments-v2-empty">${escapeHtml(t('profile.leaderboard_empty'))}</div>`}
    </div>
  `;
}

function hallOfFameForArchive(seasonId, gameType, archive){
  const overview = archiveOverviewCache && typeof archiveOverviewCache === 'object' ? archiveOverviewCache : {};
  const durable = Array.isArray(overview.hall_of_fame)
    ? overview.hall_of_fame.filter(item => String(item?.season_id || '') === seasonId && String(item?.game_type || '') === gameType)
    : [];
  if (durable.length) return durable.slice(0, 3);
  return Array.isArray(archive?.top3) ? archive.top3.slice(0, 3) : [];
}

function hallOfFameCard(entry){
  const rank = Math.max(1, Number(entry?.rank || 1));
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  return `<article class="tournaments-v2-hof-card top-${rank}"><b>#${escapeHtml(formatNumber(rank))}</b><span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span><strong>${escapeHtml(nickname)}</strong></article>`;
}

function seasonLabel(season){
  const year = Math.max(0, Number(season?.calendar_year || 0));
  const quarter = Math.max(1, Number(season?.quarter || 1));
  return year > 0 ? `Q${quarter} · ${year}` : String(season?.season_id || '');
}

function currentCache(gameType){
  const item = cache.get(gameType);
  if (!item || Date.now() - Number(item.loadedAt || 0) > CACHE_TTL_MS) return null;
  return item;
}

function syncActiveTab(){
  document.querySelectorAll('#tournamentsLeaderboardTabs [data-tournaments-game]').forEach(button => {
    const active = String(button.dataset.tournamentsGame || '') === activeGame;
    button.classList.toggle('active', active);
    button.setAttribute('aria-selected', active ? 'true' : 'false');
    if (active) {
      button.scrollIntoView({ block:'nearest', inline:'nearest', behavior:'smooth' });
    }
  });
  window.requestAnimationFrame(updateScrollAffordances);
}

function updateScrollAffordances(){
  const tabs = document.getElementById('tournamentsLeaderboardTabs');
  const root = document.getElementById('tournamentsV2Root');
  if (!(tabs instanceof HTMLElement) || !(root instanceof HTMLElement)) return;

  const max = Math.max(0, tabs.scrollWidth - tabs.clientWidth);
  const left = tabs.scrollLeft > 3;
  const right = tabs.scrollLeft < max - 3;
  root.classList.toggle('can-scroll-left', left);
  root.classList.toggle('can-scroll-right', right);

  const leftButton = root.querySelector('[data-tournaments-scroll="-1"]');
  const rightButton = root.querySelector('[data-tournaments-scroll="1"]');
  if (leftButton instanceof HTMLButtonElement) leftButton.disabled = !left;
  if (rightButton instanceof HTMLButtonElement) rightButton.disabled = !right;
}

function renderBoard(board){
  const body = document.getElementById('tournamentsLeaderboardBody');
  if (!body) return;
  const data = board && typeof board === 'object' ? board : {};
  const entries = Array.isArray(data.entries) ? data.entries : [];
  const gameType = GAME_TYPES.includes(String(data.game_type || ''))
    ? String(data.game_type)
    : activeGame;

  body.innerHTML = `
    <div class="tournaments-v2-current-game">${escapeHtml(gameName(gameType))}</div>
    <div class="tournaments-v2-table-head" aria-hidden="true">
      <span>№</span>
      <span>Игрок</span>
      <span>Очки</span>
    </div>
    <div class="tournaments-v2-list">
      ${entries.length
        ? entries.map(leaderboardRow).join('')
        : `<div class="tournaments-v2-empty">${escapeHtml(t('profile.leaderboard_empty'))}</div>`}
    </div>
  `;
}

function leaderboardRow(entry){
  const rank = Math.max(1, Number(entry?.rank || 1));
  const nickname = String(entry?.nickname || t('profile.player')).trim() || t('profile.player');
  const avatar = String(entry?.avatar_item_id || 'starter-default-01').trim() || 'starter-default-01';
  const points = Math.max(0, Number(entry?.points || 0));
  const rankClass = rank <= 3 ? ` top-${rank}` : '';
  return `<div class="tournaments-v2-row${rankClass}">
    <b class="tournaments-v2-rank">${escapeHtml(formatNumber(rank))}</b>
    <span class="tournaments-v2-avatar" data-avatar-item-id="${escapeHtml(avatar)}" aria-hidden="true">MG</span>
    <strong class="tournaments-v2-name">${escapeHtml(nickname)}</strong>
    <span class="tournaments-v2-points" aria-label="${escapeHtml(t('profile.rating_points'))}: ${escapeHtml(formatNumber(points))}">${escapeHtml(formatNumber(points))}</span>
  </div>`;
}

function scrollIcon(direction){
  const path = direction === 'left' ? 'M9.5 3.5 5 8l4.5 4.5' : 'M6.5 3.5 11 8l-4.5 4.5';
  return `<svg class="tournaments-v2-scroll-icon" viewBox="0 0 16 16" aria-hidden="true"><path d="${path}"/></svg>`;
}

function tabMarkup(gameType, current){
  return `<button class="tournaments-v2-tab${gameType === current ? ' active' : ''}" type="button" role="tab" aria-selected="${gameType === current ? 'true' : 'false'}" data-tournaments-game="${escapeHtml(gameType)}">${escapeHtml(gameName(gameType))}</button>`;
}

function loadingMarkup(){
  return `<div class="tournaments-v2-empty">${escapeHtml(t('common.loading'))}</div>`;
}

function gameName(gameType){
  try { return t(`games.${gameType}.name`); } catch (_) { return gameType; }
}

function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#39;');
}
