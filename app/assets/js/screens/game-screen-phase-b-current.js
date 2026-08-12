import { state } from '../state.js?v=27';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=41';
import { openSheet, closeSheet } from '../components/sheet.js?v=68';
import { showScreen } from '../router.js?v=27';
import { clearTimer, renderBalances } from '../ui.js?v=27';
import { APP_CONFIG } from '../config.js?v=38';
import { haptic } from '../telegram/telegram-app.js?v=27';
import {
  gameMetaText,
  gameStatusText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface,
} from '../games/game-router.js?v=74';

const gameScreenRuntime = window.__MGW_GAME_SCREEN_RUNTIME__ ||= {
  initialized:false,
  weeklyProgressNotifiedGames:new Set(),
  resultSheetScheduledGames:new Set(),
  resultSheetOpenedGames:new Set(),
  viewerByGame:new Map(),
  pollBusy:false,
  actionBusy:false,
};
const { weeklyProgressNotifiedGames, resultSheetScheduledGames, resultSheetOpenedGames } = gameScreenRuntime;
gameScreenRuntime.viewerByGame ||= new Map();
gameScreenRuntime.pollBusy = Boolean(gameScreenRuntime.pollBusy);
gameScreenRuntime.actionBusy = Boolean(gameScreenRuntime.actionBusy);

export function initGameScreen(){
  if (gameScreenRuntime.initialized) return;
  gameScreenRuntime.initialized = true;
  document.getElementById('leaveGame')?.addEventListener('click', requestLeaveGame);
}

export function startGamePolling(gameId){
  const id = String(gameId || state.activeGame?.id || '');
  if (!id) return;

  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);

  const local = state.activeGame;
  if (String(local?.id || '') === id) {
    primePhaseBLaunch(local);
    projectGame(local, resolveViewer(local), { allowOlder:false });
  }

  state.timers.game = setInterval(() => refreshGame(id), APP_CONFIG.gameIntervalMs);
  void refreshGame(id);
}

export function applyReadonlyGameProjection(game, me = null){
  const id = String(game?.id || '');
  if (!id || gameScreenRuntime.actionBusy || localOptimisticActionVisible()) return false;
  if (String(state.activeGame?.id || '') !== id) return false;
  return projectGame(game, normalizeViewer(me) || resolveViewer(game), { allowOlder:false });
}

async function refreshGame(gameId){
  if (gameScreenRuntime.pollBusy || gameScreenRuntime.actionBusy) return;
  if (document.visibilityState !== 'visible') return;

  gameScreenRuntime.pollBusy = true;
  try {
    const result = await api.gameState(gameId);
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (!result.game) {
      state.timers.game = clearTimer(state.timers.game);
      state.activeGame = null;
      showScreen('home');
      return;
    }

    projectGame(result.game, normalizeViewer(result.me) || resolveViewer(result.game), { allowOlder:false });
  } catch (error) {
    toast(error.message);
  } finally {
    gameScreenRuntime.pollBusy = false;
  }
}

function projectGame(game, me, { allowOlder = false } = {}){
  const id = String(game?.id || '');
  if (!id) return false;

  const current = state.activeGame;
  if (!allowOlder && projectionIsOlder(game, current)) return false;

  const viewer = normalizeViewer(me) || gameScreenRuntime.viewerByGame.get(id) || resolveViewer(game);
  if (viewer?.id) gameScreenRuntime.viewerByGame.set(id, viewer);

  state.activeGame = game;
  state.selectedGame = gameTypeOf(game);
  if (viewer?.id) renderGame(game, viewer);

  document.dispatchEvent(new CustomEvent('mgw:game-projected', {
    detail:{
      game,
      me:viewer,
      authoritative:game?.clock_pending_authority !== true,
    },
  }));

  if (
    String(game?.turn_clock_phase || '') === 'syncing'
    && !gameScreenRuntime.actionBusy
    && !gameScreenRuntime.pollBusy
  ) {
    queueMicrotask(() => void refreshGame(id));
  }

  if (String(game.status || '') === 'finished') {
    state.timers.game = clearTimer(state.timers.game);
    if (viewer?.id) scheduleResultSheet(game, viewer);
  }

  return true;
}

