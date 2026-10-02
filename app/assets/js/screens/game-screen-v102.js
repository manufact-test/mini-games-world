import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=89';
import { APP_CONFIG } from '../config.js?v=38';
import { haptic } from '../telegram/telegram-app.js?v=27';
import {
  gameMetaText,
  gameStatusText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface,
} from '../games/game-router-v102.js?v=102';
import { gameSurfaceFingerprint } from '../production-v97-models.js?v=97';
import { pollResultIsCurrent } from '../production-v99-models.js?v=99';
import {
  buildV100OptimisticGame,
  invalidateInFlightPoll,
  pendingSurfaceDescriptor,
} from '../production-v100-optimistic-models.js?v=102';
import { t, formatNumber as formatLocalizedNumber } from '@mgw/i18n';

const GAME_TYPES = new Set(['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino']);
const gameText = (key, params = {}) => t(`game_screen.${key}`, params);

const runtime = window.__MGW_V100_GAME_RUNTIME__ ||= {
  initialized:false,
  games:new Map(),
  pointerHoldUntil:0,
  resultOpened:new Set(),
  weeklyNotified:new Set(),
  tournamentResultDismissed:new Set(),
};
if (!(runtime.tournamentResultDismissed instanceof Set)) {
  runtime.tournamentResultDismissed = new Set();
}

export function initGameScreen(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  document.getElementById('leaveGame')?.addEventListener('click', requestLeaveGame);

  document.addEventListener('mgw:sheet-closed', () => {
    const game = state.activeGame;
    const id = String(game?.id || '');
    if (!id
        || String(game?.status || '') !== 'finished'
        || String(game?.match_source || '') !== 'tournament'
        || !runtime.resultOpened.has(id)
        || runtime.tournamentResultDismissed.has(id)) return;

    // Closing a tournament result sheet (including a backdrop tap) must never
    // expose the dead board underneath. Treat dismissal as "back to tournament".
    runtime.tournamentResultDismissed.add(id);
    state.activeGame = null;
    clearGameView();
    showScreen('tournaments');
    document.dispatchEvent(new CustomEvent('mgw:tournament-progression-open'));
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  });

  document.addEventListener('pointerdown', event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('#gameBoard button')) return;
    runtime.pointerHoldUntil = Date.now() + 700;
    const activeId = String(state.activeGame?.id || '');
    if (activeId) invalidateInFlightPoll(runtime, activeId);
  }, true);

  const release = event => {
    const origin = event.target;
    if (origin instanceof Element && origin.closest('#gameBoard')) {
      runtime.pointerHoldUntil = Date.now() + 140;
    }
  };
  document.addEventListener('pointerup', release, true);
  document.addEventListener('pointercancel', release, true);
}

export function enterGame(game, me = null){
  const id = String(game?.id || '');
  if (!id || String(game?.status || '') === '') return;

  if (String(game?.status || '') === 'finished'
      && String(game?.match_source || '') === 'tournament'
      && runtime.tournamentResultDismissed.has(id)) {
    state.timers.search = clearTimer(state.timers.search);
    state.timers.game = clearTimer(state.timers.game);
    state.activeGame = null;
    clearGameView();
    showScreen('tournaments');
    document.dispatchEvent(new CustomEvent('mgw:tournament-progression-open'));
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
    return;
  }

  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = game;
  state.selectedGame = gameTypeOf(game);

  const item = gameRuntime(id);
  const viewer = normalizeViewer(me) || item.viewer || resolveViewer(game);
  if (viewer) item.viewer = viewer;
  item.authoritative = clone(game);
  item.optimistic = clone(game);
  item.queue.length = 0;
  item.running = false;
  item.surrenderPending = false;
  item.generation++;

  closeSheet();
  if (viewer) renderGame(game, viewer, true);
  showScreen('game');

  if (String(game.status || '') === 'finished') {
    finishGame(game, viewer);
    return;
  }
  startGamePolling(id);
}

