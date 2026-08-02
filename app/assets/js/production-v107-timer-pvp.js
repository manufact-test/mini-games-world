import { state } from './state.js?v=27';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const CLOCK_URL = `${window.location.origin}/bot/game-clock.php`;
const TICK_MS = 150;
const runtime = window.__MGW_V107_TTT__ ||= {
  initialized:false,
  timerId:null,
  key:'',
  anchorLeft:60,
  anchorAt:0,
  lastDrawn:null,
  clockRequests:new Map(),
  botPending:null,
};

export function initV107TicTacToeStability(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  installStableTimerSurface();
  runtime.timerId = window.setInterval(tick, TICK_MS);
  window.addEventListener('click', rememberBotMove, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') tick();
  });
  document.addEventListener('mgw:app-ready', tick, { once:true });
}

function tick(){
  const game = state.activeGame;
  const stable = installStableTimerSurface();
  const source = document.getElementById('timerText');
  const isActiveTicTacToe = Boolean(game?.id)
    && String(game?.status || '') === 'active'
    && String(game?.game_type || '') === 'tictactoe';

  if (source) source.hidden = isActiveTicTacToe;
  if (stable) stable.hidden = !isActiveTicTacToe;
  if (!isActiveTicTacToe) {
    runtime.key = '';
    runtime.anchorAt = 0;
    runtime.lastDrawn = null;
    runtime.botPending = null;
    return;
  }

  if (shouldArmFirstBotClock(game)) void armFirstBotClock(game);
  updateMonotonicTimer(game, stable);
  paintBotPending(game);
}

function installStableTimerSurface(){
  const source = document.getElementById('timerText');
  if (!source?.parentElement) return document.getElementById('timerTextV107');
  let stable = document.getElementById('timerTextV107');
  if (stable) return stable;

  stable = document.createElement(source.tagName.toLowerCase());
  stable.id = 'timerTextV107';
  stable.className = source.className;
  stable.setAttribute('aria-live', 'off');
  stable.textContent = source.textContent || '60 сек';
  stable.hidden = true;
  source.insertAdjacentElement('afterend', stable);
  return stable;
}

function updateMonotonicTimer(game, target){
  if (!target) return;
  const full = Math.max(1, Number(game.move_timeout_sec || 60));
  const serverLeft = clamp(Number(game.time_left ?? full), 0, full);
  const key = `${String(game.id || '')}|${String(game.turn || '')}`;
  const now = Date.now();

  if (runtime.key !== key || runtime.anchorAt <= 0) {
    runtime.key = key;
    runtime.anchorLeft = shouldShowFullFirstBotClock(game) ? full : serverLeft;
    runtime.anchorAt = now;
    runtime.lastDrawn = null;
  } else {
    const localLeft = Math.max(0, runtime.anchorLeft - ((now - runtime.anchorAt) / 1000));
    if (serverLeft < localLeft - 1.05) {
      runtime.anchorLeft = serverLeft;
      runtime.anchorAt = now;
    }
  }

  const remaining = Math.max(0, Math.ceil(runtime.anchorLeft - ((now - runtime.anchorAt) / 1000)));
  if (runtime.lastDrawn !== remaining) {
    target.textContent = `${remaining} сек`;
    runtime.lastDrawn = remaining;
  }
}

function shouldShowFullFirstBotClock(game){
  if (!game?.is_bot_game) return false;
  const size = Number(game.board_size || 3);
  return String(game.board || '') === '-'.repeat(Math.max(1, size * size));
}

function shouldArmFirstBotClock(game){
  if (!shouldShowFullFirstBotClock(game)) return false;
  return runtime.clockRequests.get(String(game.id || '')) === undefined;
}

async function armFirstBotClock(game){
  const id = String(game.id || '');
  if (!id) return;
  runtime.clockRequests.set(id, 'pending');

  try {
    const response = await fetch(CLOCK_URL, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        initData:getInitData(),
        sessionId:getSessionId(),
        gameId:id,
      }),
      priority:'high',
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result || result.ok === false || !result.game?.id) {
      throw new Error(result?.error || 'Не удалось синхронизировать таймер.');
    }

    runtime.clockRequests.set(id, 'done');
    if (String(state.activeGame?.id || '') === id) {
      state.activeGame = {...state.activeGame, ...result.game};
      const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
      if (item) {
        item.authoritative = {...(item.authoritative || {}), ...result.game};
        item.optimistic = {...(item.optimistic || {}), ...result.game};
      }
    }
  } catch (error) {
    runtime.clockRequests.set(id, 'failed');
  }
}

function rememberBotMove(event){
  const origin = event.target;
  if (!(origin instanceof Element)) return;
  const button = origin.closest('#gameBoard[data-game-type="tictactoe"] [data-game-cell]');
  if (!(button instanceof HTMLButtonElement)) return;

  const game = state.activeGame;
  if (!game?.is_bot_game || String(game.status || '') !== 'active') return;
  const id = String(game.id || '');
  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(id);
  const authoritative = item?.authoritative || game;
  const viewerId = String(item?.viewer?.id || state.user?.id || '');
  if (!id || !viewerId || String(authoritative.turn || '') !== viewerId) return;

  const cell = Number(button.dataset.gameCell);
  const board = String(authoritative.board || '');
  const player = Array.isArray(authoritative.players)
    ? authoritative.players.find(entry => String(entry?.id || '') === viewerId)
    : null;
  const symbol = String(player?.symbol || '');
  if (!Number.isInteger(cell) || board[cell] !== '-' || !['X','O'].includes(symbol)) return;

  runtime.botPending = {gameId:id, cell, symbol, startedAt:Date.now()};
  paintBotPending(game);
}

function paintBotPending(game){
  const pending = runtime.botPending;
  if (!pending) return;
  if (!game?.is_bot_game || String(game.id || '') !== pending.gameId || Date.now() - pending.startedAt > 4000) {
    runtime.botPending = null;
    return;
  }

  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(pending.gameId);
  const authoritativeBoard = String(item?.authoritative?.board || '');
  if (authoritativeBoard[pending.cell] === pending.symbol && !item?.running && Number(item?.queue?.length || 0) === 0) {
    runtime.botPending = null;
    return;
  }

  const cell = document.querySelector(`#gameBoard[data-game-type="tictactoe"] [data-game-cell="${pending.cell}"]`);
  if (!(cell instanceof HTMLButtonElement)) return;
  const label = pending.symbol === 'X' ? '✕' : '○';
  if (cell.textContent !== label) cell.textContent = label;
  cell.classList.toggle('x', pending.symbol === 'X');
  cell.classList.toggle('o', pending.symbol === 'O');
  cell.classList.add('locked', 'mgw-pending-action');
  cell.disabled = true;
}

function clamp(value, minimum, maximum){
  if (!Number.isFinite(value)) return maximum;
  return Math.max(minimum, Math.min(maximum, value));
}