function projectionIsOlder(incoming, current){
  if (!current || String(current?.id || '') !== String(incoming?.id || '')) return false;
  if (String(current?.status || '') === 'finished' && String(incoming?.status || '') !== 'finished') return true;

  const incomingRevision = Number(incoming?.turn_revision ?? incoming?.clock_revision ?? 0);
  const currentRevision = Number(current?.turn_revision ?? current?.clock_revision ?? 0);
  if (Number.isFinite(incomingRevision) && Number.isFinite(currentRevision) && incomingRevision < currentRevision) return true;

  const incomingUpdated = Date.parse(String(incoming?.updated_at || ''));
  const currentUpdated = Date.parse(String(current?.updated_at || ''));
  return Number.isFinite(incomingUpdated) && Number.isFinite(currentUpdated) && incomingUpdated < currentUpdated;
}

function primePhaseBLaunch(game){
  const phase = String(game?.launch_phase || '');
  if (String(game?.status || '') !== 'active' || !['preparing','countdown'].includes(phase)) return;
  document.dispatchEvent(new CustomEvent('mgw:phase-b-game-entering', { detail:{ game } }));
}

function renderGame(game, me){
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');
  const screen = document.getElementById('screen-game');

  if (!meta || !turn || !timer || !players || !surface || !me?.id) return;

  const gameType = gameTypeOf(game);
  if (screen) {
    screen.dataset.gameType = gameType;
    screen.dataset.gamePhase = String(game?.phase || '');
  }

  meta.textContent = gameMetaText(game);
  turn.textContent = gameStatusText(game, me);
  if (game.status !== 'active') timer.textContent = '—';

  players.innerHTML = (Array.isArray(game.players) ? game.players : []).map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark"><span class="player-mark-symbol">${escapeHtml(playerMarkText(game, player))}</span><span class="player-mark-role"> · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</span></div>
    </div>
  `).join('');

  renderGameSurface({
    game,
    me,
    container: surface,
    onAction: gameAction => applyGameAction(game.id, gameAction),
  });
}

async function applyGameAction(gameId, gameAction){
  if (gameScreenRuntime.actionBusy) return;

  const previousGame = state.activeGame;
  const viewer = gameScreenRuntime.viewerByGame.get(String(gameId || '')) || resolveViewer(previousGame);
  const optimistic = createTicTacToeOptimisticProjection(previousGame, viewer, gameAction);

  gameScreenRuntime.actionBusy = true;
  if (optimistic) {
    projectGame(optimistic.game, viewer, { allowOlder:true });
    const optimisticCell = document.querySelector(
      `#gameBoard[data-game-type="tictactoe"] [data-game-cell="${optimistic.cell}"]`
    );
    optimisticCell?.classList.add('is-optimistic');
  }

  try {
    haptic('light');
    const result = await api.gameAction(gameId, gameAction);
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game) {
      projectGame(result.game, normalizeViewer(result.me) || viewer || resolveViewer(result.game), { allowOlder:false });
    }
  } catch (error) {
    if (optimistic && previousGame?.id) {
      projectGame(previousGame, viewer || resolveViewer(previousGame), { allowOlder:true });
    }
    document.getElementById('gameBoard')?.classList.remove('is-submitting');
    toast(error.message);
  } finally {
    gameScreenRuntime.actionBusy = false;
    if (String(state.activeGame?.turn_clock_phase || '') === 'syncing') {
      void refreshGame(gameId);
    }
  }
}

function createTicTacToeOptimisticProjection(game, me, gameAction){
  if (gameTypeOf(game) !== 'tictactoe' || String(game?.status || '') !== 'active') return null;
  if (String(gameAction?.type || '') !== 'cell') return null;

  const viewerId = String(me?.id || '');
  if (!viewerId || String(game?.turn || '') !== viewerId) return null;

  const cell = Number(gameAction?.cell);
  const board = String(game?.board || '');
  if (!Number.isInteger(cell) || cell < 0 || cell >= board.length || board[cell] !== '-') return null;

  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === viewerId);
  const nextPlayer = players.find(player => String(player?.id || '') !== viewerId);
  const symbol = String(viewer?.symbol || '');
  const nextPlayerId = String(nextPlayer?.id || '');
  if (!['X','O'].includes(symbol) || !nextPlayerId) return null;

  return {
    cell,
    game:{
      ...game,
      board:`${board.slice(0, cell)}${symbol}${board.slice(cell + 1)}`,
      turn:nextPlayerId,
      time_left:Number(game?.move_timeout_sec || 60),
      clock_pending_authority:true,
    },
  };
}

