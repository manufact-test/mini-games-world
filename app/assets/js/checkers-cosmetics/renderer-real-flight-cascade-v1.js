import {
  renderCheckersSurface as renderSingleFlightSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-single-flight-v1.js?v=2&mvp19_6=single-flight-dom-v2&cascade=real-flight-v1';

const EVENT_FLIGHT_DURATION_MS = 1820;
const EVENT_FLIGHT_STATE_TTL_MS = 1940;
const eventFlightStates = new Map();

ensureAllEffectFlightStyles();

export { checkersMeta, checkersPlayerMark, checkersStatus };

/*
 * MVP-19.6 all-paid-effect flight completion owner.
 *
 * Ordinary Move already uses the accepted real-board-piece FLIP in
 * renderer-board-themes. Capture and Promotion used to keep the detached moving
 * checker and then hand it back to a separately rendered board checker. That
 * second physical node is the remaining landing twitch.
 *
 * This wrapper leaves Move untouched and applies the same invariant to Capture
 * and Promotion: capture the real source checker before render, animate the REAL
 * destination checker after render, remove only the duplicate moving overlay
 * checker, and keep each effect's accepted trail/burst/crown decoration.
 */
export function renderCheckersSurface(args){
  const game = args?.game || null;
  const me = args?.me || null;
  const container = args?.container || null;

  captureEventFlightOrigin({ game, me, container });
  renderSingleFlightSurface(args);
  syncEventFlightDestination({ game, container });
}

function captureEventFlightOrigin({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;

  const candidate = eventFlightCandidate(game, me);
  if (!candidate) return;

  const gameKey = String(game?.id || 'local-checkers');
  const existing = eventFlightStates.get(gameKey) || null;
  if (existing && existing.signature === candidate.signature && !eventFlightExpired(existing)) return;

  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;

  const sourceCell = board.querySelector(`[data-checkers-cell="${candidate.from}"]`);
  const sourcePiece = sourceCell?.querySelector('.checkers-piece');
  if (!(sourcePiece instanceof HTMLElement)) {
    // Optimistic -> authoritative confirmation sees the source already empty.
    // Keep the physical in-flight checker instead of starting a second flight.
    return;
  }

  const kind = eventFlightKind({ game, candidate, sourcePiece });
  if (!kind) return;

  const sourceRect = sourcePiece.getBoundingClientRect();
  if (!(sourceRect.width > 0) || !(sourceRect.height > 0)) return;

  if (existing) clearEventFlightState(gameKey, existing);

  const state = {
    signature:candidate.signature,
    kind,
    from:candidate.from,
    to:candidate.to,
    sourceRect:rectSnapshot(sourceRect),
    finalDestinationRect:null,
    startedAt:performance.now(),
    container,
    piece:null,
    timer:0,
  };

  state.timer = window.setTimeout(() => {
    const active = eventFlightStates.get(gameKey);
    if (active !== state) return;
    clearEventFlightState(gameKey, active);
  }, EVENT_FLIGHT_STATE_TTL_MS);

  eventFlightStates.set(gameKey, state);
}

function eventFlightCandidate(game, me){
  const gameKey = String(game?.id || 'local-checkers');
  const pending = game?.__mgw_v100_pending_action || null;
  const pendingFrom = integerOrNull(pending?.from);
  const pendingTo = integerOrNull(pending?.to);

  if (pendingFrom !== null && pendingTo !== null) {
    const moverId = String(me?.id || '');
    const effectId = checkersEffectIdForPlayer(game, moverId);
    if (!['game-checkers-effect-capture','game-checkers-effect-promotion'].includes(effectId)) return null;
    return {
      from:pendingFrom,
      to:pendingTo,
      moverId,
      effectId,
      signature:`pending:${gameKey}:${moverId}:${pendingFrom}:${pendingTo}`,
    };
  }

  const move = game?.last_move || null;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  if (from === null || to === null) return null;

  const moverId = String(move?.player_id || '');
  const side = String(move?.side || '');
  const effectId = checkersEffectIdForPlayer(game, moverId, side);
  if (!['game-checkers-effect-capture','game-checkers-effect-promotion'].includes(effectId)) return null;

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
    moverId,
    effectId,
    signature:`authoritative:${moveSignature}`,
  };
}

function eventFlightKind({ game, candidate, sourcePiece }){
  if (candidate.effectId === 'game-checkers-effect-capture') {
    return game?.last_move?.capture === true ? 'capture' : '';
  }

  if (candidate.effectId !== 'game-checkers-effect-promotion') return '';

  // Optimistic checkers marks every already-crowned king move as promoted=true.
  // Fresh promotion is therefore defined from the physical source checker plus the
  // destination board state, matching the live-effect owner's authoritative rule.
  const sourceWasKing = sourcePiece.classList.contains('king');
  if (sourceWasKing) return '';

  const destinationPiece = String(Array.isArray(game?.board) ? game.board[candidate.to] || '' : '');
  const destinationIsKing = destinationPiece === 'W' || destinationPiece === 'B';
  const promotionCell = integerOrNull(game?.last_promotion);
  return destinationIsKing || promotionCell === candidate.to ? 'promotion' : '';
}

function syncEventFlightDestination({ game, container }){
  if (!(container instanceof HTMLElement)) return;
  const gameKey = String(game?.id || 'local-checkers');
  const state = eventFlightStates.get(gameKey) || null;
  if (!state) return;

  if (eventFlightExpired(state)) {
    clearEventFlightState(gameKey, state);
    return;
  }

  const board = container.querySelector('.checkers-board');
  if (!(board instanceof HTMLElement)) return;
  const destinationCell = board.querySelector(`[data-checkers-cell="${state.to}"]`);
  const destinationPiece = destinationCell?.querySelector('.checkers-piece');
  if (!(destinationPiece instanceof HTMLElement)) return;

  if (state.piece === destinationPiece
    && destinationPiece.dataset.mgwRealMove === state.signature
    && destinationPiece.classList.contains('mgw-checkers-live-real-move-piece')) {
    takeOverEventOverlay({ board, destinationCell, state });
    return;
  }

  // Read the destination before applying any transform. This is the exact layout
  // box that remains after the effect and therefore the only legal landing owner.
  const destinationRect = destinationPiece.getBoundingClientRect();
  if (!(destinationRect.width > 0) || !(destinationRect.height > 0)) return;
  state.finalDestinationRect = rectSnapshot(destinationRect);

  const sourceCenterX = state.sourceRect.left + state.sourceRect.width / 2;
  const sourceCenterY = state.sourceRect.top + state.sourceRect.height / 2;
  const destinationCenterX = destinationRect.left + destinationRect.width / 2;
  const destinationCenterY = destinationRect.top + destinationRect.height / 2;
  const dx = sourceCenterX - destinationCenterX;
  const dy = sourceCenterY - destinationCenterY;
  const scale = destinationRect.width > 0
    ? Math.max(.85, Math.min(1.15, state.sourceRect.width / destinationRect.width))
    : 1;
  const elapsed = Math.max(0, Math.min(EVENT_FLIGHT_DURATION_MS, performance.now() - state.startedAt));

  destinationPiece.classList.add('mgw-checkers-live-real-move-piece');
  destinationPiece.style.setProperty('--mgw-real-move-dx', `${dx}px`);
  destinationPiece.style.setProperty('--mgw-real-move-dy', `${dy}px`);
  destinationPiece.style.setProperty('--mgw-real-move-scale', String(scale));
  destinationPiece.style.setProperty('--mgw-real-move-delay', `${-elapsed}ms`);
  destinationPiece.dataset.mgwRealMove = state.signature;
  destinationPiece.dataset.mgwRealMoveKind = state.kind;
  state.piece = destinationPiece;

  container.dataset.mgwCheckersRealMove = '1';
  takeOverEventOverlay({ board, destinationCell, state });
}

function takeOverEventOverlay({ board, destinationCell, state }){
  if (destinationCell instanceof HTMLElement) {
    destinationCell.classList.remove('mgw-checkers-live-fx-hide-piece');
  }

  const layer = nearestLiveEffectLayer(board);
  if (!(layer instanceof HTMLElement) || !layer.classList.contains(`mgw-checkers-live-fx-${state.kind}`)) return;

  const duplicatePiece = layer.querySelector('.mgw-checkers-live-fx-piece');
  if (duplicatePiece instanceof HTMLElement) duplicatePiece.remove();

  alignEventFlightPath(layer, board, state);
  layer.dataset.mgwMovePieceOwner = `real-board-piece-${state.kind}-flip-v1`;
}

function alignEventFlightPath(layer, board, state){
  if (!(layer instanceof HTMLElement) || !(board instanceof HTMLElement)) return;
  const finalRect = state.finalDestinationRect;
  if (!finalRect || !(finalRect.width > 0) || !(finalRect.height > 0)) return;

  const boardRect = board.getBoundingClientRect();
  const fromX = state.sourceRect.left - boardRect.left + state.sourceRect.width / 2;
  const fromY = state.sourceRect.top - boardRect.top + state.sourceRect.height / 2;
  const toX = finalRect.left - boardRect.left + finalRect.width / 2;
  const toY = finalRect.top - boardRect.top + finalRect.height / 2;
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

function clearEventFlightState(gameKey, state){
  if (state?.timer) clearTimeout(state.timer);

  const piece = state?.piece;
  if (piece instanceof HTMLElement && piece.dataset.mgwRealMove === state.signature) {
    piece.classList.remove('mgw-checkers-live-real-move-piece');
    piece.style.removeProperty('--mgw-real-move-dx');
    piece.style.removeProperty('--mgw-real-move-dy');
    piece.style.removeProperty('--mgw-real-move-scale');
    piece.style.removeProperty('--mgw-real-move-delay');
    delete piece.dataset.mgwRealMove;
    delete piece.dataset.mgwRealMoveKind;
  }

  const container = state?.container;
  if (container instanceof HTMLElement && !container.querySelector('.mgw-checkers-live-real-move-piece')) {
    delete container.dataset.mgwCheckersRealMove;
  }

  if (eventFlightStates.get(gameKey) === state) eventFlightStates.delete(gameKey);
}

function eventFlightExpired(state){
  return !state || performance.now() - Number(state.startedAt || 0) > EVENT_FLIGHT_STATE_TTL_MS;
}

function rectSnapshot(rect){
  return {
    left:Number(rect.left),
    top:Number(rect.top),
    width:Number(rect.width),
    height:Number(rect.height),
  };
}

function integerOrNull(value){
  if (value === null || value === undefined || value === '') return null;
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric >= 0 && numeric < 64 ? numeric : null;
}

function ensureAllEffectFlightStyles(){
  const href = new URL('../../css/games/checkers/live-effects-store-parity-v1.css?v=6&mvp19_6=all-effects-real-flight-v1&move=trail-only-v1', import.meta.url).href;
  const apply = () => {
    const existing = document.querySelector('link[data-mgw-checkers-live-effects]');
    if (!(existing instanceof HTMLLinkElement)) return false;
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwCheckersLiveEffects = 'mvp19-6-all-effects-real-flight-v6';
    return true;
  };

  if (apply()) return;
  queueMicrotask(apply);
}
