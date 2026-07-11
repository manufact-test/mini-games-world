import { state } from '../state.js?v=21';
import { api } from '../api/client.js?v=47';
import { toast } from '../components/toast.js?v=21';
import { openSheet, closeSheet } from '../components/sheet.js?v=21';
import { showScreen } from '../router.js?v=21';
import { clearTimer, renderBalances } from '../ui.js?v=21';
import { APP_CONFIG } from '../config.js?v=21';
import { haptic } from '../telegram/telegram-app.js?v=21';
import {
  gameMetaText,
  gameTypeOf,
  playerMarkText,
  renderGameSurface,
} from '../games/game-router.js?v=49';

const weeklyProgressNotifiedGames = new Set();

export function initGameScreen(){
  document.getElementById('leaveGame')?.addEventListener('click', requestLeaveGame);
}

export function startGamePolling(gameId){
  state.timers.search = clearTimer(state.timers.search);
  state.timers.game = clearTimer(state.timers.game);
  state.timers.game = setInterval(() => refreshGame(gameId), APP_CONFIG.gameIntervalMs);
  refreshGame(gameId);
}

async function refreshGame(gameId){
  try {
    const result = await api.gameState(gameId);
    if (result.user) { state.user = result.user; state.session = result.session || state.session; renderBalances(state.user); }
    if (!result.game) { state.timers.game = clearTimer(state.timers.game); state.activeGame = null; showScreen('home'); return; }

    state.activeGame = result.game;
    renderGame(result.game, result.me);

    if (result.game.status === 'finished') {
      state.timers.game = clearTimer(state.timers.game);
      openResultSheet(result.game, result.me);
    }
  } catch (error) {
    toast(error.message);
  }
}

function renderGame(game, me){
  const meta = document.getElementById('matchMeta');
  const turn = document.getElementById('turnText');
  const timer = document.getElementById('timerText');
  const players = document.getElementById('playersRow');
  const surface = document.getElementById('gameBoard');

  if (!meta || !turn || !timer || !players || !surface) return;

  meta.textContent = gameMetaText(game);
  turn.textContent = game.status === 'finished' ? 'Игра завершена' : (String(game.turn) === String(me.id) ? 'Ваш ход' : 'Ход соперника');
  timer.textContent = game.status === 'active' ? `${game.time_left ?? 60} сек` : '—';

  players.innerHTML = game.players.map(player => `
    <div class="game-player ${String(game.turn) === String(player.id) && game.status === 'active' ? 'active' : ''}">
      <div class="name">${escapeHtml(player.name)}</div>
      <div class="mark">${escapeHtml(playerMarkText(game, player))} · ${String(player.id) === String(me.id) ? 'вы' : 'соперник'}</div>
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
  try {
    haptic('light');
    const result = await api.gameAction(gameId, gameAction);

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
    toast(error.message);
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
  checkResultSearch();
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

function openResultSheet(game, me){
  notifyWeeklyProgress(game);

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

function notifyWeeklyProgress(game){
  const gameId = String(game?.id || '');
  if (!gameId || weeklyProgressNotifiedGames.has(gameId)) return;

  weeklyProgressNotifiedGames.add(gameId);
  document.dispatchEvent(new CustomEvent('mgw:game-finished', {
    detail: { gameId }
  }));
}

function escapeHtml(value){
  return String(value || '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}
