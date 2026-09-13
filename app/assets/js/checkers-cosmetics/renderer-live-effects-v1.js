import {
  renderCheckersSurface as renderBoardThemeSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-board-themes.js?v=1&mvp19_6=board-themes&base=accepted-v57';

const LIVE_EFFECT_DURATION_MS = 1880;
const PENDING_CLAIM_TTL_MS = 12000;
const liveEffectStates = new Map();
const pendingEffectClaims = new Map();
const lastSeenMoveSignatures = new Map();
const authoritativeBoards = new Map();

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
  const pendingAction = game?.__mgw_v100_pending_action || null;
  const now = performance.now();

  if (prefersReducedMotion()) {
    cancelEffectState(gameKey, container);
    pendingEffectClaims.delete(gameKey);
    if (!pendingAction) rememberAuthoritativeBoard(gameKey, game);
    return;
  }

  // Start the visual movement on the optimistic frame so the checker never jumps
  // to the destination and then replays backwards after the server response.
  // Event classification still compares against the last authoritative board, so
  // an already-crowned king can never masquerade as a new promotion.
  if (pendingAction) {
    const plan = pendingEffectPlan(game, me, authoritativeBoards.get(gameKey) || null, pendingAction);
    if (!plan) {
      cancelEffectState(gameKey, container);
      return;
    }

    const pendingSignature = pendingActionSignature(gameKey, plan);
    let state = liveEffectStates.get(gameKey) || null;
    if (!state || state.pendingSignature !== pendingSignature) {
      cancelEffectState(gameKey, container);
      state = startEffectState({
        gameKey,
        signature:`pending:${pendingSignature}`,
        pendingSignature,
        moveSignature:'',
        plan,
        container,
        now,
        source:'pending',
      });
      pendingEffectClaims.set(gameKey, {
        signature:pendingSignature,
        from:plan.from,
        to:plan.to,
        kind:plan.kind,
        moverId:plan.moverId,
        expiresAt:Date.now() + PENDING_CLAIM_TTL_MS,
      });
    }

    renderLiveEffect(board, container, state);
    return;
  }

  const moveSignature = lastMoveSignature(game);

  // The first snapshot for a mounted/reconnected game is baseline state, not a new
  // event. Recording it here prevents stale paid effects from replaying on reload.
  if (!lastSeenMoveSignatures.has(gameKey)) {
    lastSeenMoveSignatures.set(gameKey, moveSignature);
    cancelEffectState(gameKey, container);
    pendingEffectClaims.delete(gameKey);
    rememberAuthoritativeBoard(gameKey, game);
    return;
  }

  const previousMoveSignature = lastSeenMoveSignatures.get(gameKey) || '';
  const isNewMove = Boolean(moveSignature && moveSignature !== previousMoveSignature);
  if (moveSignature !== previousMoveSignature) {
    lastSeenMoveSignatures.set(gameKey, moveSignature);
  }

  let state = liveEffectStates.get(gameKey) || null;

  if (isNewMove) {
    const claim = consumeMatchingPendingClaim(gameKey, game);
    if (claim) {
      // The optimistic animation already represented this exact completed move.
      // Keep it alive if it is still running, but never restart it on confirmation.
      if (state?.pendingSignature === claim.signature) {
        state.source = 'authoritative-confirmed';
        state.moveSignature = moveSignature;
      }
      // The frozen base renderer paints its own short move/capture/promotion flourish
      // on the authoritative snapshot. A paid overlay has already represented this
      // event, so consume those transient classes before our landing handoff.
      stripBaseTransientAnimations(container);
    } else {
      const plan = effectPlan(game, me);
      if (!plan) {
        cancelEffectState(gameKey, container);
        rememberAuthoritativeBoard(gameKey, game);
        return;
      }

      cancelEffectState(gameKey, container);
      state = startEffectState({
        gameKey,
        signature:`authoritative:${moveSignature}:${plan.effectId}:${plan.kind}`,
        pendingSignature:'',
        moveSignature,
        plan,
        container,
        now,
        source:'authoritative',
      });
    }
  } else if (!state || (state.moveSignature && state.moveSignature !== moveSignature)) {
    clearEffectPresentation(container);
    rememberAuthoritativeBoard(gameKey, game);
    return;
  }

  state = liveEffectStates.get(gameKey) || state;
  if (state) {
    const elapsed = Math.max(0, now - state.startedAt);
    if (elapsed >= LIVE_EFFECT_DURATION_MS) {
      finishEffectState(gameKey, state.signature);
    } else {
      renderLiveEffect(board, container, state);
    }
  } else {
    clearEffectPresentation(container);
  }

  rememberAuthoritativeBoard(gameKey, game);
}

