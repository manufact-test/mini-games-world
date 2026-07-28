import { state } from './state.js?v=27';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const CLOCK_URL = `${window.location.origin}/bot/game-clock.php`;
const TICK_MS = 200;
const runtime = window.__MGW_V106_TTT_TIMER__ ||= {
  initialized:false,
  timerId:null,
  anchorKey:'',
  anchorLeft:60,
  anchorAt:0,
  clockRequests:new Map(),
  pinFrame:null,
};

export function initV106TicTacToeTimerAndMobilePin(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  runtime.timerId = window.setInterval(updateTimerAndPin, TICK_MS);
  window.addEventListener('click', event => {
    const origin = event.target;
    if (!(origin instanceof Element) || !origin.closest('#gameBoard[data-game-type="tictactoe"] [data-game-cell]')) return;
    queueMicrotask(startPinLoop);
  }, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') updateTimerAndPin();
  });
  document.addEventListener('mgw:app-ready', updateTimerAndPin, { once:true });
}

function updateTimerAndPin(){
  const game = state.activeGame;
  if (!game?.id || String(game.status || '') !== 'active') return;

  if (shouldArmFirstBotClock(game)) void armFirstBotClock(game);
  updateTimer(game);
  paintPinnedMark();
}

function shouldArmFirstBotClock(game){
  if (!game?.is_bot_game || String(game.game_type || '') !== 'tictactoe') return false;
  const size = Number(game.board_size || 3);
  const board = String(game.board || '');
  if (!game.id || board !== '-'.repeat(Math.max(1, size * size))) return false;
  const status = runtime.clockRequests.get(String(game.id));
  return status !== 'pending' && status !== 'done';
}

async function armFirstBotClock(game){
  const id = String(game.id || '');
  if (!id) return;
  runtime.clockRequests.set(id, 'pending');

  const full = Math.max(1, Number(game.move_timeout_sec || 60));
  runtime.anchorKey = `${id}|${String(game.turn || '')}`;
  runtime.anchorLeft = full;
  runtime.anchorAt = Date.now();
  drawTimer(full);

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

function updateTimer(game){
  const full = Math.max(1, Number(game.move_timeout_sec || 60));
  const serverLeft = clamp(Number(game.time_left ?? full), 0, full);
  const key = `${String(game.id || '')}|${String(game.turn || '')}`;
  const now = Date.now();

  if (runtime.anchorKey !== key || runtime.anchorAt <= 0) {
    runtime.anchorKey = key;
    runtime.anchorLeft = serverLeft;
    runtime.anchorAt = now;
  } else {
    const expected = Math.max(0, runtime.anchorLeft - ((now - runtime.anchorAt) / 1000));
    const clockPending = runtime.clockRequests.get(String(game.id || '')) === 'pending';
    if (!clockPending && Math.abs(expected - serverLeft) > 1.25) {
      runtime.anchorLeft = serverLeft;
      runtime.anchorAt = now;
    }
  }

  const remaining = Math.max(0, Math.ceil(runtime.anchorLeft - ((now - runtime.anchorAt) / 1000)));
  drawTimer(remaining);
}

function drawTimer(value){
  const timer = document.getElementById('timerText');
  if (!timer) return;
  const text = `${Math.max(0, Number(value || 0))} сек`;
  if (timer.textContent !== text) timer.textContent = text;
}

function startPinLoop(){
  if (runtime.pinFrame !== null) return;
  const frame = () => {
    runtime.pinFrame = null;
    const pending = window.__MGW_V105_TICTACTOE__?.pending;
    if (!pending) return;
    paintPinnedMark();
    runtime.pinFrame = window.requestAnimationFrame(frame);
  };
  runtime.pinFrame = window.requestAnimationFrame(frame);
}

function paintPinnedMark(){
  const pending = window.__MGW_V105_TICTACTOE__?.pending;
  if (!pending) return;
  if (String(state.activeGame?.id || '') !== String(pending.gameId || '')) return;

  const cell = document.querySelector(`#gameBoard[data-game-type="tictactoe"] [data-game-cell="${Number(pending.cell)}"]`);
  if (!(cell instanceof HTMLButtonElement)) return;

  const label = pending.symbol === 'X' ? '✕' : '○';
  if (cell.textContent !== label) cell.textContent = label;
  cell.classList.toggle('x', pending.symbol === 'X');
  cell.classList.toggle('o', pending.symbol === 'O');
  cell.classList.add('locked', 'mgw-pending-action');
  if (!cell.disabled) cell.disabled = true;
}

function clamp(value, minimum, maximum){
  if (!Number.isFinite(value)) return maximum;
  return Math.max(minimum, Math.min(maximum, value));
}