function requestLeaveGame(){
  const game = state.activeGame;
  if (!game || game.status !== 'active') {
    state.timers.game = clearTimer(state.timers.game);
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
  if (!game) {
    closeSheet();
    showScreen('home');
    return;
  }
  if (gameScreenRuntime.actionBusy) return;

  gameScreenRuntime.actionBusy = true;
  try {
    haptic('medium');
    const result = await api.leaveGame(game.id);
    closeSheet();
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game) {
      projectGame(result.game, normalizeViewer(result.me) || resolveViewer(result.game), { allowOlder:false });
      return;
    }
    state.timers.game = clearTimer(state.timers.game);
    showScreen('home');
  } catch (error) {
    toast(error.message);
  } finally {
    gameScreenRuntime.actionBusy = false;
  }
}

async function startSameSearchFromResult(){
  const lastGame = state.activeGame;
  if (!lastGame) {
    closeSheet();
    showScreen('home');
    return;
  }

  const room = lastGame.room || state.room || 'match';
  const boardSize = Number(lastGame.board_size || state.selectedBoardSize || 3);
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(lastGame.bet || state.selectedBet || APP_CONFIG.matchBet);
  const gameType = gameTypeOf(lastGame);

  state.room = room;
  if (gameType === 'tictactoe') state.selectedBoardSize = boardSize;
  else if (gameType === 'four_in_a_row') state.selectedFourBoardSize = boardSize;
  else if (gameType === 'reversi') state.selectedReversiBoardSize = boardSize;
  else if (gameType === 'go') state.selectedGoBoardSize = boardSize;
  state.selectedBet = bet;
  state.activeGame = null;
  closeSheet();

  try {
    const result = await api.startSearch(room, bet, boardSize, gameType);
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game) {
      state.activeGame = result.game;
      state.selectedGame = gameTypeOf(result.game);
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    showScreen('search');
    startResultSearchPolling();
  } catch (error) {
    toast(error.message);
    showScreen('home');
  }
}

function startResultSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  state.timers.search = setInterval(checkResultSearch, APP_CONFIG.searchIntervalMs);
  void checkResultSearch();
}

async function checkResultSearch(){
  try {
    const result = await api.gameState();
    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }
    if (result.game && result.game.status === 'active') {
      state.activeGame = result.game;
      state.selectedGame = gameTypeOf(result.game);
      state.timers.search = clearTimer(state.timers.search);
      showScreen('game');
      startGamePolling(result.game.id);
      return;
    }
    if (!result.game && result.user && result.user.status !== 'searching') {
      state.timers.search = clearTimer(state.timers.search);
      showScreen('home');
      toast('Поиск остановлен. Соперник не найден или связь прервалась.');
    }
  } catch (error) {
    toast(error.message);
  }
}

function scheduleResultSheet(game, me){
  const gameId = String(game?.id || '');
  if (!gameId || resultSheetScheduledGames.has(gameId) || resultSheetOpenedGames.has(gameId)) return;
  resultSheetScheduledGames.add(gameId);

  const gameType = gameTypeOf(game);
  const flippedCount = Array.isArray(game?.last_flipped_cells) ? game.last_flipped_cells.length : 0;
  const capturedCount = Array.isArray(game?.last_captured_cells) ? game.last_captured_cells.length : 0;
  const delay = gameType === 'reversi'
    ? Math.min(4200, 650 + flippedCount * 150)
    : (gameType === 'go'
      ? Math.min(2600, 1450 + capturedCount * 35)
      : (gameType === 'domino' ? 1100 : 0));

  if (delay <= 0) {
    openResultSheet(game, me);
    return;
  }
  window.setTimeout(() => openResultSheet(game, me), delay);
}