export function startGamePolling(gameId){
  const id = String(gameId || state.activeGame?.id || '');
  if (!id) return;
  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.timers.game = window.setInterval(() => refreshGame(id), APP_CONFIG.gameIntervalMs);
  window.setTimeout(() => refreshGame(id), Math.min(180, Math.max(60, Number(APP_CONFIG.gameIntervalMs || 450) / 3)));
}

export function clearGameView(){
  state.timers.game = clearTimer(state.timers.game);
  const board = document.getElementById('gameBoard');
  if (board) {
    board.replaceChildren();
    board.className = 'board size-3';
    delete board.dataset.gameType;
    delete board.dataset.mgwV100Fingerprint;
  }
  document.getElementById('playersRow')?.replaceChildren();
  const turn = document.getElementById('turnText');
  if (turn) turn.textContent = gameText('waiting_start');
  const timer = document.getElementById('timerText');
  if (timer) timer.textContent = '—';
}

async function refreshGame(gameId){
  const item = gameRuntime(gameId);
  if (item.pollBusy || item.running || item.queue.length || item.surrenderPending) return;
  if (document.visibilityState !== 'visible') return;
  if (runtime.pointerHoldUntil > Date.now()) return;

  item.pollBusy = true;
  const generation = item.generation;
  try {
    const result = await api.gameState(gameId);
    if (!pollResultIsCurrent(generation, item.generation, item.running || item.queue.length || item.surrenderPending)) return;
    rememberUserAndSession(result);

    if (!result?.game) {
      state.timers.game = clearTimer(state.timers.game);
      state.activeGame = null;
      clearGameView();
      if (document.getElementById('screen-game')?.classList.contains('active')) showScreen('home');
      return;
    }

    const game = result.game;
    const viewer = normalizeViewer(result.me) || item.viewer || resolveViewer(game);
    if (!viewer) return;
    item.viewer = viewer;
    item.authoritative = clone(game);
    item.optimistic = clone(game);
    state.activeGame = game;
    state.selectedGame = gameTypeOf(game);

    renderGame(game, viewer, false);
    if (String(game.status || '') === 'finished') finishGame(game, viewer);
  } catch (error) {
    // Background game-state polling is best-effort. The active read-only watcher
    // and the next poll will reconcile state, so an Android/WebView transport
    // blip must not surface a false "server unavailable" toast while the match
    // itself is still healthy. Action failures remain user-visible below.
    if (String(error?.code || '') !== 'network_unavailable') {
      const message = String(error?.message || '');
      if (message) toast(message);
    }
  } finally {
    item.pollBusy = false;
  }
}

function submitAction(gameId, action){
  const id = String(gameId || '');
  const item = gameRuntime(id);
  const base = item.optimistic || state.activeGame;
  if (!base || String(base.id || '') !== id || String(base.status || '') !== 'active' || item.surrenderPending) return false;

  const viewer = item.viewer || resolveViewer(base);
  if (!viewer?.id) return false;
  item.viewer = viewer;

  const type = gameTypeOf(base);
  const optimistic = buildV100OptimisticGame(base, action, viewer.id, type);
  const localBattleshipSetup = type === 'battleship' && String(base?.phase || '') === 'setup';
  const localBattleshipFire = type === 'battleship'
    && String(base?.phase || '') === 'battle'
    && String(action?.type || '') === 'fire';
  if ((localBattleshipSetup || localBattleshipFire) && !optimistic) return false;
  if (localBattleshipFire && item.queue.some(entry => String(entry?.action?.type || '') === 'fire')) return false;

  haptic('light');
  item.generation++;

  if (optimistic) {
    item.optimistic = optimistic;
    state.activeGame = optimistic;
    renderGame(optimistic, viewer, true);
  } else {
    const pending = clone(base);
    pending.__mgw_v100_pending_action = clone(action);
    item.optimistic = pending;
    state.activeGame = pending;
    renderGame(pending, viewer, true);
    document.getElementById('gameBoard')?.classList.add('is-submitting');
  }

  coalesceReplaceableAction(item, action, type, base);
  item.queue.push({ action:clone(action) });

  if (type === 'battleship'
      && String(base?.phase || '') === 'battle'
      && String(action?.type || '') === 'fire') {
    document.dispatchEvent(new CustomEvent('mgw:battleship-fire-queued', {
      detail:{
        gameId:id,
        playerId:String(viewer.id),
        cell:Number(action.cell),
      },
    }));
  }

  drainActions(id, item);
  return true;
}

