import {
  renderCheckersSurface as renderBaseCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from '../games/checkers/renderer.js?v=57&base=mvp16-accepted';

const REAL_MOVE_DURATION_MS = 1780;
const REAL_MOVE_STATE_TTL_MS = 1940;
const realMoveStates = new Map();

ensureCheckersCosmeticStyles();
ensureCheckersRuntimeCorrectiveStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

export function renderCheckersSurface({ game, me, container, onAction }){
  /*
   * New landing owner for the ordinary paid Move effect.
   *
   * The failed chain (#1375 -> #1377) kept animating a detached checker and then
   * tried increasingly precise ways to hand it off to the real board checker.
   * That architecture is the bug surface: two different DOM elements/compositor
   * paths must agree on the same final raster pixel.
   *
   * Capture the source checker BEFORE the frozen renderer replaces the board.
   * After render, animate the REAL destination checker itself from that captured
   * source rect back to its own layout-owned final position (FLIP). The detached
   * live-effects owner still supplies the accepted trail/ring, but its duplicate
   * moving checker is removed before paint. There is therefore no checker-to-checker
   * handoff at all: the final animation frame and the settled checker are one node.
   */
  captureRealMoveOrigin({ game, me, container });

  /* Immutable legend: preserve one physical node across optimistic/authoritative
   * snapshots so the already-accepted muted-label paint fix stays closed. */
  const stableLegend = container.querySelector('.checkers-legend');

  renderBaseCheckersSurface({ game, me, container, onAction });

  const renderedLegend = container.querySelector('.checkers-legend');
  if (stableLegend instanceof HTMLElement && renderedLegend instanceof HTMLElement && stableLegend !== renderedLegend) {
    renderedLegend.replaceWith(stableLegend);
  }

  container.dataset.checkersTheme = checkersBoardVariant(game, me);
  container.dataset.mgwCheckersPaidEffect = viewerHasPaidCheckersEffect(game, me) ? '1' : '0';

  syncRealMoveDestination({ game, container });
}

function checkersBoardVariant(game, me){
  const players = Array.isArray(game?.players) ? game.players : [];
  const viewer = players.find(player => String(player?.id || '') === String(me?.id || '')) || null;
  const slots = viewer?.game_cosmetics?.slots;
  const itemId = slots && typeof slots === 'object' ? String(slots.game_checkers_theme || '') : '';
  const marker = 'game-checkers-board-';
  return itemId.startsWith(marker) ? itemId.slice(marker.length) : 'base';
}

function viewerHasPaidCheckersEffect(game, me){
  return [
    'game-checkers-effect-move',
    'game-checkers-effect-capture',
    'game-checkers-effect-promotion',
  ].includes(checkersEffectIdForPlayer(game, me?.id));
}

function checkersEffectIdForPlayer(game, playerId, fallbackSide = ''){
  const players = Array.isArray(game?.players) ? game.players : [];
  const byId = playerId
    ? players.find(player => String(player?.id || '') === String(playerId || ''))
    : null;
  const bySide = !byId && fallbackSide
    ? players.find(player => String(player?.side || '') === String(fallbackSide))
    : null;
  const player = byId || bySide || null;
  const slots = player?.game_cosmetics?.slots;
  return slots && typeof slots === 'object' ? String(slots.game_checkers_effect || '') : '';
}

function captureRealMoveOrigin({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;

  const candidate = realMoveCandidate(game, me);
  if (!candidate || candidate.effectId !== 'game-checkers-effect-move') return;

  const gameKey = String(game?.id || 'local-checkers');
  const existing = realMoveStates.get(gameKey) || null;
  if (existing && existing.signature === candidate.signature && !realMoveExpired(existing)) return;

  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;

  const sourceCell = board.querySelector(`[data-checkers-cell="${candidate.from}"]`);
  const sourcePiece = sourceCell?.querySelector('.checkers-piece');
  if (!(sourcePiece instanceof HTMLElement)) {
    // Authoritative confirmation of our own optimistic move sees the source cell
    // already empty. Keep the existing FLIP state rather than starting a second one.
    return;
  }

  const sourceRect = sourcePiece.getBoundingClientRect();
  if (!(sourceRect.width > 0) || !(sourceRect.height > 0)) return;

  if (existing) clearRealMoveState(gameKey, existing);

  const state = {
    signature:candidate.signature,
    from:candidate.from,
    to:candidate.to,
    sourceRect:rectSnapshot(sourceRect),
    startedAt:performance.now(),
    container,
    timer:0,
    renderRevision:0,
  };
  state.timer = window.setTimeout(() => {
    const active = realMoveStates.get(gameKey);
    if (active !== state) return;
    clearRealMoveState(gameKey, active);
  }, REAL_MOVE_STATE_TTL_MS);

  realMoveStates.set(gameKey, state);
}

function realMoveCandidate(game, me){
  const gameKey = String(game?.id || 'local-checkers');
  const pending = game?.__mgw_v100_pending_action || null;
  const pendingFrom = integerOrNull(pending?.from);
  const pendingTo = integerOrNull(pending?.to);

  if (pendingFrom !== null && pendingTo !== null) {
    const moverId = String(me?.id || '');
    return {
      from:pendingFrom,
      to:pendingTo,
      effectId:checkersEffectIdForPlayer(game, moverId),
      signature:`pending:${gameKey}:${moverId}:${pendingFrom}:${pendingTo}`,
    };
  }

  const move = game?.last_move || null;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  if (from === null || to === null) return null;

  const moverId = String(move?.player_id || '');
  const side = String(move?.side || '');
  const moveSignature = [
    gameKey,
    moverId,
    side,
    from,
    to,
    move?.capture === true ? 'capture' : 'move',
    String(move?.captured ?? ''),
    move?.promoted === true ? 'promoted' : '',
    move?.chain_continues === true ? 'chain' : '',
  ].join(':');

  return {
    from,
    to,
    effectId:checkersEffectIdForPlayer(game, moverId, side),
    signature:`authoritative:${moveSignature}`,
  };
}

function syncRealMoveDestination({ game, container }){
  if (!(container instanceof HTMLElement)) return;
  const gameKey = String(game?.id || 'local-checkers');
  const state = realMoveStates.get(gameKey) || null;

  clearStaleRealMovePieces(container);

  if (!state || realMoveExpired(state)) {
    if (state) clearRealMoveState(gameKey, state);
    return;
  }

  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;
  const destinationCell = board.querySelector(`[data-checkers-cell="${state.to}"]`);
  const destinationPiece = destinationCell?.querySelector('.checkers-piece');
  if (!(destinationPiece instanceof HTMLElement)) return;

  // Read the destination BEFORE applying our animation class. This is the final
  // CSS-Grid-owned border box that must remain after the effect has finished.
  const destinationRect = destinationPiece.getBoundingClientRect();
  if (!(destinationRect.width > 0) || !(destinationRect.height > 0)) return;
  const finalDestinationRect = rectSnapshot(destinationRect);
  state.renderRevision = Number(state.renderRevision || 0) + 1;
  const renderRevision = state.renderRevision;

  const sourceCenterX = state.sourceRect.left + state.sourceRect.width / 2;
  const sourceCenterY = state.sourceRect.top + state.sourceRect.height / 2;
  const destinationCenterX = destinationRect.left + destinationRect.width / 2;
  const destinationCenterY = destinationRect.top + destinationRect.height / 2;
  const dx = sourceCenterX - destinationCenterX;
  const dy = sourceCenterY - destinationCenterY;
  const scale = destinationRect.width > 0
    ? Math.max(.85, Math.min(1.15, state.sourceRect.width / destinationRect.width))
    : 1;
  const elapsed = Math.max(0, Math.min(REAL_MOVE_DURATION_MS, performance.now() - state.startedAt));

  destinationPiece.classList.add('mgw-checkers-live-real-move-piece');
  destinationPiece.style.setProperty('--mgw-real-move-dx', `${dx}px`);
  destinationPiece.style.setProperty('--mgw-real-move-dy', `${dy}px`);
  destinationPiece.style.setProperty('--mgw-real-move-scale', String(scale));
  destinationPiece.style.setProperty('--mgw-real-move-delay', `${-elapsed}ms`);
  destinationPiece.dataset.mgwRealMove = state.signature;

  container.dataset.mgwCheckersRealMove = '1';

  // The accepted live-effects module runs immediately after this wrapper. Let it
  // keep its trail/ring, then remove only its duplicate flying checker and unmask
  // our real destination checker before the browser gets a paint opportunity.
  queueRealMoveOverlayTakeover({ container, state, finalDestinationRect, renderRevision });
}

function queueRealMoveOverlayTakeover({ container, state, finalDestinationRect, renderRevision }){
  const run = () => {
    if (!(container instanceof HTMLElement) || !container.isConnected) return;
    const gameKey = gameKeyForState(state);
    const active = gameKey ? realMoveStates.get(gameKey) : state;
    if (active !== state || realMoveExpired(state) || state.renderRevision !== renderRevision) return;

    const liveBoard = container.querySelector('.checkers-board');
    if (!(liveBoard instanceof HTMLElement)) return;

    const destinationCell = liveBoard.querySelector(`[data-checkers-cell="${state.to}"]`);
    if (destinationCell instanceof HTMLElement) {
      destinationCell.classList.remove('mgw-checkers-live-fx-hide-piece');
    }

    const layer = nearestLiveEffectLayer(liveBoard);
    if (!(layer instanceof HTMLElement) || !layer.classList.contains('mgw-checkers-live-fx-move')) return;

    const duplicatePiece = layer.querySelector('.mgw-checkers-live-fx-piece');
    if (duplicatePiece instanceof HTMLElement) duplicatePiece.remove();

    alignMoveDecorations(layer, liveBoard, state, finalDestinationRect);
    layer.dataset.mgwMovePieceOwner = 'real-board-piece-flip-v2';
  };

  if (typeof globalThis.queueMicrotask === 'function') globalThis.queueMicrotask(run);
  else Promise.resolve().then(run);
  if (typeof globalThis.requestAnimationFrame === 'function') globalThis.requestAnimationFrame(run);
}

function alignMoveDecorations(layer, board, state, finalDestinationRect){
  if (!(layer instanceof HTMLElement) || !(board instanceof HTMLElement)) return;
  if (!finalDestinationRect || !(finalDestinationRect.width > 0) || !(finalDestinationRect.height > 0)) return;
  const boardRect = board.getBoundingClientRect();

  // IMPORTANT: never re-read the destination piece here. By the time this
  // microtask runs the real checker already carries the FLIP transform, so its
  // getBoundingClientRect() describes the in-flight visual box near the source.
  // Use the final untransformed layout box captured synchronously before the
  // animation class was applied. This keeps trail/ring geometry independent from
  // compositor progress and from optimistic -> authoritative rerenders.
  const fromX = state.sourceRect.left - boardRect.left + state.sourceRect.width / 2;
  const fromY = state.sourceRect.top - boardRect.top + state.sourceRect.height / 2;
  const toX = finalDestinationRect.left - boardRect.left + finalDestinationRect.width / 2;
  const toY = finalDestinationRect.top - boardRect.top + finalDestinationRect.height / 2;
  const dx = toX - fromX;
  const dy = toY - fromY;
  const distance = Math.hypot(dx, dy);
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  const path = layer.querySelector('.mgw-checkers-live-fx-path');
  if (path instanceof HTMLElement) {
    path.style.left = `${fromX}px`;
    path.style.top = `${fromY}px`;
    path.style.width = `${distance}px`;
    path.style.transform = `translateY(-50%) rotate(${angle}deg)`;
  }

  const impact = layer.querySelector('.mgw-checkers-live-fx-impact');
  if (impact instanceof HTMLElement) {
    impact.style.left = `${toX}px`;
    impact.style.top = `${toY}px`;
  }
}

function nearestLiveEffectLayer(board){
  if (!(board instanceof HTMLElement)) return null;
  const boardRect = board.getBoundingClientRect();
  let best = null;
  let bestScore = Infinity;
  document.querySelectorAll('.mgw-checkers-live-fx').forEach(candidate => {
    if (!(candidate instanceof HTMLElement) || !candidate.isConnected) return;
    const rect = candidate.getBoundingClientRect();
    const score = Math.abs(rect.left - boardRect.left)
      + Math.abs(rect.top - boardRect.top)
      + Math.abs(rect.width - boardRect.width)
      + Math.abs(rect.height - boardRect.height);
    if (score < bestScore) {
      best = candidate;
      bestScore = score;
    }
  });
  return bestScore <= 8 ? best : null;
}

function rectSnapshot(rect){
  return {
    left:Number(rect.left),
    top:Number(rect.top),
    width:Number(rect.width),
    height:Number(rect.height),
  };
}

function realMoveExpired(state){
  return !state || performance.now() - Number(state.startedAt || 0) > REAL_MOVE_STATE_TTL_MS;
}

function gameKeyForState(state){
  for (const [key, value] of realMoveStates.entries()) {
    if (value === state) return key;
  }
  return '';
}

function clearRealMoveState(gameKey, state){
  if (state?.timer) clearTimeout(state.timer);
  const container = state?.container;
  if (container instanceof HTMLElement) {
    delete container.dataset.mgwCheckersRealMove;
    clearStaleRealMovePieces(container);
  }
  if (realMoveStates.get(gameKey) === state) realMoveStates.delete(gameKey);
}

function clearStaleRealMovePieces(container){
  if (!(container instanceof HTMLElement)) return;
  container.querySelectorAll('.mgw-checkers-live-real-move-piece').forEach(piece => {
    if (!(piece instanceof HTMLElement)) return;
    piece.classList.remove('mgw-checkers-live-real-move-piece');
    piece.style.removeProperty('--mgw-real-move-dx');
    piece.style.removeProperty('--mgw-real-move-dy');
    piece.style.removeProperty('--mgw-real-move-scale');
    piece.style.removeProperty('--mgw-real-move-delay');
    delete piece.dataset.mgwRealMove;
  });
}

function integerOrNull(value){
  if (value === null || value === undefined || value === '') return null;
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric >= 0 && numeric < 64 ? numeric : null;
}

function ensureCheckersCosmeticStyles(){
  if (document.querySelector('link[data-mgw-checkers-cosmetics]')) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersCosmetics = 'mvp19-6-live-boards';
  link.href = new URL('../../css/games/checkers/cosmetics.css?v=1&mvp19_6=board-themes', import.meta.url).href;
  document.head.appendChild(link);
}

function ensureCheckersRuntimeCorrectiveStyles(){
  const href = new URL('../../css/games/checkers/runtime-handoff-mobile-v1.css?v=7&mvp19_6=real-piece-flip-v1&legend=stable-paint-v1&grid_rows=equal-v1&mobile=insets-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-checkers-runtime-corrective]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-real-piece-flip-v7';
    document.head.appendChild(existing);
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwCheckersRuntimeCorrective = 'mvp19-6-real-piece-flip-v7';
  link.href = href;
  document.head.appendChild(link);
}