function startEffectState({ gameKey, signature, pendingSignature, moveSignature, plan, container, now, source }){
  const state = {
    signature,
    pendingSignature,
    moveSignature,
    plan,
    source,
    container,
    layer:null,
    startedAt:now,
    timer:window.setTimeout(() => finishEffectState(gameKey, signature), LIVE_EFFECT_DURATION_MS + 40),
  };
  liveEffectStates.set(gameKey, state);
  return state;
}

function finishEffectState(gameKey, signature){
  const active = liveEffectStates.get(gameKey);
  if (!active || active.signature !== signature) return;
  if (active.timer) clearTimeout(active.timer);
  if (active.layer?.isConnected) active.layer.remove();
  stripBaseTransientAnimations(active.container);
  clearEffectPresentation(active.container);
  liveEffectStates.delete(gameKey);
}

function cancelEffectState(gameKey, container){
  const state = liveEffectStates.get(gameKey) || null;
  if (state?.timer) clearTimeout(state.timer);
  if (state?.layer?.isConnected) state.layer.remove();
  liveEffectStates.delete(gameKey);
  clearEffectPresentation(container || state?.container || null);
}

function effectPlan(game, me){
  const move = game?.last_move;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  if (from === null || to === null) return null;

  const players = Array.isArray(game?.players) ? game.players : [];
  const movePlayerId = String(move?.player_id || '');
  const moveSide = normalizedSide(move?.side);
  const board = boardArray(game);
  const movedPiece = String(board[to] || '');
  const boardSide = sideForPiece(movedPiece);
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
  const kind = liveEffectKind(effectId, { captured, promoted });
  if (!kind) return null;

  return buildPlan({
    players,
    mover,
    moverId:movePlayerId,
    side,
    effectId,
    kind,
    from,
    to,
    capturedCell,
    captured,
    promoted,
  });
}

function pendingEffectPlan(game, me, previousBoard, action){
  const from = integerOrNull(action?.from);
  const to = integerOrNull(action?.to);
  if (from === null || to === null) return null;

  const players = Array.isArray(game?.players) ? game.players : [];
  const mover = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  if (!mover) return null;

  const effectId = String(equippedSlots(mover).game_checkers_effect || '');
  if (!effectId) return null;

  const board = boardArray(game);
  const beforePiece = String(previousBoard?.[from] || '');
  const afterPiece = String(board[to] || '');
  const side = normalizedSide(mover?.side) || sideForPiece(beforePiece) || sideForPiece(afterPiece);
  const capturedCell = capturedCellFor(game, from, to);
  const captured = game?.last_move?.capture === true || capturedCell !== null;
  const promoted = isFreshPromotion(beforePiece, afterPiece);
  const kind = liveEffectKind(effectId, { captured, promoted });
  if (!kind) return null;

  return buildPlan({
    players,
    mover,
    moverId:String(mover?.id || me?.id || ''),
    side,
    effectId,
    kind,
    from,
    to,
    capturedCell,
    captured,
    promoted,
  });
}

function liveEffectKind(effectId, { captured, promoted }){
  if (effectId === 'game-checkers-effect-move') return 'move';
  if (effectId === 'game-checkers-effect-capture' && captured) return 'capture';
  if (effectId === 'game-checkers-effect-promotion' && promoted) return 'promotion';
  return '';
}

function buildPlan({ players, mover, moverId, side, effectId, kind, from, to, capturedCell, captured, promoted }){
  const safeSide = side === 'black' ? 'black' : 'white';
  const opponentSide = safeSide === 'black' ? 'white' : 'black';
  const targetPlayer = players.find(player => String(player?.side || '') === opponentSide) || null;

  return {
    kind,
    effectId,
    from,
    to,
    capturedCell,
    captured:Boolean(captured),
    promoted:Boolean(promoted),
    moverId:String(moverId || mover?.id || ''),
    pieceSide:safeSide,
    targetSide:opponentSide,
    pieceStyle:pieceVariantFor(mover),
    targetStyle:pieceVariantFor(targetPlayer),
  };
}

