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

const runtime = window.__MGW_V100_GAME_RUNTIME__ ||= {
  initialized:false,
  games:new Map(),
  pointerHoldUntil:0,
  resultOpened:new Set(),
  weeklyNotified:new Set(),
};

export function initGameScreen(){
  if (runtime.initialized) return;
  runtime.initialized = true;
  document.getElementById('leaveGame')?.addEventListener('click', requestLeaveGame);

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
  if (turn) turn.textContent = 'Ожидаем начало матча';
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
    const message = String(error?.message || '');
    if (message) toast(message);
  } finally {
    item.pollBusy = false;
  }
}

function submitAction(gameId, action){
  const id = String(gameId || '');
  const item = gameRuntime(id);
  const base = item.optimistic || state.activeGame;
  if (!base || String(base.id || '') !== id || String(base.status || '') !== 'active' || item.surrenderPending) return;

  const viewer = item.viewer || resolveViewer(base);
  if (!viewer?.id) return;
  item.viewer = viewer;

  haptic('light');
  item.generation++;

  const type = gameTypeOf(base);
  const optimistic = buildV100OptimisticGame(base, action, viewer.id, type);
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
  drainActions(id, item);
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
        item.queue.length = 0;
        restoreAuthoritative(item);
        toast(error?.message || 'Не удалось выполнить действие. Поле восстановлено.');
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
  timer.textContent = String(game.status || '') === 'active' ? `${game.time_left ?? 60} сек` : '—';

  const playersMarkup = (game.players || []).map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div>
    </div>
  `).join('');
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
  window.requestAnimationFrame(() => window.setTimeout(() => openResultSheet(game, me), 80));
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
      <div><h2>Выйти из матча?</h2></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="small-note">Матч ещё не завершён. Если выйти сейчас, вам будет засчитано техническое поражение.</div>
    <div class="stack">
      <button class="btn primary full" data-close-sheet type="button">Продолжить игру</button>
      <button class="btn danger full" id="confirmLeaveGame" type="button">Выйти и завершить матч</button>
    </div>
  `);
  document.getElementById('confirmLeaveGame')?.addEventListener('click', confirmLeaveGame);
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
    toast('Не удалось определить игрока для завершения матча.');
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
  } catch (error) {
    item.surrenderPending = false;
    runtime.resultOpened.delete(id);
    closeSheet();
    restoreAuthoritative(item);
    startGamePolling(id);
    toast(error?.message || 'Не удалось выйти из матча.');
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
  let title = 'Ничья';
  let text = chessDrawText(game) || 'Коины возвращены на баланс.';

  if (game.winner_id) {
    const isWin = String(game.winner_id) === String(me.id);
    title = isWin ? 'Победа!' : 'Поражение';
    if (game.finish_reason === 'timeout') {
      text = isWin
        ? `Соперник не сделал ход вовремя. Вы получили ${game.payout ?? 0} коинов.`
        : 'Время хода вышло. Засчитано техническое поражение.';
    } else if (game.finish_reason === 'player_left') {
      text = isWin
        ? `Соперник вышел из матча. Вы получили ${game.payout ?? 0} коинов.`
        : 'Вы вышли из матча. Засчитано техническое поражение.';
    } else if (gameTypeOf(game) === 'chess' && game.chess_end_reason === 'checkmate') {
      text = isWin ? `Мат. Вы получили ${game.payout ?? 0} коинов.` : 'Вашему королю поставлен мат.';
    } else if (gameTypeOf(game) === 'domino' && game.end_reason === 'empty_hand') {
      text = isWin
        ? `Вы первыми избавились от всех костяшек и получили ${game.payout ?? 0} коинов.`
        : 'Соперник первым избавился от всех костяшек.';
    } else {
      text = isWin ? `Вы получили ${game.payout ?? 0} коинов.` : 'Соперник оказался сильнее.';
    }
  }

  text += reversiScoreText(game, me);
  text += goScoreText(game, me);
  text += dominoScoreText(game);
  const disabled = options.pending ? 'disabled aria-busy="true"' : '';

  openSheet(`
    <div class="sheet-head">
      <div><h2>${title}</h2><p>${text}</p></div>
      <button class="close" data-close-sheet type="button" ${disabled}>×</button>
    </div>
    <div class="stack">
      <button class="btn primary full" id="newOpponent" type="button" ${disabled}>Найти нового соперника</button>
      <button class="btn ghost full" id="goHome" type="button" ${disabled}>В меню</button>
    </div>
  `);

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
}

function setResultActionsDisabled(disabled){
  for (const selector of ['#sheet [data-close-sheet]', '#newOpponent', '#goHome']) {
    const button = document.querySelector(selector);
    if (!(button instanceof HTMLButtonElement)) continue;
    button.disabled = Boolean(disabled);
    if (disabled) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
  }
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
  const label = {
    stalemate:'Пат.',
    insufficient_material:'Недостаточно фигур для мата.',
    threefold_repetition:'Позиция повторилась три раза.',
    fifty_move:'Сработало правило 50 ходов.',
  }[String(game?.chess_end_reason || '')] || 'Партия завершилась вничью.';
  return `${label} Коины возвращены на баланс.`;
}

function reversiScoreText(game, me){
  if (gameTypeOf(game) !== 'reversi') return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
  const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
  return ` Итоговый счёт: ${side === 'black' ? black : white}:${side === 'black' ? white : black}.`;
}

function goScoreText(game, me){
  if (gameTypeOf(game) !== 'go' || !game?.final_score) return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = formatScore(game.final_score.black_total);
  const white = formatScore(game.final_score.white_total);
  return ` Итоговый счёт: ${side === 'black' ? black : white}:${side === 'black' ? white : black}.`;
}

function dominoScoreText(game){
  if (gameTypeOf(game) !== 'domino' || game?.my_points === null || game?.my_points === undefined) return '';
  const mine = Number(game.my_points || 0);
  const theirs = Number(game.opponent_points || 0);
  return game?.end_reason === 'blocked'
    ? ` Партия заблокирована. Оставшиеся точки: ${mine}:${theirs}.`
    : ` Оставшиеся точки: ${mine}:${theirs}.`;
}

function formatScore(value){
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(1).replace('.', ',');
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
