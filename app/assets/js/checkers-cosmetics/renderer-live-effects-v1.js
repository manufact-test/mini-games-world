import {
  renderCheckersSurface as renderBoardThemeSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-board-themes.js?v=1&mvp19_6=board-themes&base=accepted-v57';

const LIVE_EFFECT_DURATION_MS = 1880;
const liveEffectStates = new Map();

ensureLiveEffectStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface({ game, me, container, onAction }){
  renderBoardThemeSurface({ game, me, container, onAction });
  syncLiveEffect({ game, me, container });
}

function syncLiveEffect({ game, me, container }){
  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;

  const gameKey = String(game?.id || 'local-checkers');
  const plan = effectPlan(game, me);
  const signature = plan ? `${lastMoveSignature(game)}:${plan.effectId}:${plan.kind}` : '';
  const now = performance.now();
  const current = liveEffectStates.get(gameKey) || null;

  if (!plan || !signature || prefersReducedMotion()) {
    if (current?.timer) clearTimeout(current.timer);
    liveEffectStates.delete(gameKey);
    clearLiveEffect(container);
    return;
  }

  let state = current;
  if (!state || state.signature !== signature) {
    if (state?.timer) clearTimeout(state.timer);
    state = {
      signature,
      startedAt: now,
      timer: window.setTimeout(() => {
        const active = liveEffectStates.get(gameKey);
        if (!active || active.signature !== signature) return;
        liveEffectStates.delete(gameKey);
        clearLiveEffect(container);
      }, LIVE_EFFECT_DURATION_MS + 40),
    };
    liveEffectStates.set(gameKey, state);
  }

  const elapsed = Math.max(0, now - state.startedAt);
  if (elapsed >= LIVE_EFFECT_DURATION_MS) {
    liveEffectStates.delete(gameKey);
    clearLiveEffect(container);
    return;
  }

  renderLiveEffect(board, container, plan, elapsed);
}

function effectPlan(game, me){
  const move = game?.last_move;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  if (from === null || to === null) return null;

  const board = Array.from({ length:64 }, (_, index) => String(game?.board?.[index] || ''));
  const movedPiece = String(board[to] || '');
  const side = movedPiece.toLowerCase() === 'w'
    ? 'white'
    : (movedPiece.toLowerCase() === 'b' ? 'black' : '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const mover = players.find(player => String(player?.side || '') === side)
    || players.find(player => String(player?.id || '') === String(me?.id || ''))
    || null;
  const effectId = String(equippedSlots(mover).game_checkers_effect || '');

  const promotionCell = integerOrNull(game?.last_promotion);
  const capturedCell = capturedCellFor(game, from, to);
  const kind = promotionCell !== null
    ? 'promotion'
    : (capturedCell !== null ? 'capture' : 'move');

  if (effectId !== `game-checkers-effect-${kind}`) return null;

  return {
    kind,
    effectId,
    from,
    to,
    capturedCell,
    pieceSide: side === 'black' ? 'black' : 'white',
    targetSide: side === 'black' ? 'white' : 'black',
  };
}

function renderLiveEffect(board, container, plan, elapsed){
  clearLiveEffect(container);

  const fromPoint = cellPoint(board, plan.from);
  const toPoint = cellPoint(board, plan.to);
  if (!fromPoint || !toPoint) return;

  const capturePoint = plan.capturedCell === null ? null : cellPoint(board, plan.capturedCell);
  const cellSize = Math.min(fromPoint.size, toPoint.size);
  const pieceSize = Math.max(20, cellSize * .72);
  const impactSize = Math.max(
    pieceSize * 1.2,
    cellSize * (plan.kind === 'capture' ? 1.48 : 1.24),
  );
  const crownSize = Math.max(pieceSize * 1.3, cellSize * 1.2);
  const pathDx = toPoint.x - fromPoint.x;
  const pathDy = toPoint.y - fromPoint.y;
  const pathDistance = Math.hypot(pathDx, pathDy);
  const pathAngle = Math.atan2(pathDy, pathDx) * 180 / Math.PI;

  const layer = document.createElement('span');
  layer.className = `mgw-checkers-live-fx mgw-checkers-live-fx-${plan.kind}`;
  layer.setAttribute('aria-hidden', 'true');
  layer.style.setProperty('--mgw-fx-from-x', `${fromPoint.x}px`);
  layer.style.setProperty('--mgw-fx-from-y', `${fromPoint.y}px`);
  layer.style.setProperty('--mgw-fx-to-x', `${toPoint.x}px`);
  layer.style.setProperty('--mgw-fx-to-y', `${toPoint.y}px`);
  layer.style.setProperty('--mgw-fx-piece-size', `${pieceSize}px`);
  layer.style.setProperty('--mgw-fx-impact-size', `${impactSize}px`);
  layer.style.setProperty('--mgw-fx-crown-size', `${crownSize}px`);
  layer.style.setProperty('--mgw-fx-crown-font', `${Math.max(22, cellSize * .76)}px`);
  layer.style.setProperty('--mgw-fx-path-height', `${Math.max(5, cellSize * .11)}px`);
  layer.style.setProperty('--mgw-fx-delay', `-${Math.round(elapsed)}ms`);

  layer.innerHTML = `
    <span class="mgw-checkers-live-fx-path" style="left:${fromPoint.x}px;top:${fromPoint.y}px;width:${pathDistance}px;transform:translateY(-50%) rotate(${pathAngle}deg)"></span>
    <span class="mgw-checkers-live-fx-piece ${plan.pieceSide}"></span>
    ${plan.kind === 'capture' && capturePoint ? `<span class="mgw-checkers-live-fx-target ${plan.targetSide}" style="left:${capturePoint.x}px;top:${capturePoint.y}px;width:${pieceSize}px;height:${pieceSize}px"></span>` : ''}
    <span class="mgw-checkers-live-fx-impact" style="left:${plan.kind === 'capture' && capturePoint ? capturePoint.x : toPoint.x}px;top:${plan.kind === 'capture' && capturePoint ? capturePoint.y : toPoint.y}px"></span>
    ${plan.kind === 'promotion' ? `<span class="mgw-checkers-live-fx-crown" style="left:${toPoint.x}px;top:${toPoint.y}px">♛</span>` : ''}
  `;

  const toCell = board.querySelector(`[data-checkers-cell="${plan.to}"]`);
  if (toCell instanceof HTMLElement) toCell.classList.add('mgw-checkers-live-fx-hide-piece');

  container.classList.add('mgw-checkers-live-fx-running');
  container.dataset.mgwCheckersLiveEffect = plan.kind;
  board.appendChild(layer);
}

function clearLiveEffect(container){
  container.classList.remove('mgw-checkers-live-fx-running');
  delete container.dataset.mgwCheckersLiveEffect;
  container.querySelectorAll('.mgw-checkers-live-fx').forEach(node => node.remove());
  container.querySelectorAll('.mgw-checkers-live-fx-hide-piece').forEach(node => node.classList.remove('mgw-checkers-live-fx-hide-piece'));
}

function cellPoint(board, cellIndex){
  const cell = board.querySelector(`[data-checkers-cell="${cellIndex}"]`);
  if (!(cell instanceof HTMLElement)) return null;
  const boardRect = board.getBoundingClientRect();
  const cellRect = cell.getBoundingClientRect();
  return {
    x: cellRect.left - boardRect.left + cellRect.width / 2,
    y: cellRect.top - boardRect.top + cellRect.height / 2,
    size: Math.min(cellRect.width, cellRect.height),
  };
}

function capturedCellFor(game, from, to){
  const direct = integerOrNull(game?.last_move?.captured);
  if (direct !== null) return direct;

  const captured = Array.isArray(game?.last_captured_cells)
    ? game.last_captured_cells.map(integerOrNull).filter(value => value !== null)
    : [];
  if (captured.length > 0) return captured[captured.length - 1];

  const fromRow = Math.floor(from / 8);
  const fromCol = from % 8;
  const toRow = Math.floor(to / 8);
  const toCol = to % 8;
  if (Math.abs(toRow - fromRow) < 2 || Math.abs(toCol - fromCol) < 2) return null;
  const midRow = Math.round((fromRow + toRow) / 2);
  const midCol = Math.round((fromCol + toCol) / 2);
  return midRow * 8 + midCol;
}

function equippedSlots(player){
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? slots : {};
}

function integerOrNull(value){
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric >= 0 && numeric < 64 ? numeric : null;
}

function lastMoveSignature(game){
  const move = game?.last_move;
  if (!move) return '';
  return [
    String(game?.id || ''),
    String(move?.from ?? ''),
    String(move?.to ?? ''),
    String(move?.captured ?? ''),
    String(move?.promoted ?? ''),
    String(game?.last_promotion ?? ''),
    Array.isArray(game?.last_captured_cells) ? game.last_captured_cells.join(',') : '',
  ].join(':');
}

function prefersReducedMotion(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function ensureLiveEffectStyles(){
  if (document.querySelector('link[data-mgw-checkers-live-effects]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersLiveEffects = 'mvp19-6-store-parity-v1';
  link.href = new URL('../../css/games/checkers/live-effects-store-parity-v1.css?v=1&mvp19_6=store-parity', import.meta.url).href;
  document.head.appendChild(link);
}
