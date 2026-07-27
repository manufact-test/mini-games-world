import { buildOptimisticGame } from './production-cross-game-optimistic.js?v=96';
import { buildTicTacToeOptimistic } from './production-v97-models.js?v=97';
import { buildV102BattleshipSetupOptimistic } from './production-v102-battleship-models.js?v=102';

export function buildV102OptimisticGame(game, action, viewerId, gameType){
  const type = String(gameType || '');
  const id = String(viewerId || '');
  if (!game || !action || !id) return null;

  if (type === 'tictactoe') return buildTicTacToeOptimistic(game, action, id);
  if (type === 'battleship' && String(game?.phase || '') === 'setup') {
    return buildV102BattleshipSetupOptimistic(game, action);
  }

  const modelGame = normalizeSideSymbols(game, type);
  const optimistic = buildOptimisticGame(modelGame, action, id, type);
  if (!optimistic) return null;

  if (type === 'battleship' && String(action?.type || '') === 'fire') {
    const cell = Number(action?.cell);
    if (Number.isInteger(cell) && cell >= 0 && cell < 100) optimistic.pending_fire_cell = cell;
  }

  optimistic.__mgw_v102_pending_action = normalizePendingAction(action);
  return optimistic;
}

export function pendingV102SurfaceDescriptor(game, gameType){
  const type = String(gameType || '');
  const action = game?.__mgw_v102_pending_action || null;

  if (type === 'battleship') {
    const cell = Number(game?.pending_fire_cell ?? action?.cell);
    if (Number.isInteger(cell) && cell >= 0 && cell < 100) {
      return { selector:`[data-battleship-cell="${cell}"]`, className:'mgw-pending-shot' };
    }
  }

  if (type === 'four_in_a_row') {
    const column = Number(action?.column);
    if (Number.isInteger(column)) {
      return { selector:`[data-four-column="${column}"]`, className:'mgw-pending-action' };
    }
  }

  if (type === 'tictactoe') {
    const cell = Number(action?.cell);
    if (Number.isInteger(cell)) {
      return { selector:`[data-game-cell="${cell}"]`, className:'mgw-pending-action' };
    }
  }

  return null;
}

export function invalidateV102InFlightPoll(runtime, gameId){
  const id = String(gameId || '');
  const item = runtime?.games?.get?.(id);
  if (!item) return false;
  item.generation = Number(item.generation || 0) + 1;
  item.interactionGeneration = Number(item.interactionGeneration || 0) + 1;
  return true;
}

function normalizeSideSymbols(game, type){
  if (!['reversi','go'].includes(type) || !Array.isArray(game?.players)) return game;
  return {
    ...game,
    players:game.players.map(player => {
      const side = String(player?.side || '');
      const existing = String(player?.symbol || '');
      const symbol = side === 'black'
        ? 'B'
        : (side === 'white' ? 'W' : (['B','W'].includes(existing) ? existing : ''));
      return { ...player, symbol };
    }),
  };
}

function normalizePendingAction(action){
  if (!action || typeof action !== 'object') return null;
  const result = {};
  for (const key of ['type','cell','column','from','to','tile','side','size','orientation','promotion']) {
    if (action[key] !== undefined) result[key] = action[key];
  }
  return result;
}