function coalesceReplaceableAction(item, action, type, game){
  const replaceable = type === 'battleship'
    && String(game?.phase || '') === 'setup'
    && String(action?.type || '') === 'randomize_fleet';
  if (!replaceable) return;
  const protectedCount = item.running ? 1 : 0;
  for (let index = item.queue.length - 1; index >= protectedCount; index--) {
    if (String(item.queue[index]?.action?.type || '') === 'randomize_fleet') item.queue.splice(index, 1);
  }
}

async function drainActions(gameId, item){
  if (item.running) return;
  item.running = true;

  try {
    while (item.queue.length) {
      const queued = item.queue[0];
      let result;
      try {
        result = await api.gameAction(gameId, queued.action);
      } catch (error) {
        const reconciled = await reconcileBattleshipFireFailure(gameId, item, queued.action);
        if (reconciled === 'committed') {
          item.queue.shift();
          continue;
        }

        item.queue.length = 0;
        if (item.surrenderPending) break;
        restoreAuthoritative(item);
        toast(error?.message || gameText('errors.action_restore'));
        break;
      }

      if (item.surrenderPending) {
        item.queue.length = 0;
        break;
      }

      rememberUserAndSession(result);
      item.queue.shift();
      if (result?.game) {
        item.authoritative = clone(result.game);
        item.viewer = normalizeViewer(result.me) || item.viewer || resolveViewer(result.game);
      }

      if (item.queue.length) continue;

      const authoritative = result?.game || item.authoritative;
      if (!authoritative || !item.viewer) continue;
      item.optimistic = clone(authoritative);
      state.activeGame = authoritative;
      state.selectedGame = gameTypeOf(authoritative);
      item.generation++;

      // The authoritative result is already ready. Drop the temporary input lock
      // before rendering the fresh board so a retained-turn hit can be tapped immediately.
      document.getElementById('gameBoard')?.classList.remove('mgw-action-pending');
      renderGame(authoritative, item.viewer, false);

      if (String(authoritative.status || '') === 'finished') finishGame(authoritative, item.viewer);
    }
  } finally {
    item.running = false;
    document.getElementById('gameBoard')?.classList.remove('is-submitting', 'mgw-action-pending');
    item.generation++;
    if (item.queue.length) drainActions(gameId, item);
  }
}

async function reconcileBattleshipFireFailure(gameId, item, action){
  if (String(action?.type || '') !== 'fire') return 'not-applicable';

  const local = item?.optimistic || item?.authoritative || state.activeGame;
  if (gameTypeOf(local) !== 'battleship' || String(local?.phase || '') !== 'battle') return 'not-applicable';

  const cell = Number(action?.cell);
  if (!Number.isInteger(cell) || cell < 0 || cell > 99) return 'not-applicable';

  try {
    const result = await api.gameState(gameId);
    rememberUserAndSession(result);
    const game = result?.game;
    if (!game || String(game.id || '') !== String(gameId || '')) return 'not-applicable';

    const viewer = normalizeViewer(result.me) || item.viewer || resolveViewer(game);
    if (viewer) item.viewer = viewer;
    item.authoritative = clone(game);
    item.optimistic = clone(game);
    state.activeGame = game;
    state.selectedGame = gameTypeOf(game);
    item.generation++;

    if (viewer) {
      document.getElementById('gameBoard')?.classList.remove('mgw-action-pending');
      renderGame(game, viewer, false);
      if (String(game.status || '') === 'finished') finishGame(game, viewer);
    }

    const cellState = String(game?.enemy_board?.[cell] || 'unknown');
    return ['miss','hit','sunk'].includes(cellState) ? 'committed' : 'reconciled-uncommitted';
  } catch {
    return 'not-applicable';
  }
}

