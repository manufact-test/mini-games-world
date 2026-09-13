import {
  renderCheckersSurface as renderLiveCheckersSurface,
  checkersMeta,
  checkersPlayerMark,
  checkersStatus,
} from './renderer-live-effects-v1.js?v=14&mvp19_6=runtime-smoothing-v13&cascade=real-flight-v1&pieces=king-brand-v3&events=move-through-capture-v4&landing=real-piece-flip-final-rect-v2&grid_rows=equal-v1&selection=geometry-neutral-v1&last_from=flat-v1&legend=stable-paint-v1&mobile=insets-v1&promotion=authoritative-only-v1';

export { checkersMeta, checkersPlayerMark, checkersStatus };

/*
 * MVP-19.6 single-flight continuity owner.
 *
 * The accepted real-piece FLIP fixed the final landing handoff, but the frozen
 * Checkers renderer still replaces the entire surface through container.innerHTML
 * on every snapshot. During optimistic -> authoritative confirmation that destroys
 * the physical checker whose CSS animation is already in flight and creates a new
 * checker that resumes from a negative delay. On real devices that DOM replacement
 * is visible as the large mid-flight jump reported after PR #1379.
 *
 * Rule: one factual move owns one physical moving checker for the whole timeline.
 * While that exact checker is active, duplicate snapshots for the same from -> to
 * are presentation no-ops. Rejected/different moves, terminal state, a new chain
 * move, or an expired FLIP fall through to the normal renderer immediately.
 */
export function renderCheckersSurface(args){
  const game = args?.game || null;
  const container = args?.container || null;

  if (shouldPreserveSingleFlight(game, container)) {
    container.dataset.mgwCheckersSingleFlight = '1';
    return;
  }

  if (container instanceof HTMLElement) {
    delete container.dataset.mgwCheckersSingleFlight;
  }
  renderLiveCheckersSurface(args);
}

function shouldPreserveSingleFlight(game, container){
  if (!(container instanceof HTMLElement) || !container.isConnected) return false;
  if (String(game?.status || '') !== 'active') return false;
  if (container.dataset.mgwCheckersRealMove !== '1') return false;

  const movingPiece = container.querySelector('.checkers-piece.mgw-checkers-live-real-move-piece');
  if (!(movingPiece instanceof HTMLElement)) return false;

  const activeMove = activeMoveCoordinates(movingPiece);
  const incomingMove = incomingMoveCoordinates(game);
  if (!activeMove || !incomingMove) return false;
  if (activeMove.from !== incomingMove.from || activeMove.to !== incomingMove.to) return false;

  const destinationCell = movingPiece.closest('[data-checkers-cell]');
  if (!(destinationCell instanceof HTMLElement)) return false;
  if (integerOrNull(destinationCell.dataset.checkersCell) !== activeMove.to) return false;

  return true;
}

function incomingMoveCoordinates(game){
  const pending = game?.__mgw_v100_pending_action || null;
  const pendingFrom = integerOrNull(pending?.from);
  const pendingTo = integerOrNull(pending?.to);
  if (pendingFrom !== null && pendingTo !== null) {
    return { from:pendingFrom, to:pendingTo };
  }

  const move = game?.last_move || null;
  const from = integerOrNull(move?.from);
  const to = integerOrNull(move?.to);
  return from !== null && to !== null ? { from, to } : null;
}

function activeMoveCoordinates(piece){
  const signature = String(piece?.dataset?.mgwRealMove || '');
  const parts = signature.split(':');

  if (parts[0] === 'pending' && parts.length >= 5) {
    const from = integerOrNull(parts[parts.length - 2]);
    const to = integerOrNull(parts[parts.length - 1]);
    return from !== null && to !== null ? { from, to } : null;
  }

  if (parts[0] === 'authoritative' && parts.length >= 6) {
    const from = integerOrNull(parts[4]);
    const to = integerOrNull(parts[5]);
    return from !== null && to !== null ? { from, to } : null;
  }

  return null;
}

function integerOrNull(value){
  if (value === null || value === undefined || value === '') return null;
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric >= 0 && numeric < 64 ? numeric : null;
}
