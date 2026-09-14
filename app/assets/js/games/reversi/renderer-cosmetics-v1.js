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
ensureTelegramHeightFitStyles();

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
  const blackPlayer = players.find(player => normalizeSide(player?.side) === 'black') || null;
  const whitePlayer = players.find(player => normalizeSide(player?.side) === 'white') || null;
  const presentationOwner = viewer || blackPlayer || whitePlayer || players[0] || null;

  // Store defines one Reversi piece SKU as a complete black+white material set.
  // Project the viewer's selected SKU onto both colors so live gameplay is the
  // same product the player bought and saw in Store/Profile.
  const theme = fieldVariant(gameId, presentationOwner);
  const pieces = piecesVariant(gameId, presentationOwner);

  container.dataset.mgwReversiLiveCosmetics = '2';
  container.dataset.reversiTheme = theme;
  container.dataset.reversiBlackPieces = pieces;
  container.dataset.reversiWhitePieces = pieces;

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

  const side = normalizeSide(game?.last_move?.side);
  if (side) {
    const bySide = players.find(player => normalizeSide(player?.side) === side);
    if (bySide) return bySide;
  }

  // Some authoritative snapshots expose the next turn but omit mover id/side.
  // With exactly two players, the mover is the other player after a completed move.
  const turnId = String(game?.turn || '');
  if (turnId && players.length === 2) {
    const other = players.find(player => String(player?.id || '') !== turnId);
    if (other) return other;
  }

  return null;
}

function normalizeSide(value){
  const side = String(value || '').trim().toLowerCase();
  if (side === 'black' || side === 'b') return 'black';
  if (side === 'white' || side === 'w') return 'white';
  return '';
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
  const href = new URL('../../../css/games/reversi/live-cosmetics-v1.css?v=2&mvp19_7=store-exact-real-events-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-reversi-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwReversiLiveCosmetics = 'mvp19-7-live-v2';
  link.href = href;
  document.head.appendChild(link);
}

function ensureTelegramHeightFitStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/reversi/telegram-height-fit-v1.css?v=1&mvp19_7=footer-menu-height-fit-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-reversi-height-fit]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwReversiHeightFit = 'mvp19-7-height-fit-v1';
  link.href = href;
  document.head.appendChild(link);
}