function renderLiveEffect(board, container, state){
  applyEffectMask(board, container, state.plan);

  if (!state.layer?.isConnected) {
    state.layer = createEffectLayer(board, state.plan);
    if (!state.layer) return;
    document.body.appendChild(state.layer);
  }

  positionEffectLayer(state.layer, board);
}

function createEffectLayer(board, plan){
  const fromPoint = cellPoint(board, plan.from);
  const toPoint = cellPoint(board, plan.to);
  if (!fromPoint || !toPoint) return null;

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
  const eventClasses = [
    plan.captured ? 'mgw-checkers-live-fx-event-capture' : '',
    plan.promoted ? 'mgw-checkers-live-fx-event-promotion' : '',
  ].filter(Boolean).join(' ');
  layer.className = `mgw-checkers-live-fx mgw-checkers-live-fx-${plan.kind}${eventClasses ? ` ${eventClasses}` : ''}`;
  layer.setAttribute('aria-hidden', 'true');
  layer.style.setProperty('--mgw-fx-from-x', `${fromPoint.x}px`);
  layer.style.setProperty('--mgw-fx-from-y', `${fromPoint.y}px`);
  layer.style.setProperty('--mgw-fx-to-x', `${toPoint.x}px`);
  layer.style.setProperty('--mgw-fx-to-y', `${toPoint.y}px`);
  layer.style.setProperty('--mgw-fx-dx', `${pathDx}px`);
  layer.style.setProperty('--mgw-fx-dy', `${pathDy}px`);
  layer.style.setProperty('--mgw-fx-piece-size', `${pieceSize}px`);
  layer.style.setProperty('--mgw-fx-impact-size', `${impactSize}px`);
  layer.style.setProperty('--mgw-fx-crown-size', `${crownSize}px`);
  layer.style.setProperty('--mgw-fx-crown-font', `${Math.max(24, cellSize * .82)}px`);
  layer.style.setProperty('--mgw-fx-path-height', `${Math.max(6, cellSize * .13)}px`);
  layer.style.setProperty('--mgw-fx-delay', '0ms');

  layer.innerHTML = `
    <span class="mgw-checkers-live-fx-path" style="left:${fromPoint.x}px;top:${fromPoint.y}px;width:${pathDistance}px;transform:translateY(-50%) rotate(${pathAngle}deg)"></span>
    <span class="mgw-checkers-live-fx-piece ${plan.pieceSide}" data-checkers-piece-style="${plan.pieceStyle}"></span>
    ${plan.kind === 'capture' && capturePoint ? `<span class="mgw-checkers-live-fx-target ${plan.targetSide}" data-checkers-piece-style="${plan.targetStyle}" style="left:${capturePoint.x}px;top:${capturePoint.y}px;width:${pieceSize}px;height:${pieceSize}px"></span>` : ''}
    <span class="mgw-checkers-live-fx-impact" style="left:${plan.kind === 'capture' && capturePoint ? capturePoint.x : toPoint.x}px;top:${plan.kind === 'capture' && capturePoint ? capturePoint.y : toPoint.y}px"></span>
    ${plan.kind === 'promotion' ? `<span class="mgw-checkers-live-fx-crown" style="left:${toPoint.x}px;top:${toPoint.y}px">♛</span>` : ''}
  `;

  return layer;
}

function positionEffectLayer(layer, board){
  if (!(layer instanceof HTMLElement) || !(board instanceof HTMLElement)) return;
  const rect = board.getBoundingClientRect();
  layer.style.left = `${rect.left}px`;
  layer.style.top = `${rect.top}px`;
  layer.style.width = `${rect.width}px`;
  layer.style.height = `${rect.height}px`;
  layer.style.borderRadius = getComputedStyle(board).borderRadius;
}

function applyEffectMask(board, container, plan){
  clearEffectPresentation(container);
  container.classList.add('mgw-checkers-live-fx-running');
  container.dataset.mgwCheckersLiveEffect = plan.kind;

  const toCell = board.querySelector(`[data-checkers-cell="${plan.to}"]`);
  if (toCell instanceof HTMLElement) toCell.classList.add('mgw-checkers-live-fx-hide-piece');

  if (plan.kind === 'capture' && plan.capturedCell !== null) {
    const capturedCell = board.querySelector(`[data-checkers-cell="${plan.capturedCell}"]`);
    if (capturedCell instanceof HTMLElement) capturedCell.classList.add('mgw-checkers-live-fx-hide-piece');
  }
}

