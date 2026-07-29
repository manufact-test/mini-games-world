import { api } from './api/client.js?v=47';
import { state } from './state.js?v=27';
import { getInitData } from './telegram/telegram-app.js?v=27';
import { getSessionId } from './session.js?v=27';

const CLOCK_URL = `${window.location.origin}/bot/game-clock.php`;
const originalGameState = api.gameState.bind(api);
const originalGameAction = api.gameAction.bind(api);
const runtime = window.__MGW_V111_MATCH_START__ ||= {
  initialized:false,
  overlay:null,
  timer:null,
  observer:null,
  anchor:null,
  anchorSignature:'',
  timeoutSettling:new Set(),
};

export function initV111SyncedMatchStart(){
  if (runtime.initialized) return;
  runtime.initialized = true;

  retireV110ClockOwner();
  api.gameState = gameId => synchronizedState(gameId);
  api.gameAction = async (gameId, action) => {
    const result = await originalGameAction(gameId, action);
    return result?.game ? mergeResult(result, await synchronizedState(gameId)) : result;
  };
  // Stale callers must not bypass the common launch/turn guard through make_move.
  api.makeMove = (gameId, cell) => api.gameAction(gameId, { type:'cell', cell });

  installStyle();
  runtime.timer = window.setInterval(tick, 50);
  const timer = document.getElementById('timerText');
  if (timer && typeof MutationObserver === 'function') {
    runtime.observer = new MutationObserver(paintAuthoritativeClock);
    runtime.observer.observe(timer, { childList:true, characterData:true, subtree:true });
  }
}

function retireV110ClockOwner(){
  const v110 = window.__MGW_V110_ACCEPTANCE__;
  if (!v110) return;
  if (v110.timer) window.clearInterval(v110.timer);
  v110.timer = null;
  v110.observer?.disconnect?.();
  v110.observer = null;
  v110.clock = null;
}

async function synchronizedState(gameId){
  const id = String(gameId || state.activeGame?.id || '');
  if (!id) return originalGameState(gameId);
  try {
    const synchronized = await postClock(id);
    if (String(synchronized?.game?.launch_phase || '') !== 'preparation_timeout') return synchronized;
    return settlePreparationTimeout(id, synchronized);
  } catch (error) {
    return originalGameState(gameId);
  }
}

async function settlePreparationTimeout(gameId, snapshot){
  const id = String(gameId || '');
  if (!id || runtime.timeoutSettling.has(id)) return snapshot;
  runtime.timeoutSettling.add(id);
  try {
    const settled = await originalGameAction(id, { type:'cancel_preparation' });
    try {
      const finalSnapshot = await postClock(id);
      return finalSnapshot?.game ? mergeResult(settled, finalSnapshot) : settled;
    } catch (error) {
      return settled;
    }
  } finally {
    runtime.timeoutSettling.delete(id);
  }
}

async function postClock(gameId){
  const speed = window.__MGW_V101_SPEED__;
  const fetcher = typeof speed?.rawFetch === 'function' ? speed.rawFetch : window.fetch.bind(window);
  const response = await fetcher(CLOCK_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body:JSON.stringify({ initData:getInitData(), sessionId:getSessionId(), gameId }),
    cache:'no-store',
    priority:'high',
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data || data.ok === false) throw new Error(data?.error || 'Не удалось синхронизировать матч.');
  rememberAnchor(data.game);
  return data;
}

function mergeResult(primary, synchronized){
  return {
    ...primary,
    ...synchronized,
    user:synchronized?.user || primary?.user,
    me:synchronized?.me || primary?.me,
    game:synchronized?.game || primary?.game,
    session:synchronized?.session || primary?.session,
  };
}

function tick(){
  reconcileV110PendingMove();
  const game = state.activeGame;
  if (!game?.id || String(game.status || '') === 'finished') {
    hideOverlay();
    runtime.anchor = null;
    runtime.anchorSignature = '';
    return;
  }

  rememberAnchor(game);
  renderPreparation(game);
  paintAuthoritativeClock();
}