function gameTournamentCrownSvg(){
  return '<svg class="mgw-game-prestige-crown" viewBox="0 0 48 36" aria-hidden="true" focusable="false"><path d="M5 27 8 8l11 9L24 3l5 14 11-9 3 19H5Z" fill="currentColor"/><path d="M7 29h34v5H7z" rx="2" fill="currentColor"/><path d="M13 24h22" stroke="rgba(88,57,7,.6)" stroke-width="2.4" stroke-linecap="round"/></svg>';
}

function renderGame(game, me, forceSurface){
  if (!me?.id) return;
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');
  const screen = document.getElementById('screen-game');
  if (!meta || !turn || !timer || !players || !surface) return;

  const type = gameTypeOf(game);
  if (screen) {
    screen.dataset.gameType = type;
    screen.dataset.gamePhase = String(game?.phase || '');
  }
  meta.textContent = gameMetaText(game);
  turn.textContent = gameStatusText(game, me);
  const phaseClockOwned = type === 'tictactoe'
    && Object.prototype.hasOwnProperty.call(game || {}, 'launch_phase');
  if (String(game.status || '') !== 'active') timer.textContent = '—';
  else if (!phaseClockOwned) timer.textContent = gameText('timer_seconds', { count:formatLocalizedNumber(game.time_left ?? 60, { maximumFractionDigits:0 }) });

  const playersMarkup = (game.players || []).map(player => {
    const champion = player?.tournament_prestige?.champion_crown === true;
    return `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}${champion ? ' has-tournament-crown' : ''}">
      <div class="name"><span>${escapeHtml(player.name)}</span></div>
      <div class="mark">
        <span class="mgw-game-player-mark-stack">
          ${champion ? gameTournamentCrownSvg() : ''}
          <span class="mgw-game-player-mark-symbol">${escapeHtml(playerMarkText(game, player))}</span>
        </span>
        <span class="mgw-game-player-role">· ${String(player.id) === String(me.id) ? gameText('roles.you') : gameText('roles.opponent')}</span>
      </div>
    </div>
  `;
  }).join('');
  if (players.innerHTML !== playersMarkup) players.innerHTML = playersMarkup;

  const fingerprint = gameSurfaceFingerprint(game, me.id);
  const rendered = String(surface.dataset.mgwV100Fingerprint || '');
  if (forceSurface || surface.childElementCount === 0 || fingerprint !== rendered) {
    renderGameSurface({
      game,
      me,
      container:surface,
      onAction:action => submitAction(game.id, action),
    });
    surface.dataset.mgwV100Fingerprint = fingerprint;
  }

  decoratePendingSurface(surface, game, type);
}

function decoratePendingSurface(surface, game, type){
  surface.classList.remove('mgw-action-pending');
  surface.querySelectorAll('.mgw-pending-shot,.mgw-pending-action').forEach(node => {
    node.classList.remove('mgw-pending-shot', 'mgw-pending-action');
  });

  const descriptor = pendingSurfaceDescriptor(game, type);
  if (!descriptor) return;
  const node = surface.querySelector(descriptor.selector);
  if (node) {
    node.classList.add(descriptor.className);
    if ('disabled' in node) node.disabled = true;
  }

  if (type === 'battleship' && String(game?.phase || '') !== 'setup') {
    surface.classList.add('mgw-action-pending');
  }
}

function finishGame(game, me){
  if (!game?.id || !me?.id) return;
  const id = String(game.id);
  state.timers.game = clearTimer(state.timers.game);
  state.activeGame = game;
  renderGame(game, me, false);
  if (runtime.resultOpened.has(id)) return;
  runtime.resultOpened.add(id);

  const presentationDelay = fourTerminalPresentationDelay(game);
  if (presentationDelay > 0) {
    window.setTimeout(() => {
      if (String(state.activeGame?.id || '') !== id) return;
      if (!document.getElementById('screen-game')?.classList.contains('active')) return;
      openResultSheet(game, me);
    }, presentationDelay);
    return;
  }
  window.requestAnimationFrame(() => openResultSheet(game, me));
}