function stripBaseTransientAnimations(container){
  if (!(container instanceof HTMLElement)) return;
  container.querySelectorAll('.move-impact').forEach(node => node.classList.remove('move-impact'));
  container.querySelectorAll('.captured-flash').forEach(node => node.classList.remove('captured-flash'));
  container.querySelectorAll('.promotion-flash').forEach(node => node.classList.remove('promotion-flash'));
}

function clearEffectPresentation(container){
  if (!(container instanceof HTMLElement)) return;
  container.classList.remove('mgw-checkers-live-fx-running');
  delete container.dataset.mgwCheckersLiveEffect;
  container.querySelectorAll('.mgw-checkers-live-fx-hide-piece').forEach(node => node.classList.remove('mgw-checkers-live-fx-hide-piece'));
}

function cellPoint(board, cellIndex){
  const cell = board.querySelector(`[data-checkers-cell="${cellIndex}"]`);
  if (!(cell instanceof HTMLElement)) return null;
  const boardRect = board.getBoundingClientRect();
  const cellRect = cell.getBoundingClientRect();
  return {
    x:cellRect.left - boardRect.left + cellRect.width / 2,
    y:cellRect.top - boardRect.top + cellRect.height / 2,
    size:Math.min(cellRect.width, cellRect.height),
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

function consumeMatchingPendingClaim(gameKey, game){
  const claim = pendingEffectClaims.get(gameKey) || null;
  if (!claim) return null;
  if (Date.now() > Number(claim.expiresAt || 0)) {
    pendingEffectClaims.delete(gameKey);
    return null;
  }

  const move = game?.last_move;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  const moverId = String(move?.player_id || '');
  const flags = authoritativeMoveFlags(game, from, to);
  const kindMatches = claim.kind === 'move'
    || (claim.kind === 'capture' && flags.captured)
    || (claim.kind === 'promotion' && flags.promoted);
  const matches = from === claim.from
    && to === claim.to
    && kindMatches
    && (!claim.moverId || !moverId || moverId === claim.moverId);

  if (!matches) return null;
  pendingEffectClaims.delete(gameKey);
  return claim;
}

function authoritativeMoveFlags(game, from, to){
  if (from === null || to === null) return { captured:false, promoted:false };
  const move = game?.last_move;
  const promotionCell = integerOrNull(game?.last_promotion);
  return {
    captured:move?.capture === true || capturedCellFor(game, from, to) !== null,
    promoted:move?.promoted === true || promotionCell === to,
  };
}

function pendingActionSignature(gameKey, plan){
  return [gameKey, plan.moverId, plan.from, plan.to, plan.kind, plan.effectId].join(':');
}

function rememberAuthoritativeBoard(gameKey, game){
  authoritativeBoards.set(gameKey, boardArray(game));
}

function boardArray(game){
  return Array.from({ length:64 }, (_, index) => String(game?.board?.[index] || ''));
}

function isFreshPromotion(beforePiece, afterPiece){
  return (beforePiece === 'w' && afterPiece === 'W')
    || (beforePiece === 'b' && afterPiece === 'B');
}

function sideForPiece(piece){
  const normalized = String(piece || '').toLowerCase();
  if (normalized === 'w') return 'white';
  if (normalized === 'b') return 'black';
  return '';
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
    pieceLink.dataset.mgwCheckersLivePieces = 'mvp19-6-store-parity-v3';
    pieceLink.href = new URL('../../css/games/checkers/live-pieces-store-parity-v1.css?v=3&mvp19_6=king-brand-live', import.meta.url).href;
    document.head.appendChild(pieceLink);
  }

  if (!document.querySelector('link[data-mgw-checkers-live-effects]')) {
    const effectLink = document.createElement('link');
    effectLink.rel = 'stylesheet';
    effectLink.dataset.mgwCheckersLiveEffects = 'mvp19-6-store-parity-v5-real-flight';
    effectLink.href = new URL('../../css/games/checkers/live-effects-store-parity-v1.css?v=5&mvp19_6=real-piece-flip-exempt-v1', import.meta.url).href;
    document.head.appendChild(effectLink);
  }
}