function reconcileV110PendingMove(){
  const v110 = window.__MGW_V110_ACCEPTANCE__;
  const pending = v110?.pending;
  if (!pending) return;
  const item = window.__MGW_V100_GAME_RUNTIME__?.games?.get?.(pending.gameId);
  const game = item?.authoritative || state.activeGame;
  const board = String(game?.board || '');
  const expired = Date.now() - Number(pending.startedAt || 0) > 5000;
  const changedGame = String(state.activeGame?.id || '') !== String(pending.gameId || '');
  const confirmed = board[Number(pending.cell)] === String(pending.symbol || '');
  const finished = String(game?.status || '') === 'finished';
  if (item?.running || Number(item?.queue?.length || 0) > 0) pending.sawRequest = true;
  const rejected = pending.sawRequest && !item?.running && Number(item?.queue?.length || 0) === 0;
  if (!expired && !changedGame && !confirmed && !finished && !rejected) return;
  v110.pending = null;
  if (v110.pendingFrame) window.cancelAnimationFrame(v110.pendingFrame);
  v110.pendingFrame = 0;
}

function rememberAnchor(game){
  const serverNow = finiteNumber(game?.server_now_ms);
  if (serverNow === null) return;
  const signature = `${String(game?.id || '')}|${serverNow}|${String(game?.turn_revision ?? '')}|${String(game?.launch_phase || '')}`;
  if (signature === runtime.anchorSignature) return;
  runtime.anchorSignature = signature;
  runtime.anchor = { serverNow, localNow:performance.now() };
}

function estimatedServerNow(){
  return runtime.anchor
    ? runtime.anchor.serverNow + Math.max(0, performance.now() - runtime.anchor.localNow)
    : Date.now();
}

function renderPreparation(game){
  const phase = String(game.launch_phase || 'preparing');
  const startsAt = finiteNumber(game.starts_at_ms);
  const remainingMs = startsAt === null ? null : startsAt - estimatedServerNow();
  const shouldShow = phase === 'preparing'
    || phase === 'preparation_timeout'
    || (phase === 'countdown' && (remainingMs === null || remainingMs > 0));
  if (!shouldShow) return hideOverlay();

  const overlay = ensureOverlay();
  const title = String(game.game_title || gameTitle(game.game_type));
  const players = Array.isArray(game.players) ? game.players : [];
  const first = String(players[0]?.name || 'Игрок 1');
  const second = String(players[1]?.name || 'Игрок 2');
  const icon = gameIcon(game.game_type);
  const countdown = phase === 'countdown' && remainingMs !== null
    ? Math.max(1, Math.ceil(remainingMs / 1000))
    : null;

  overlay.querySelector('[data-v111-icon]').textContent = icon;
  overlay.querySelector('[data-v111-title]').textContent = title;
  overlay.querySelector('[data-v111-players]').textContent = `${first}  ·  ${second}`;
  overlay.querySelector('[data-v111-status]').textContent = phase === 'preparation_timeout'
    ? 'Соперник не подключился. Возвращаем ставку…'
    : countdown === null
      ? 'Синхронизируем игроков…'
      : 'Матч начинается';
  const counter = overlay.querySelector('[data-v111-countdown]');
  counter.textContent = countdown === null ? '' : String(countdown);
  counter.hidden = countdown === null;
  overlay.classList.add('show');
  overlay.setAttribute('aria-hidden', 'false');
}

function ensureOverlay(){
  if (runtime.overlay?.isConnected) return runtime.overlay;
  const overlay = document.createElement('section');
  overlay.id = 'mgwV111MatchPreparation';
  overlay.className = 'mgw-v111-preparation';
  overlay.setAttribute('aria-live', 'polite');
  overlay.setAttribute('aria-hidden', 'true');
  overlay.innerHTML = `
    <div class="mgw-v111-preparation-card">
      <div class="mgw-v111-game-icon" data-v111-icon>🎮</div>
      <div class="mgw-v111-game-title" data-v111-title>Игра</div>
      <div class="mgw-v111-players" data-v111-players>Игрок 1 · Игрок 2</div>
      <div class="mgw-v111-status" data-v111-status>Синхронизируем игроков…</div>
      <div class="mgw-v111-countdown" data-v111-countdown hidden></div>
      <div class="mgw-v111-progress" aria-hidden="true"><span></span></div>
      <div class="mgw-v111-note">Подготавливаем поле и единый таймер матча</div>
    </div>`;
  document.body.appendChild(overlay);
  runtime.overlay = overlay;
  return overlay;
}