function fourTerminalPresentationDelay(game){
  if (gameTypeOf(game) !== 'four_in_a_row') return 0;
  const surface = document.getElementById('gameBoard');
  const activeFx = String(surface?.dataset?.fourActiveFx || '');
  const delay = {
    drop: 930,
    pulse: 1180,
    victory: 4200,
  }[activeFx] || 0;
  if (delay <= 0) return 0;

  const reduceMotion = typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  return reduceMotion ? Math.min(delay, 240) : delay;
}

function requestLeaveGame(){
  const game = state.activeGame;
  if (!game || String(game.status || '') !== 'active') {
    state.timers.game = clearTimer(state.timers.game);
    state.activeGame = null;
    clearGameView();
    showScreen('home');
    return;
  }

  openSheet(`
    <div class="sheet-head">
      <div><h2>${escapeHtml(gameText('leave.title'))}</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">${escapeHtml(gameText('leave.note'))}</div>
    <div class="stack">
      <button class="btn primary full" data-close-sheet type="button">${escapeHtml(gameText('leave.continue'))}</button>
      <button class="btn danger full" id="confirmLeaveGame" type="button">${escapeHtml(gameText('leave.confirm'))}</button>
    </div>
  `);

  if (!window.__MGW_V110_MATCH_LIFECYCLE__?.initialized) {
    document.getElementById('confirmLeaveGame')?.addEventListener('click', confirmLeaveGame);
  }
}

async function confirmLeaveGame(){
  const game = state.activeGame;
  if (!game?.id) {
    closeSheet();
    showScreen('home');
    return;
  }

  const id = String(game.id);
  const item = gameRuntime(id);
  if (item.surrenderPending) return;
  const viewer = item.viewer || resolveViewer(game);
  if (!viewer?.id) {
    toast(gameText('errors.viewer'));
    return;
  }

  haptic('medium');
  item.surrenderPending = true;
  item.generation++;
  state.timers.game = clearTimer(state.timers.game);

  const optimistic = buildOptimisticSurrender(game, viewer.id);
  item.optimistic = optimistic;
  state.activeGame = optimistic;
  renderGame(optimistic, viewer, true);
  runtime.resultOpened.add(id);
  openResultSheet(optimistic, viewer, { pending:true, notify:false });

  try {
    const result = await api.leaveGame(id);
    rememberUserAndSession(result);
    const authoritative = result?.game || optimistic;
    const confirmedViewer = normalizeViewer(result?.me) || viewer;
    item.viewer = confirmedViewer;
    item.authoritative = clone(authoritative);
    item.optimistic = clone(authoritative);
    item.surrenderPending = false;
    state.activeGame = authoritative;
    renderGame(authoritative, confirmedViewer, false);
    notifyWeeklyProgress(authoritative);
    setResultActionsDisabled(false);
    void hydrateResultSummary(authoritative, confirmedViewer);
  } catch (error) {
    item.surrenderPending = false;
    runtime.resultOpened.delete(id);
    closeSheet();
    restoreAuthoritative(item);
    startGamePolling(id);
    toast(error?.message || gameText('errors.leave'));
  }
}

function buildOptimisticSurrender(game, viewerId){
  const next = clone(game);
  const players = Array.isArray(next?.players) ? next.players : [];
  const winner = players.find(player => String(player?.id || '') !== String(viewerId || ''));
  next.status = 'finished';
  next.winner_id = String(winner?.id || '');
  next.loser_id = String(viewerId || '');
  next.finish_reason = 'player_left';
  next.time_left = 0;
  return next;
}

