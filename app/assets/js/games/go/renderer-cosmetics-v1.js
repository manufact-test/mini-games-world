import {
  renderGoSurface as renderBaseGoSurface,
  goMeta,
  goPlayerMark,
  goStatus,
} from './renderer.js?v=70&base=mvp11-accepted';

const BOARD_PREFIX = 'game-go-board-';
const STONES_PREFIX = 'game-go-stones-';
const EFFECT_IDS = new Set([
  'game-go-effect-placement',
  'game-go-effect-group-capture',
  'game-go-effect-territory-finish',
]);
const cosmeticsByGamePlayer = new Map();

ensureLiveCosmeticStyles();

export { goMeta, goPlayerMark, goStatus };

export function renderGoSurface(args){
  const { game, me, container } = args || {};
  cachePlayerCosmetics(game);
  renderBaseGoSurface(args);
  decorateLiveGo({ game, me, container });
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

function decorateLiveGo({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;
  const board = container.querySelector('.go-board');
  if (!(board instanceof HTMLElement)) return;

  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const myId = String(me?.id || '');
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const blackPlayer = players.find(player => normalizeSide(player?.side) === 'black') || null;
  const whitePlayer = players.find(player => normalizeSide(player?.side) === 'white') || null;
  const presentationOwner = viewer || blackPlayer || whitePlayer || players[0] || null;

  container.dataset.mgwGoLiveCosmetics = '1';
  container.dataset.goTheme = boardVariant(gameId, presentationOwner);
  container.dataset.goStones = stonesVariant(gameId, presentationOwner);
  clearPaidEffectMarks(container);

  const mover = moverPlayer(game, players);
  const effectId = effectForPlayer(gameId, mover);

  if (container.classList.contains('is-animating') && String(game?.last_move?.type || '') === 'place') {
    const size = Number(game?.board_size || 9);
    const placedCell = integerCell(game?.last_move?.cell, size);
    const captured = uniqueCells(game?.last_captured_cells, size);

    if (effectId === 'game-go-effect-placement') {
      const point = pointElement(container, placedCell);
      if (point) point.dataset.mgwGoFx = 'placement';
      return;
    }

    if (effectId === 'game-go-effect-group-capture' && captured.length > 0) {
      captured.forEach((cell, index) => {
        const point = pointElement(container, cell);
        if (!point) return;
        point.dataset.mgwGoFx = 'group-capture';
        point.style.setProperty('--mgw-go-fx-step', String(index));
      });
      return;
    }
  }

  if (
    String(game?.status || '') === 'finished'
    && game?.final_score
    && effectId === 'game-go-effect-territory-finish'
  ) {
    board.dataset.mgwGoFx = 'territory-finish';
    board.dataset.mgwGoSeal = 'MGW';
    container.querySelectorAll('.go-point.territory-black,.go-point.territory-white,.go-point.territory-neutral')
      .forEach((point, index) => {
        if (!(point instanceof HTMLElement)) return;
        point.dataset.mgwGoTerritoryFx = '1';
        point.style.setProperty('--mgw-go-territory-step', String(index));
      });
  }
}

function clearPaidEffectMarks(container){
  container.querySelectorAll('[data-mgw-go-fx]').forEach(element => {
    if (!(element instanceof HTMLElement)) return;
    delete element.dataset.mgwGoFx;
    element.style.removeProperty('--mgw-go-fx-step');
  });
  container.querySelectorAll('[data-mgw-go-territory-fx]').forEach(element => {
    if (!(element instanceof HTMLElement)) return;
    delete element.dataset.mgwGoTerritoryFx;
    element.style.removeProperty('--mgw-go-territory-step');
  });
  const board = container.querySelector('.go-board');
  if (board instanceof HTMLElement) {
    delete board.dataset.mgwGoFx;
    delete board.dataset.mgwGoSeal;
  }
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

  const turnId = String(game?.turn || '');
  if (turnId && players.length === 2) {
    return players.find(player => String(player?.id || '') !== turnId) || null;
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

function boardVariant(gameId, player){
  return variantFromItem(slotsFor(gameId, player).game_go_theme, BOARD_PREFIX);
}

function stonesVariant(gameId, player){
  return variantFromItem(slotsFor(gameId, player).game_go_elements, STONES_PREFIX);
}

function effectForPlayer(gameId, player){
  const itemId = String(slotsFor(gameId, player).game_go_effect || '');
  return EFFECT_IDS.has(itemId) ? itemId : '';
}

function variantFromItem(value, prefix){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return variant || 'base';
}

function uniqueCells(value, size){
  const max = Number.isInteger(size) && size > 0 ? size * size : 81;
  return [...new Set((Array.isArray(value) ? value : []).map(Number))]
    .filter(cell => Number.isInteger(cell) && cell >= 0 && cell < max);
}

function integerCell(value, size){
  const cell = Number(value);
  const max = Number.isInteger(size) && size > 0 ? size * size : 81;
  return Number.isInteger(cell) && cell >= 0 && cell < max ? cell : -1;
}

function pointElement(container, cell){
  if (!(cell >= 0)) return null;
  const point = container.querySelector(`[data-go-cell="${cell}"]`);
  return point instanceof HTMLElement ? point : null;
}

function ensureLiveCosmeticStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/go/live-cosmetics-v1.css?v=1&mvp19_8=live-cosmetics-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-go-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwGoLiveCosmetics = 'mvp19-8-live-v1';
  link.href = href;
  document.head.appendChild(link);
}