function openResultSheet(game, me){
  const gameId = String(game?.id || '');
  if (!gameId || resultSheetOpenedGames.has(gameId)) return;
  resultSheetOpenedGames.add(gameId);
  resultSheetScheduledGames.add(gameId);
  notifyWeeklyProgress(game);

  let title = 'Ничья';
  let text = chessDrawText(game) || 'Коины возвращены на баланс.';

  if (game.finish_reason === 'preparation_timeout') {
    title = 'Матч не начался';
    text = 'Соперник не подключился вовремя. Ставка возвращена на баланс.';
  } else if (game.winner_id) {
    const isWin = String(game.winner_id) === String(me?.id || state.user?.id || '');
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

  openSheet(`
    <div class="sheet-head">
      <div><h2>${title}</h2><p>${text}</p></div>
      <button class="close" data-close-sheet type="button">×</button>
    </div>
    <div class="stack">
      <button class="btn primary full" id="newOpponent" type="button">Найти нового соперника</button>
      <button class="btn ghost full" id="goHome" type="button">В меню</button>
    </div>
  `);

  document.getElementById('newOpponent')?.addEventListener('click', startSameSearchFromResult);
  document.getElementById('goHome')?.addEventListener('click', () => {
    closeSheet();
    state.activeGame = null;
    showScreen('home');
  });
}

function chessDrawText(game){
  if (gameTypeOf(game) !== 'chess') return '';
  const reason = String(game?.chess_end_reason || '');
  const label = {
    stalemate: 'Пат.',
    insufficient_material: 'Недостаточно фигур для мата.',
    threefold_repetition: 'Позиция повторилась три раза.',
    fifty_move: 'Сработало правило 50 ходов.',
  }[reason] || 'Партия завершилась вничью.';
  return `${label} Коины возвращены на баланс.`;
}

function reversiScoreText(game, me){
  if (gameTypeOf(game) !== 'reversi') return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || state.user?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = Number(game?.final_counts?.black ?? game?.black_count ?? 0);
  const white = Number(game?.final_counts?.white ?? game?.white_count ?? 0);
  const mine = side === 'black' ? black : white;
  const theirs = side === 'black' ? white : black;
  return ` Итоговый счёт: ${mine}:${theirs}.`;
}

function goScoreText(game, me){
  if (gameTypeOf(game) !== 'go' || !game?.final_score) return '';
  const player = (game?.players || []).find(item => String(item?.id || '') === String(me?.id || state.user?.id || ''));
  const side = String(player?.side || game?.viewer_side || 'black');
  const black = formatScore(game.final_score.black_total);
  const white = formatScore(game.final_score.white_total);
  const mine = side === 'black' ? black : white;
  const theirs = side === 'black' ? white : black;
  return ` Итоговый счёт: ${mine}:${theirs}.`;
}

function dominoScoreText(game){
  if (gameTypeOf(game) !== 'domino' || game?.my_points === null || game?.my_points === undefined) return '';
  const mine = Number(game.my_points || 0);
  const theirs = Number(game.opponent_points || 0);
  if (game?.end_reason === 'blocked') return ` Партия заблокирована. Оставшиеся точки: ${mine}:${theirs}.`;
  return ` Оставшиеся точки: ${mine}:${theirs}.`;
}

function formatScore(value){
  const number = Number(value || 0);
  return Number.isInteger(number) ? String(number) : number.toFixed(1).replace('.', ',');
}

function notifyWeeklyProgress(game){
  const gameId = String(game?.id || '');
  if (!gameId || weeklyProgressNotifiedGames.has(gameId)) return;
  weeklyProgressNotifiedGames.add(gameId);
  document.dispatchEvent(new CustomEvent('mgw:game-finished', { detail: { gameId } }));
}

function normalizeViewer(me){
  const id = String(me?.id || '');
  return id ? { ...me, id } : null;
}

function resolveViewer(game){
  const id = String(state.user?.id || '');
  if (!id) return null;
  const players = Array.isArray(game?.players) ? game.players : [];
  return players.some(player => String(player?.id || '') === id) ? { id } : null;
}

function localOptimisticActionVisible(){
  return Boolean(document.querySelector('#gameBoard .is-optimistic, #gameBoard .mgw-pending-action'));
}

function escapeHtml(value){
  return String(value || '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}