function openResultSheet(game, me, options = {}){
  if (options.notify !== false) notifyWeeklyProgress(game);
  const tournamentMatch = String(game?.match_source || '') === 'tournament';
  let title = gameText('result.draw_title');
  let text = chessDrawText(game) || gameText('result.draw_text');

  if (game.finish_reason === 'preparation_timeout') {
    title = gameText('result.not_started_title');
    text = gameText('result.not_started_text');
  } else if (game.winner_id) {
    const isWin = String(game.winner_id) === String(me.id);
    title = isWin ? gameText('result.win_title') : gameText('result.loss_title');
    if (game.finish_reason === 'timeout') {
      text = isWin
        ? gameText('result.timeout_win')
        : gameText('result.timeout_loss');
    } else if (game.finish_reason === 'player_left') {
      text = isWin
        ? gameText('result.left_win')
        : gameText('result.left_loss');
    } else if (gameTypeOf(game) === 'chess' && game.chess_end_reason === 'checkmate') {
      text = isWin ? gameText('result.checkmate_win') : gameText('result.checkmate_loss');
    } else if (gameTypeOf(game) === 'domino' && game.end_reason === 'empty_hand') {
      text = isWin
        ? gameText('result.domino_empty_win')
        : gameText('result.domino_empty_loss');
    } else {
      text = isWin ? gameText('result.normal_win') : gameText('result.normal_loss');
    }
  }

  text += reversiScoreText(game, me);
  text += goScoreText(game, me);
  text += dominoScoreText(game);
  const disabled = options.pending ? 'disabled aria-busy="true"' : '';
  const summaryMarkup = tournamentMatch
    ? tournamentResultSummaryMarkup(game)
    : resultSummaryPlaceholder(
        game,
        me,
        options.pending ? gameText('result.pending_confirming') : gameText('result.pending_counting')
      );

  if (tournamentMatch) text += ` ${gameText('result.tournament_saved')}`;

  openSheet(`
    <div class="sheet-head">
      <div><h2>${title}</h2><p>${text}</p></div>
      <button class="close" data-close-sheet type="button" ${disabled}>×</button>
    </div>
    <div class="small-note" id="resultSummary" data-result-game-id="${escapeHtml(game?.id || '')}">${summaryMarkup}</div>
    <div class="stack">
      ${tournamentMatch
        ? `<button class="btn primary full" id="goTournament" type="button" ${disabled}>${escapeHtml(gameText('result.go_tournament'))}</button>`
        : `<button class="btn primary full" id="newOpponent" type="button" ${disabled}>${escapeHtml(gameText('result.new_opponent'))}</button>
           <button class="btn ghost full" id="goHome" type="button" ${disabled}>${escapeHtml(gameText('result.home'))}</button>`}
    </div>
  `);

  document.getElementById('goTournament')?.addEventListener('click', () => {
    runtime.tournamentResultDismissed.add(String(game?.id || ''));
    closeSheet();
    state.activeGame = null;
    clearGameView();
    showScreen('tournaments');
    document.dispatchEvent(new CustomEvent('mgw:tournament-progression-open'));
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  });
  document.getElementById('newOpponent')?.addEventListener('click', () => {
    const detail = searchContextFromGame(game);
    closeSheet();
    document.dispatchEvent(new CustomEvent('mgw:v99-search-request', { detail }));
  });
  document.getElementById('goHome')?.addEventListener('click', () => {
    closeSheet();
    state.activeGame = null;
    clearGameView();
    showScreen('home');
    document.dispatchEvent(new CustomEvent('mgw:game-dismissed'));
  });

  if (!options.pending && !tournamentMatch) void hydrateResultSummary(game, me);
}

function setResultActionsDisabled(disabled){
  for (const selector of ['#sheet [data-close-sheet]', '#newOpponent', '#goHome', '#goTournament']) {
    const button = document.querySelector(selector);
    if (!(button instanceof HTMLButtonElement)) continue;
    button.disabled = Boolean(disabled);
    if (disabled) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
  }
}

