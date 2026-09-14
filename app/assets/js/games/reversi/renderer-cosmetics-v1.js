import {
  renderReversiSurface as renderBaseReversiSurface,
  reversiMeta,
  reversiPlayerMark,
  reversiStatus,
} from './renderer.js?v=66&base=mvp14r-accepted';

const FIELD_PREFIX = 'game-reversi-field-';
const PIECES_PREFIX = 'game-reversi-pieces-';
const EFFECT_IDS = new Set([
  'game-reversi-effect-placement',
  'game-reversi-effect-line',
  'game-reversi-effect-mass-flip',
]);
const cosmeticsByGamePlayer = new Map();

ensureLiveCosmeticStyles();

export { reversiMeta, reversiPlayerMark, reversiStatus };

export function renderReversiSurface(args){
  const { game, me, container } = args || {};
  cachePlayerCosmetics(game);
  renderBaseReversiSurface(args);
  decorateLiveReversi({ game, me, container });
}

function cachePlayerCosmetics(game){
  const gameId = String(game?.id || '');
  if (!gameId) return;
  const players = Array.isArray(game?.players) ? game.players : [];
  players.forEach(player => {
    const playerId = String(player?.id || '');
    const slots = player?.game_cosmetics?.slots;
    if (!playerId || !slots || typeof slots !== 'object') return;
    cosmeticsByGamePlayer.set(`${gameId}:${playerId}`, { ...slots });
  });
}

function decorateLiveReversi({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;
  const board = container.querySelector('.reversi-board');
  if (!(board instanceof HTMLElement)) return;

  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const myId = String(me?.id || '');
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const blackPlayer = players.find(player => String(player?.side || '') === 'black') || null;
  const whitePlayer = players.find(player => String(player?.side || '') === 'white') || null;

  // Match the accepted Checkers convention: the shared field theme belongs to
  // the current viewer. Black/white disc materials remain owned by the actual
  // black/white players, so both players' purchased piece cosmetics are visible.
  const theme = fieldVariant(gameId, viewer);
  const blackPieces = piecesVariant(gameId, blackPlayer);
  const whitePieces = piecesVariant(gameId, whitePlayer);

  container.dataset.mgwReversiLiveCosmetics = '1';
  container.dataset.reversiTheme = theme;
  container.dataset.reversiBlackPieces = blackPieces;
  container.dataset.reversiWhitePieces = whitePieces;

  clearPaidEffectMarks(container);
  if (!container.classList.contains('is-animating')) return;

  const mover = moverPlayer(game, players);
  const effectId = effectForPlayer(gameId, mover);
  if (!EFFECT_IDS.has(effectId)) return;

  const size = Number(game?.board_size || 8);
  const placedCell = integerCell(game?.last_move?.cell, size);
  const flipped = uniqueCells(game?.last_flipped_cells, size);

  if (effectId === 'game-reversi-effect-placement') {
    const cell = cellElement(container, placedCell);
    if (cell) cell.dataset.mgwReversiFx = 'placement';
    return;
  }

  const variant = effectId === 'game-reversi-effect-line' ? 'line' : 'mass-flip';
  flipped
    .sort((a, b) => distanceFrom(a, placedCell, size) - distanceFrom(b, placedCell, size))
    .forEach((cellIndex, index) => {
      const cell = cellElement(container, cellIndex);
      if (!cell) return;
      cell.dataset.mgwReversiFx = variant;
      cell.style.setProperty('--mgw-rv-fx-step', String(index));
    });
}

function clearPaidEffectMarks(container){
  container.querySelectorAll('[data-mgw-reversi-fx]').forEach(cell => {
    if (!(cell instanceof HTMLElement)) return;
    delete cell.dataset.mgwReversiFx;
    cell.style.removeProperty('--mgw-rv-fx-step');
  });
}

function moverPlayer(game, players){
  const playerId = String(game?.last_move?.player_id || '');
  if (playerId) {
    const byId = players.find(player => String(player?.id || '') === playerId);
    if (byId) return byId;
  }
  const side = String(game?.last_move?.side || '');
  return players.find(player => String(player?.side || '') === side) || null;
}

function slotsFor(gameId, player){
  const direct = player?.game_cosmetics?.slots;
  if (direct && typeof direct === 'object') return direct;
  const playerId = String(player?.id || '');
  if (!gameId || !playerId) return {};
  return cosmeticsByGamePlayer.get(`${gameId}:${playerId}`) || {};
}

function fieldVariant(gameId, player){
  return variantFromItem(slotsFor(gameId, player).game_reversi_theme, FIELD_PREFIX);
}

function piecesVariant(gameId, player){
  return variantFromItem(slotsFor(gameId, player).game_reversi_elements, PIECES_PREFIX);
}

function effectForPlayer(gameId, player){
  const itemId = String(slotsFor(gameId, player).game_reversi_effect || '');
  return EFFECT_IDS.has(itemId) ? itemId : '';
}

function variantFromItem(value, prefix){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return variant || 'base';
}

function uniqueCells(value, size){
  const max = Number.isInteger(size) && size > 0 ? size * size : 64;
  return [...new Set((Array.isArray(value) ? value : []).map(Number))]
    .filter(cell => Number.isInteger(cell) && cell >= 0 && cell < max);
}

function integerCell(value, size){
  const cell = Number(value);
  const max = Number.isInteger(size) && size > 0 ? size * size : 64;
  return Number.isInteger(cell) && cell >= 0 && cell < max ? cell : -1;
}

function cellElement(container, cell){
  if (!(cell >= 0)) return null;
  const element = container.querySelector(`[data-reversi-cell="${cell}"]`);
  return element instanceof HTMLElement ? element : null;
}

function distanceFrom(cell, origin, size){
  if (!(origin >= 0) || !(size > 0)) return cell;
  return Math.max(
    Math.abs(Math.floor(cell / size) - Math.floor(origin / size)),
    Math.abs((cell % size) - (origin % size)),
  );
}

function ensureLiveCosmeticStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/reversi/live-cosmetics-v1.css?v=1&mvp19_7=store-parity-real-events-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-reversi-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwReversiLiveCosmetics = 'mvp19-7-live-v1';
  link.href = href;
  document.head.appendChild(link);
}
