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
const lastBoardByGame = new Map();

ensureLiveCosmeticStyles();

export { goMeta, goPlayerMark, goStatus };

export function renderGoSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const size = normalizeSize(game?.board_size);
  const currentBoard = normalizeSnapshotBoard(game?.board, size);
  const previousBoard = gameId ? String(lastBoardByGame.get(gameId) || '') : '';
  const removedCells = removedBoardCells(previousBoard, currentBoard, size);

  cachePlayerCosmetics(game);
  renderBaseGoSurface(args);
  decorateLiveGo({ game, me, container, size, removedCells });

  if (gameId && currentBoard) lastBoardByGame.set(gameId, currentBoard);
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

function decorateLiveGo({ game, me, container, size, removedCells }){
  if (!(container instanceof HTMLElement)) return;

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
  clearTransientTerritoryMarks(container);

  const mover = moverPlayer(game, players);
  const effectId = effectForPlayer(gameId, mover);
  const isPlacement = String(game?.last_move?.type || '') === 'place';
  const isAnimatedPlacement = container.classList.contains('is-animating') && isPlacement;
  const placedCell = integerCell(game?.last_move?.cell, size);
  const authoritativeCaptured = uniqueCells(game?.last_captured_cells, size);

  if (isAnimatedPlacement && effectId === 'game-go-effect-placement') {
    const point = pointElement(container, placedCell);
    if (point) point.dataset.mgwGoFx = 'placement';
    return;
  }

  if (effectId === 'game-go-effect-group-capture' && isPlacement) {
    const captureCells = authoritativeCaptured.length > 0
      ? authoritativeCaptured
      : removedCells.map(entry => entry.cell);

    if (captureCells.length > 0) {
      applyGroupCapturePresentation({
        container,
        captureCells,
        removedCells,
        animatedByBase:isAnimatedPlacement && authoritativeCaptured.length > 0,
      });
      return;
    }
  }

  const finishEffectId = effectForPlayer(gameId, presentationOwner);
  if (
    String(game?.status || '') === 'finished'
    && game?.final_score
    && (effectId === 'game-go-effect-territory-finish' || finishEffectId === 'game-go-effect-territory-finish')
  ) {
    container.querySelectorAll('.go-point.territory-black,.go-point.territory-white,.go-point.territory-neutral')
      .forEach((point, index) => {
        if (!(point instanceof HTMLElement)) return;
        point.dataset.mgwGoTerritoryFx = '1';
        point.style.setProperty('--mgw-go-territory-step', String(index));
      });
  }
}

function applyGroupCapturePresentation({ container, captureCells, removedCells, animatedByBase }){
  const removedByCell = new Map(removedCells.map(entry => [entry.cell, entry]));

  captureCells.forEach((cell, index) => {
    const point = pointElement(container, cell);
    if (!(point instanceof HTMLElement)) return;

    const sourceStone = point.querySelector('.go-stone');
    point.dataset.mgwGoFx = 'group-capture-v10';
    point.style.setProperty('--mgw-go-fx-step', String(index));

    if (animatedByBase && sourceStone instanceof HTMLElement) {
      return;
    }

    const color = sourceStone?.classList.contains('white')
      ? 'white'
      : (sourceStone?.classList.contains('black')
        ? 'black'
        : (removedByCell.get(cell)?.color || 'black'));

    if (!(sourceStone instanceof HTMLElement)) {
      const ghost = document.createElement('span');
      ghost.className = `go-stone ${color} mgw-go-capture-ghost-v10`;
      ghost.setAttribute('aria-hidden', 'true');
      point.appendChild(ghost);
    }

    point.classList.add('mgw-go-capture-fallback-v10');
  });
}

function clearTransientTerritoryMarks(container){
  container.querySelectorAll('[data-mgw-go-territory-fx]').forEach(element => {
    if (!(element instanceof HTMLElement)) return;
    delete element.dataset.mgwGoTerritoryFx;
    element.style.removeProperty('--mgw-go-territory-step');
  });
  const board = container.querySelector('.go-board');
  if (board instanceof HTMLElement) {
    delete board.dataset.mgwGoFx;
    delete board.dataset.mgwGoSeal;
    delete board.dataset.mgwGoTerritoryQa;
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

function normalizeSize(value){
  const size = Number(value);
  return [9,13].includes(size) ? size : 9;
}

function normalizeSnapshotBoard(value, size){
  const raw = typeof value === 'string' ? value : '';
  if (raw.length !== size * size) return '';
  return Array.from(raw, char => ['B','W','-'].includes(char) ? char : '-').join('');
}

function removedBoardCells(previousBoard, currentBoard, size){
  if (!previousBoard || !currentBoard || previousBoard.length !== size * size || currentBoard.length !== size * size) return [];
  const removed = [];
  for (let cell = 0; cell < size * size; cell += 1) {
    const before = previousBoard[cell];
    const after = currentBoard[cell];
    if ((before === 'B' || before === 'W') && after === '-') {
      removed.push({ cell, color:before === 'B' ? 'black' : 'white' });
    }
  }
  return removed;
}

function ensureLiveCosmeticStyles(){
  if (typeof document === 'undefined') return;
  ensureStylesheet({
    selector:'link[data-mgw-go-live-cosmetics]',
    marker:'mgwGoLiveCosmetics',
    markerValue:'mvp19-8-live-v1',
    href:new URL('../../../css/games/go/live-cosmetics-v1.css?v=1&mvp19_8=live-cosmetics-v1', import.meta.url).href,
  });
  ensureStylesheet({
    selector:'link[data-mgw-go-live-effects-corrective]',
    marker:'mgwGoLiveEffectsCorrective',
    markerValue:'mvp19-8-live-effects-corrective-v2',
    href:new URL('../../../css/games/go/live-effects-corrective-v2.css?v=2&mvp19_8=live-effects-corrective-v2', import.meta.url).href,
  });
  ensureStylesheet({
    selector:'link[data-mgw-go-live-effects-v7-runtime]',
    marker:'mgwGoLiveEffectsV7Runtime',
    markerValue:'mvp19-8-effect2-single-pass-territory-final-v7',
    href:new URL('../../../css/games/go/live-effects-corrective-v7.css?v=1&mvp19_8=effect2-single-pass-territory-final-v7', import.meta.url).href,
  });
  ensureStylesheet({
    selector:'link[data-mgw-go-live-capture-direct-v10-runtime]',
    marker:'mgwGoLiveCaptureDirectV10Runtime',
    markerValue:'mvp19-8-direct-capture-v10',
    href:new URL('../../../css/games/go/live-capture-direct-v10.css?v=1&mvp19_8=direct-capture-v10', import.meta.url).href,
  });
}

function ensureStylesheet({ selector, marker, markerValue, href }){
  const existing = document.querySelector(selector);
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset[marker] = markerValue;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset[marker] = markerValue;
  link.href = href;
  document.head.appendChild(link);
}