async function hydrateResultSummary(game, me){
  if (String(game?.match_source || '') === 'tournament') return;
  const gameId = String(game?.id || '');
  if (!gameId) return;
  const target = document.getElementById('resultSummary');
  if (!(target instanceof HTMLElement) || String(target.dataset.resultGameId || '') !== gameId) return;

  try {
    const result = await api.history();
    rememberUserAndSession(result);
    const matches = Array.isArray(result?.history?.matches) ? result.history.matches : [];
    const match = matches.find(item => String(item?.id || '') === gameId);
    const current = document.getElementById('resultSummary');
    if (!(current instanceof HTMLElement) || String(current.dataset.resultGameId || '') !== gameId) return;
    current.innerHTML = match
      ? resultSummaryMarkup(match)
      : resultSummaryPlaceholder(game, me, gameText('result.unavailable'));
  } catch (error) {
    const current = document.getElementById('resultSummary');
    if (!(current instanceof HTMLElement) || String(current.dataset.resultGameId || '') !== gameId) return;
    current.innerHTML = resultSummaryPlaceholder(game, me, gameText('result.unavailable'));
  }
}

function tournamentResultSummaryMarkup(game){
  const title = localizedGameTitle(game);
  const names = (Array.isArray(game?.players) ? game.players : [])
    .map(player => String(player?.name || '').trim())
    .filter(Boolean);
  const pairing = names.length >= 2
    ? gameText('result.tournament_pair', { first:names[0], second:names[1] })
    : gameText('result.tournament_participants');
  return `<strong>${escapeHtml(gameText('result.tournament_duel', { title }))}</strong><br>${escapeHtml(pairing)}`;
}

function resultSummaryMarkup(match){
  const context = resultContextFromMatch(match);
  const economy = match?.economy && typeof match.economy === 'object' ? match.economy : null;
  if (!economy) return `<strong>${escapeHtml(context)}</strong><br>${escapeHtml(gameText('result.summary_unavailable'))}`;

  const delta = formatCoinDelta(economy.ledger_delta);
  const balance = economy.new_balance === null || economy.new_balance === undefined
    ? '—'
    : formatCoins(economy.new_balance);
  return `<strong>${escapeHtml(context)}</strong><br>${escapeHtml(gameText('result.summary', { delta, balance }))}`;
}

function resultSummaryPlaceholder(game, me, status){
  const context = resultContextFromGame(game, me);
  return `<strong>${escapeHtml(context)}</strong><br>${escapeHtml(gameText('result.summary_status', { status }))}`;
}

function resultContextFromMatch(match){
  const title = localizedGameTitle(match);
  const opponent = String(match?.opponent || gameText('result.opponent_fallback'));
  return [title, gameText('result.versus', { opponent })].filter(Boolean).join(' · ');
}

function resultContextFromGame(game, me){
  const title = localizedGameTitle(game);
  const opponent = (Array.isArray(game?.players) ? game.players : [])
    .find(player => String(player?.id || '') !== String(me?.id || ''));
  const opponentName = String(opponent?.name || gameText('result.opponent_fallback'));
  return [title, gameText('result.versus', { opponent:opponentName })].filter(Boolean).join(' · ');
}

function formatCoins(value){
  const number = Number(value);
  return Number.isFinite(number) ? gameText('result.coins', { value:formatLocalizedNumber(Math.trunc(number), { maximumFractionDigits:0 }) }) : '—';
}

function formatCoinDelta(value){
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  const normalized = Math.trunc(number);
  return gameText('result.coins', { value:`${normalized > 0 ? '+' : ''}${formatLocalizedNumber(normalized, { maximumFractionDigits:0 })}` });
}

function localizedGameTitle(value){
  const type = String(gameTypeOf(value) || value?.game_type || '').trim();
  if (GAME_TYPES.has(type)) return t(`games.${type}.name`);
  const supplied = String(value?.game_title || '').trim();
  return supplied || gameText('result.match_fallback');
}

function searchContextFromGame(game){
  const type = gameTypeOf(game);
  return {
    gameType:type,
    room:String(game?.room || state.room || 'match') === 'gold' ? 'gold' : 'match',
    bet:Number(game?.bet || state.selectedBet || APP_CONFIG.matchBet),
    size:Number(game?.board_size || state.selectedBoardSize || 3),
  };
}

