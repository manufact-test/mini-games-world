import { state } from '../state.js?v=29';
import { api } from '../api/client.js?v=29';
import { toast } from '../components/toast.js?v=29';
import { openSheet, closeSheet } from '../components/sheet.js?v=29';
import { showScreen } from '../router.js?v=29';
import { clearTimer, renderBalances } from '../ui.js?v=29';
import { APP_CONFIG } from '../config.js?v=29';
import { haptic } from '../telegram/telegram-app.js?v=29';

export function initGameScreen(){
  document.getElementById('leaveGame')?.addEventListener('click', requestLeaveGame);
}

export function startGamePolling(gameId){
  state.timers.search = clearTimer(state.timers.search);
  state.polling.search = false;
  state.timers.game = clearTimer(state.timers.game);
  state.polling.game = false;
  state.ignoredFinishedGameId = null;
  state.timers.game = setInterval(() => refreshGame(gameId), APP_CONFIG.gameIntervalMs);
  refreshGame(gameId);
}

async function refreshGame(gameId){
  if (state.polling.game) return;
  state.polling.game = true;

  try {
    const result = await api.gameState(gameId);
    if (result.user) { state.user = result.user; state.session = result.session || state.session; renderBalances(state.user); }
    if (!result.game) { state.timers.game = clearTimer(state.timers.game); state.activeGame = null; showScreen('home'); return; }

    if (result.game.status === 'finished' && state.ignoredFinishedGameId && String(result.game.id) === String(state.ignoredFinishedGameId)) {
      state.timers.game = clearTimer(state.timers.game);
      return;
    }

    state.activeGame = result.game;
    renderGame(result.game, result.me);

    if (result.game.status === 'finished') {
      state.timers.game = clearTimer(state.timers.game);
      openResultSheet(result.game, result.me);
    }
  } catch (error) {
    handleGameError(error);
  } finally {
    state.polling.game = false;
  }
}

