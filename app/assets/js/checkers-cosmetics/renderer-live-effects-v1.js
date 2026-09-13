import {
  renderCheckersSurface as renderBoardThemeSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-board-themes.js?v=1&mvp19_6=board-themes&base=accepted-v57';

const LIVE_EFFECT_DURATION_MS = 1880;
const liveEffectStates = new Map();
const lastSeenMoveSignatures = new Map();

ensureLiveCosmeticStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface({ game, me, container, onAction }){
  renderBoardThemeSurface({ game, me, container, onAction });
  syncLivePieceSets({ game, container });
  syncLiveEffect({ game, me, container });
}

function syncLivePieceSets({ game, container }){
  const players = Array.isArray(game?.players) ? game.players : [];
  const playerBySide = new Map(players.map(player => [String(player?.side || ''), player]));
  const whiteVariant = pieceVariantFor(playerBySide.get('white'));
  const blackVariant = pieceVariantFor(playerBySide.get('black'));

  container.dataset.checkersWhitePieceStyle = whiteVariant;
  container.dataset.checkersBlackPieceStyle = blackVariant;

  container.querySelectorAll('.checkers-piece.white').forEach(piece => {
    piece.dataset.checkersPieceStyle = whiteVariant;
  });
  container.querySelectorAll('.checkers-piece.black').forEach(piece => {
    piece.dataset.checkersPieceStyle = blackVariant;
  });
}

function syncLiveEffect({ game, me, container }){
  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;

  const gameKey = String(game?.id || 'local-checkers');
  const moveSignature = lastMoveSignature(game);

  // The first snapshot for a mounted/reconnected game is baseline state, not a new
  // event. Recording it here prevents stale paid effects from replaying on reload.
  if (!lastSeenMoveSignatures.has(gameKey)) {
    lastSeenMoveSignatures.set(gameKey, moveSignature);
    clearLiveEffect(container);
    return;
  }

  const previousMoveSignature = lastSeenMoveSignatures.get(gameKey) || '';
  const isNewMove = Boolean(moveSignature && moveSignature !== previousMoveSignature);
  if (moveSignature !== previousMoveSignature) {
    lastSeenMoveSignatures.set(gameKey, moveSignature);
  }

  const now = performance.now();
  let state = liveEffectStates.get(gameKey) || null;

  if (prefersReducedMotion()) {
    if (state?.timer) clearTimeout(state.timer);
    liveEffectStates.delete(gameKey);
    clearLiveEffect(container);
    return;
  }

  if (isNewMove) {
    const plan = effectPlan(game, me);
    if (!plan) {
      if (state?.timer) clearTimeout(state.timer);
      liveEffectStates.delete(gameKey);
      clearLiveEffect(container);
      return;
    }

    const signature = `${moveSignature}:${plan.effectId}:${plan.kind}`;
    if (state?.timer) clearTimeout(state.timer);
    state = startEffectState({ gameKey, signature, moveSignature, plan, container, now });
  } else if (!state || state.moveSignature !== moveSignature) {
    clearLiveEffect(container);
    return;
  }

  const elapsed = Math.max(0, now - state.startedAt);
  if (elapsed >= LIVE_EFFECT_DURATION_MS) {
    liveEffectStates.delete(gameKey);
    clearLiveEffect(container);
    return;
  }

  renderLiveEffect(board, container, state.plan, elapsed);
}

function startEffectState({ gameKey, signature, moveSignature, plan, container, now }){
  const state = {
    signature,
    moveSignature,
    plan,
    startedAt: now,
    timer: window.setTimeout(() => {
      const active = liveEffectStates.get(gameKey);
      if (!active || active.signature !== signature) return;
      liveEffectStates.delete(gameKey);
      clearLiveEffect(container);
    }, LIVE_EFFECT_DURATION_MS + 40),
  };
  liveEffectStates.set(gameKey, state);
  return state;
}

function effectPlan(game, me){
  const move = game?.last_move;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  if (from === null || to === null) return null;

  const players = Array.isArray(game?.players) ? game.players : [];
  const movePlayerId = String(move?.player_id || '');
  const moveSide = normalizedSide(move?.side);
  const board = Array.from({ length:64 }, (_, index) => String(game?.board?.[index] || ''));
  const movedPiece = String(board[to] || '');
  const boardSide = movedPiece.toLowerCase() === 'w'
    ? 'white'
    : (movedPiece.toLowerCase() === 'b' ? 'black' : '');
  const side = moveSide || boardSide;
  const mover = (movePlayerId ? players.find(player => String(player?.id || '') === movePlayerId) : null)
    || (side ? players.find(player => String(player?.side || '') === side) : null)
    || null;

  // Never borrow the viewer's cosmetic for an opponent move. If a legacy payload
  // omits mover cosmetics, the correct behaviour is no paid effect, not a false one.
  const moverIsViewer = mover && String(mover?.id || '') === String(me?.id || '');
  const effectId = String(equippedSlots(mover).game_checkers_effect || '');
  if (!mover && movePlayerId && movePlayerId === String(me?.id || '')) return null;
  if (!effectId && moverIsViewer) return null;

  const promotionCell = integerOrNull(game?.last_promotion);
  const capturedCell = capturedCellFor(game, from, to);
  const promoted = move?.promoted === true || promotionCell === to;
  const captured = move?.capture === true || capturedCell !== null;
  const kind = promoted ? 'promotion' : (captured ? 'capture' : 'move');

  if (effectId !== `game-checkers-effect-${kind}`) return null;

  const opponentSide = side === 'black' ? 'white' : 'black';
  const targetPlayer = players.find(player => String(player?.side || '') === opponentSide) || null;

  return {
    kind,
    effectId,
    from,
    to,
    capturedCell,
    pieceSide: side === 'black' ? 'black' : 'white',
    targetSide: opponentSide,
    pieceStyle: pieceVariantFor(mover),
    targetStyle: pieceVariantFor(targetPlayer),
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
    pieceSize * 1.35,
    cellSize * (plan.kind === 'capture' ? 1.72 : 1.38),
  );
  const crownSize = Math.max(pieceSize * 1.5, cellSize * 1.38);
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
  layer.style.setProperty('--mgw-fx-crown-font', `${Math.max(24, cellSize * .82)}px`);
  layer.style.setProperty('--mgw-fx-path-height', `${Math.max(6, cellSize * .13)}px`);
  layer.style.setProperty('--mgw-fx-delay', `-${Math.round(elapsed)}ms`);

  layer.innerHTML = `
    <span class="mgw-checkers-live-fx-path" style="left:${fromPoint.x}px;top:${fromPoint.y}px;width:${pathDistance}px;transform:translateY(-50%) rotate(${pathAngle}deg)"></span>
    <span class="mgw-checkers-live-fx-piece ${plan.pieceSide}" data-checkers-piece-style="${plan.pieceStyle}"></span>
    ${plan.kind === 'capture' && capturePoint ? `<span class="mgw-checkers-live-fx-target ${plan.targetSide}" data-checkers-piece-style="${plan.targetStyle}" style="left:${capturePoint.x}px;top:${capturePoint.y}px;width:${pieceSize}px;height:${pieceSize}px"></span>` : ''}
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
    ? game.last_captured_cells.map(value => integerOrNull(value)).filter(value => value !== null)
    : [];
  if (captured.length > 0) return captured[captured.length - 1];

  if (game?.last_move?.capture !== true) return null;
  const fromRow = Math.floor(from / 8);
  const fromCol = from % 8;
  const toRow = Math.floor(to / 8);
  const toCol = to % 8;
  if (Math.abs(toRow - fromRow) < 2 || Math.abs(toCol - fromCol) < 2) return null;
  const midRow = Math.round((fromRow + toRow) / 2);
  const midCol = Math.round((fromCol + toCol) / 2);
  return midRow * 8 + midCol;
}

function pieceVariantFor(player){
  const itemId = String(equippedSlots(player).game_checkers_elements || '');
  const marker = 'game-checkers-pieces-';
  if (!itemId.startsWith(marker)) return 'base';
  const variant = itemId.slice(marker.length);
  return ['wood','marble','metal','neon'].includes(variant) ? variant : 'base';
}

function equippedSlots(player){
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? slots : {};
}

function normalizedSide(value){
  const side = String(value || '').toLowerCase();
  return side === 'white' || side === 'black' ? side : '';
}

function integerOrNull(value){
  if (value === null || value === undefined || value === '') return null;
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric >= 0 && numeric < 64 ? numeric : null;
}

function lastMoveSignature(game){
  const move = game?.last_move;
  if (!move) return '';
  return [
    String(game?.id || ''),
    String(move?.player_id || ''),
    String(move?.side || ''),
    String(move?.from ?? ''),
    String(move?.to ?? ''),
    move?.capture === true ? 'capture' : 'move',
    String(move?.captured ?? ''),
    move?.promoted === true ? 'promoted' : '',
    move?.chain_continues === true ? 'chain' : '',
  ].join(':');
}

function prefersReducedMotion(){
  return typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function ensureLiveCosmeticStyles(){
  if (!document.querySelector('link[data-mgw-checkers-live-pieces]')) {
    const pieceLink = document.createElement('link');
    pieceLink.rel = 'stylesheet';
    pieceLink.dataset.mgwCheckersLivePieces = 'mvp19-6-store-parity-v1';
    pieceLink.href = new URL('../../css/games/checkers/live-pieces-store-parity-v1.css?v=1&mvp19_6=store-parity', import.meta.url).href;
    document.head.appendChild(pieceLink);
  }

  if (!document.querySelector('link[data-mgw-checkers-live-effects]')) {
    const effectLink = document.createElement('link');
    effectLink.rel = 'stylesheet';
    effectLink.dataset.mgwCheckersLiveEffects = 'mvp19-6-store-parity-v2';
    effectLink.href = new URL('../../css/games/checkers/live-effects-store-parity-v1.css?v=2&mvp19_6=store-parity', import.meta.url).href;
    document.head.appendChild(effectLink);
  }
}