function restoreAuthoritative(item){
  if (!item.authoritative || !item.viewer) return;
  item.optimistic = clone(item.authoritative);
  state.activeGame = item.authoritative;
  item.generation++;
  renderGame(item.authoritative, item.viewer, true);
}

function rememberUserAndSession(result){
  if (result?.user) {
    state.user = result.user;
    renderBalances(state.user);
  }
  if (result?.session) state.session = result.session;
}

function gameRuntime(gameId){
  const key = String(gameId || '');
  if (!runtime.games.has(key)) {
    runtime.games.set(key, {
      viewer:null,
      authoritative:null,
      optimistic:null,
      queue:[],
      running:false,
      pollBusy:false,
      surrenderPending:false,
      generation:0,
      interactionGeneration:0,
    });
  }
  return runtime.games.get(key);
}

function resolveViewer(game){
  const players = Array.isArray(game?.players) ? game.players : [];
  const explicit = players.find(player => player?.is_me === true || player?.viewer === true);
  if (explicit?.id !== undefined) return normalizeViewer(explicit);

  const candidates = [state.user?.id, state.user?.mgw_id, state.user?.telegram_id]
    .map(value => String(value || ''))
    .filter(Boolean);
  for (const candidate of candidates) {
    const found = players.find(player => String(player?.id || '') === candidate);
    if (found) return normalizeViewer(found);
  }

  const side = String(game?.viewer_side || '');
  const matches = side ? players.filter(player => String(player?.side || '') === side) : [];
  return matches.length === 1 ? normalizeViewer(matches[0]) : null;
}

function normalizeViewer(viewer){
  const id = String(viewer?.id || '');
  return id ? { ...viewer, id } : null;
}

function chessDrawText(game){
  if (gameTypeOf(game) !== 'chess') return '';
  return {
    stalemate:gameText('result.chess_draw.stalemate'),
    insufficient_material:gameText('result.chess_draw.insufficient_material'),
    threefold_repetition:gameText('result.chess_draw.threefold_repetition'),
    fifty_move:gameText('result.chess_draw.fifty_move'),
  }[String(game?.chess_end_reason || '')] || gameText('result.chess_draw.fallback');
}

function reversiScoreText(game, me){
  if (gameTypeOf(game) !== 'reversi') return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
  const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
  return ` ${gameText('result.final_score', { mine:side === 'black' ? black : white, theirs:side === 'black' ? white : black })}`;
}

function goScoreText(game, me){
  if (gameTypeOf(game) !== 'go' || !game?.final_score) return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = formatScore(game.final_score.black_total);
  const white = formatScore(game.final_score.white_total);
  return ` ${gameText('result.final_score', { mine:side === 'black' ? black : white, theirs:side === 'black' ? white : black })}`;
}

function dominoScoreText(game){
  if (gameTypeOf(game) !== 'domino' || game?.my_points === null || game?.my_points === undefined) return '';
  const mine = Number(game.my_points || 0);
  const theirs = Number(game.opponent_points || 0);
  return game?.end_reason === 'blocked'
    ? ` ${gameText('result.domino_blocked_score', { mine, theirs })}`
    : ` ${gameText('result.domino_score', { mine, theirs })}`;
}

function formatScore(value){
  const number = Number(value || 0);
  return formatLocalizedNumber(number, Number.isInteger(number) ? { maximumFractionDigits:0 } : { minimumFractionDigits:1, maximumFractionDigits:1 });
}

function notifyWeeklyProgress(game){
  const id = String(game?.id || '');
  if (!id || runtime.weeklyNotified.has(id)) return;
  runtime.weeklyNotified.add(id);
  document.dispatchEvent(new CustomEvent('mgw:game-finished', { detail:{ gameId:id } }));
}

function escapeHtml(value){
  return String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;',
  }[char]));
}

function clone(value){
  if (value === undefined) return undefined;
  if (typeof structuredClone === 'function') return structuredClone(value);
  return JSON.parse(JSON.stringify(value));
}