function hideOverlay(){
  if (!runtime.overlay) return;
  runtime.overlay.classList.remove('show');
  runtime.overlay.setAttribute('aria-hidden', 'true');
}

function paintAuthoritativeClock(){
  const game = state.activeGame;
  const timer = document.getElementById('timerText');
  if (!timer || !game?.id || String(game.status || '') !== 'active') return;
  const startsAt = finiteNumber(game.turn_starts_at_ms);
  const deadline = finiteNumber(game.turn_deadline_ms);
  if (deadline === null) return;
  const serverNow = estimatedServerNow();
  const timeout = Math.max(1, Number(game.move_timeout_sec || 60));
  const seconds = startsAt !== null && serverNow < startsAt
    ? timeout
    : Math.max(0, Math.min(timeout, Math.ceil((deadline - serverNow) / 1000)));
  const label = `${seconds} сек`;
  if (timer.textContent !== label) timer.textContent = label;
}

function installStyle(){
  if (document.getElementById('mgw-v111-preparation-style')) return;
  const style = document.createElement('style');
  style.id = 'mgw-v111-preparation-style';
  style.textContent = `
    .mgw-v111-preparation{position:fixed;inset:0;z-index:12000;display:grid;place-items:center;padding:24px;background:rgba(10,13,20,.94);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .28s ease,visibility .28s ease}
    .mgw-v111-preparation.show{opacity:1;visibility:visible;pointer-events:auto}
    .mgw-v111-preparation-card{width:min(100%,420px);display:grid;justify-items:center;gap:12px;text-align:center;color:#fff}
    .mgw-v111-game-icon{width:84px;height:84px;display:grid;place-items:center;border-radius:26px;background:rgba(255,255,255,.09);font-size:40px;animation:mgwV111Float 1.8s ease-in-out infinite}
    .mgw-v111-game-title{font-size:25px;font-weight:700;line-height:1.15}
    .mgw-v111-players{font-size:15px;line-height:1.35;color:rgba(255,255,255,.74)}
    .mgw-v111-status{margin-top:8px;font-size:17px;font-weight:600}
    .mgw-v111-countdown{font-size:58px;font-weight:800;line-height:1;min-height:58px;font-variant-numeric:tabular-nums}
    .mgw-v111-progress{width:min(100%,280px);height:4px;overflow:hidden;border-radius:999px;background:rgba(255,255,255,.14)}
    .mgw-v111-progress span{display:block;width:42%;height:100%;border-radius:inherit;background:currentColor;animation:mgwV111Progress 1.15s ease-in-out infinite}
    .mgw-v111-note{font-size:13px;line-height:1.4;color:rgba(255,255,255,.56)}
    @keyframes mgwV111Float{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
    @keyframes mgwV111Progress{0%{transform:translateX(-115%)}100%{transform:translateX(340%)}}
    @media (prefers-reduced-motion:reduce){.mgw-v111-game-icon,.mgw-v111-progress span{animation:none}.mgw-v111-preparation{transition:none}}
  `;
  document.head.appendChild(style);
}

function gameTitle(type){
  return ({
    tictactoe:'Крестики-нолики', four_in_a_row:'4 в ряд', battleship:'Морской бой',
    checkers:'Шашки', reversi:'Реверси', chess:'Шахматы', go:'Го', domino:'Домино',
  })[String(type || '')] || 'Игра';
}

function gameIcon(type){
  return ({
    tictactoe:'✕○', four_in_a_row:'🔴', battleship:'🚢', checkers:'⛀',
    reversi:'◐', chess:'♟', go:'⚫', domino:'🁫',
  })[String(type || '')] || '🎮';
}

function finiteNumber(value){
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}