function renderGame(game, me){
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const board = document.getElementById('gameBoard');

  if (!turn || !timer || !players || !board) return;

  if (meta) {
    meta.textContent = `${game.room_name} · ${game.bet} коинов · ${game.board_size}×${game.board_size}`;
  }
  turn.textContent = game.status === 'finished' ? 'Игра завершена' : (String(game.turn) === String(me.id) ? 'Ваш ход' : 'Ход соперника');
  timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';

  players.innerHTML = game.players.map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${player.symbol === 'X' ? '✕' : '○'} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div>
    </div>
  `).join('');

  board.className = `board size-${game.board_size}`;
  board.innerHTML = game.board.split('').map((cell, index) => {
    const isEmpty = cell === '-';
    const canMove = game.status === 'active' && String(game.turn) === String(me.id) && isEmpty;
    const label = cell === '-' ? '' : (cell === 'X' ? '✕' : '○');

    return `<button class="cell ${cell === 'X' ? 'x' : ''} ${cell === 'O' ? 'o' : ''} ${canMove ? '' : 'locked'}" data-cell="${index}" ${canMove ? '' : 'disabled'} type="button">${label}</button>`;
  }).join('');

  board.querySelectorAll('[data-cell]').forEach(btn => btn.addEventListener('click', () => makeMove(game.id, Number(btn.dataset.cell))));
}

async function makeMove(gameId, cell){
  if (state.locks.move) return;
  state.locks.move = true;

  try {
    haptic('light');
    const result = await api.makeMove(gameId, cell);

    if (result.user) { state.user = result.user; state.session = result.session || state.session; renderBalances(state.user); }

    if (result.game) {
      state.activeGame = result.game;
      renderGame(result.game, result.me);

      if (result.game.status === 'finished') {
        state.timers.game = clearTimer(state.timers.game);
        openResultSheet(result.game, result.me);
      }
    }
  } catch (error) {
    handleGameError(error);
  } finally {
    state.locks.move = false;
  }
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

  try {
    haptic('medium');
    const result = await api.leaveGame(game.id);

    closeSheet();

    if (result.user) { state.user = result.user; state.session = result.session || state.session; renderBalances(state.user); }

    if (result.game) {
      state.activeGame = result.game;
      renderGame(result.game, result.me);
      state.timers.game = clearTimer(state.timers.game);
      openResultSheet(result.game, result.me);
      return;
    }

    state.timers.game = clearTimer(state.timers.game);
    showScreen('home');
  } catch (error) {
    toast(error.message);
  }
}

async function startSameSearchFromResult(){
  if (state.locks.startSearch) return;
  state.locks.startSearch = true;

  const trigger = document.getElementById('newOpponent');
  if (trigger) {
    trigger.disabled = true;
    trigger.textContent = 'Ищем…';
  }

  const lastGame = state.activeGame;

  if (!lastGame) {
    state.locks.startSearch = false;
    closeSheet();
    showScreen('home');
    return;
  }

  const room = lastGame.room || state.room || 'match';
  const boardSize = Number(lastGame.board_size || state.selectedBoardSize || 3);
  const bet = room === 'match' ? APP_CONFIG.matchBet : Number(lastGame.bet || state.selectedBet || APP_CONFIG.matchBet);

  state.room = room;
  state.selectedBoardSize = boardSize;
  state.selectedBet = bet;
  state.ignoredFinishedGameId = lastGame.id || null;
  state.activeGame = null;
  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.polling.search = false;
  state.polling.game = false;

  closeSheet();

  try {
    const result = await api.startSearch(room, bet, boardSize);

    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result.game) {
      state.ignoredFinishedGameId = null;
      state.activeGame = result.game;
      showScreen('game');
      startGamePolling(result.game.id);
      state.locks.startSearch = false;
      return;
    }

    showScreen('search');
    startResultSearchPolling();
    state.locks.startSearch = false;
  } catch (error) {
    handleGameError(error);
    showScreen('home');
    state.locks.startSearch = false;
  }
}

function startResultSearchPolling(){
  state.timers.search = clearTimer(state.timers.search);
  state.polling.search = false;
  state.timers.search = setInterval(checkResultSearch, APP_CONFIG.searchIntervalMs);
  checkResultSearch();
}

async function checkResultSearch(){
  if (state.polling.search) return;
  state.polling.search = true;

  try {
    const result = await api.gameState();

    if (result.user) {
      state.user = result.user;
      state.session = result.session || state.session;
      renderBalances(state.user);
    }

    if (result.game && result.game.status === 'active') {
      state.ignoredFinishedGameId = null;
      state.activeGame = result.game;
      state.timers.search = clearTimer(state.timers.search);
      showScreen('game');
      startGamePolling(result.game.id);
      state.locks.startSearch = false;
      return;
    }

    if (!result.game && result.user && result.user.status !== 'searching') {
      state.timers.search = clearTimer(state.timers.search);
      showScreen('home');
      toast('Поиск остановлен. Соперник не найден или связь прервалась.');
    }
  } catch (error) {
    handleGameError(error);
  } finally {
    state.polling.search = false;
  }
}

function handleGameError(error){
  if (error?.status === 429 || error?.retryable) {
    const now = Date.now();
    if (!state.rateLimitNoticeAt || now - state.rateLimitNoticeAt > 12000) {
      toast('Сервер занят. Автоматически повторяем запрос.');
      state.rateLimitNoticeAt = now;
    }
    return;
  }

  toast(error.message || 'Не удалось обновить игру.');
}

function openResultSheet(game, me){
  let title = 'Ничья';
  let text = 'Коины возвращены на баланс.';

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
    } else {
      text = isWin ? `Вы получили ${game.payout ?? 0} коинов.` : 'Соперник оказался сильнее.';
    }
  }

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
  document.getElementById('goHome')?.addEventListener('click', () => { closeSheet(); state.activeGame = null; showScreen('home'); });
}

function escapeHtml(value){
  return String(value || '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}
