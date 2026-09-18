import {
  renderFourInARowSurface as renderBaseFourInARowSurface,
  fourInARowMeta,
  fourInARowPlayerMark,
} from './renderer.js?v=53&base=mvp19-10-live-v1';
import { state } from '../../state.js?v=27';

const THEME_SLOT = 'game_four_in_a_row_theme';
const ELEMENTS_SLOT = 'game_four_in_a_row_elements';
const EFFECT_SLOT = 'game_four_in_a_row_effect';

const FIELD_PREFIX = 'game-four-field-';
const DISCS_PREFIX = 'game-four-discs-';

const DROP_ID = 'game-four-effect-drop';
const FOUR_ID = 'game-four-effect-four';
const VICTORY_ID = 'game-four-effect-victory-wave';
const EFFECT_IDS = new Set([DROP_ID, FOUR_ID, VICTORY_ID]);
const FIELD_VARIANTS = new Set(['blue', 'dark', 'metal', 'neon']);
const DISC_VARIANTS = new Set(['classic', '3d', 'metal', 'neon']);

const cosmeticsByGamePlayer = new Map();
const seenMoveByGame = new Map();
const activeEffectByGame = new Map();

const EFFECT_DURATION_MS = Object.freeze({
  drop: 720,
  four: 1280,
  victory: 1520,
});

ensureLiveStyles();

export { fourInARowMeta, fourInARowPlayerMark };

export function renderFourInARowSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const myId = String(me?.id || '');

  cachePlayerCosmetics(gameId, players);

  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const presentationOwner = viewer || players[0] || null;
  const presentationSlots = slotsFor(gameId, presentationOwner, me);
  const themeVariant = variantFromItem(presentationSlots[THEME_SLOT], FIELD_PREFIX, FIELD_VARIANTS);
  const discsVariant = variantFromItem(presentationSlots[ELEMENTS_SLOT], DISCS_PREFIX, DISC_VARIANTS);
  const viewerEffect = normalizedEffectId(presentationSlots[EFFECT_SLOT]);

  const columns = normalizeColumns(game);
  const rows = normalizeRows(game, columns);
  const board = normalizeBoard(game?.board, columns, rows);
  const lastMove = normalizeCell(game?.last_move, columns, rows);
  const moveKey = eventSignature(game, board, lastMove);
  const optimistic = Boolean(game?.__mgw_v100_pending_action);
  const alreadyObserved = gameId !== '' && seenMoveByGame.has(gameId);
  const previousMoveKey = gameId ? String(seenMoveByGame.get(gameId) || '') : '';
  const newMove = Boolean(
    gameId
    && moveKey
    && !optimistic
    && alreadyObserved
    && previousMoveKey !== moveKey
  );

  if (gameId && !optimistic) {
    if (!alreadyObserved) {
      seenMoveByGame.set(gameId, moveKey || '__initial__');
    } else if (moveKey) {
      seenMoveByGame.set(gameId, moveKey);
    }
  }

  if (newMove) {
    const mover = moverPlayer(players, board, lastMove);
    const moverIsViewer = String(mover?.id || '') !== '' && String(mover?.id || '') === myId;
    const moverEffect = effectForPlayer(gameId, mover, me) || (moverIsViewer ? viewerEffect : '');
    const winner = winnerPlayer(game, players);
    const winnerIsViewer = String(winner?.id || '') !== '' && String(winner?.id || '') === myId;
    const winnerEffect = effectForPlayer(gameId, winner, me) || (winnerIsViewer ? viewerEffect : '');
    const winningCells = normalizedWinningCells(game?.winning_cells, columns, rows);

    let kind = '';
    let ownerId = '';

    if (
      String(game?.status || '') === 'finished'
      && String(game?.finish_reason || '') === 'normal_win'
      && winningCells.length >= 4
      && winnerEffect === FOUR_ID
    ) {
      kind = 'four';
      ownerId = String(winner?.id || '');
    } else if (
      String(game?.status || '') === 'finished'
      && String(game?.finish_reason || '') === 'normal_win'
      && winningCells.length >= 4
      && winnerEffect === VICTORY_ID
    ) {
      kind = 'victory';
      ownerId = String(winner?.id || '');
    } else if (moverEffect === DROP_ID && lastMove !== null) {
      kind = 'drop';
      ownerId = String(mover?.id || '');
    }

    if (kind) {
      activeEffectByGame.set(gameId, {
        kind,
        key: moveKey,
        ownerId,
        lastMove,
        winningCells,
        columns,
        rows,
        startedAt: Date.now(),
      });
    } else {
      activeEffectByGame.delete(gameId);
    }
  }

  renderBaseFourInARowSurface(args);

  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwFourLiveCosmetics = 'v1';
  container.dataset.fourTheme = themeVariant;
  container.dataset.fourDiscs = discsVariant;
  container.dataset.fourEffect = viewerEffect || 'base';
  delete container.dataset.fourActiveFx;

  const frame = container.querySelector('.four-board-frame');
  if (frame instanceof HTMLElement) {
    frame.dataset.fourTheme = themeVariant;
    frame.dataset.fourDiscs = discsVariant;
  }

  if (optimistic && viewerEffect === DROP_ID && lastMove !== null) {
    const pendingSlot = slotForCell(container, lastMove);
    if (pendingSlot instanceof HTMLElement) pendingSlot.dataset.mgwFourPendingDrop = '1';
  }

  const active = gameId ? activeEffectByGame.get(gameId) : null;
  if (!active) return;

  const duration = EFFECT_DURATION_MS[active.kind] || 0;
  const elapsed = Math.max(0, Date.now() - Number(active.startedAt || 0));
  if (duration <= 0 || elapsed >= duration || String(active.key || '') !== moveKey) {
    activeEffectByGame.delete(gameId);
    return;
  }

  container.dataset.fourActiveFx = active.kind;
  const delayMs = -Math.round(elapsed);

  if (active.kind === 'drop') {
    mountDropEffect(container, active, delayMs);
    return;
  }
  if (active.kind === 'four') {
    mountFourLineEffect(container, active, delayMs);
    return;
  }
  if (active.kind === 'victory') {
    mountVictoryWaveEffect(container, active, delayMs);
  }
}

function cachePlayerCosmetics(gameId, players){
  if (!gameId) return;
  players.forEach(player => {
    const playerId = String(player?.id || '');
    const slots = player?.game_cosmetics?.slots;
    if (!playerId || !slots || typeof slots !== 'object') return;
    cosmeticsByGamePlayer.set(`${gameId}:${playerId}`, { ...slots });
  });
}

function localEquippedSlots(player, me){
  const playerId = String(player?.id || '');
  const myId = String(me?.id || '');
  if (!playerId || !myId || playerId !== myId) return null;
  const equipped = state?.profileInventory?.equipped;
  return equipped && typeof equipped === 'object' ? equipped : null;
}

function slotsFor(gameId, player, me){
  const local = localEquippedSlots(player, me);
  if (local) return local;

  const direct = player?.game_cosmetics?.slots;
  if (direct && typeof direct === 'object') return direct;

  const playerId = String(player?.id || '');
  if (!gameId || !playerId) return {};
  return cosmeticsByGamePlayer.get(`${gameId}:${playerId}`) || {};
}

function effectForPlayer(gameId, player, me){
  return normalizedEffectId(slotsFor(gameId, player, me)[EFFECT_SLOT]);
}

function normalizedEffectId(value){
  const itemId = String(value || '');
  return EFFECT_IDS.has(itemId) ? itemId : '';
}

function variantFromItem(value, prefix, allowed){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return allowed.has(variant) ? variant : 'base';
}

function moverPlayer(players, board, lastMove){
  if (lastMove === null) return null;
  const symbol = String(board[lastMove] || '');
  if (!symbol || symbol === '-') return null;
  return players.find(player => String(player?.symbol || '') === symbol) || null;
}

function winnerPlayer(game, players){
  const winnerId = String(game?.winner_id || '');
  if (!winnerId) return null;
  return players.find(player => String(player?.id || '') === winnerId) || null;
}

function eventSignature(game, board, lastMove){
  if (!board || lastMove === null) return '';
  return [
    String(game?.id || ''),
    board,
    String(lastMove),
    String(game?.status || ''),
    String(game?.winner_id || ''),
  ].join('|');
}

function normalizeColumns(game){
  const value = Number(game?.board_columns || game?.board_size || 7);
  return [6, 7, 8].includes(value) ? value : 7;
}

function normalizeRows(game, columns){
  const value = Number(game?.board_rows || (columns - 1));
  return value === columns - 1 ? value : columns - 1;
}

function normalizeBoard(value, columns, rows){
  const raw = typeof value === 'string' ? value : '';
  return raw.padEnd(columns * rows, '-').slice(0, columns * rows);
}

function normalizeCell(value, columns, rows){
  const cell = Number(value);
  return Number.isInteger(cell) && cell >= 0 && cell < columns * rows ? cell : null;
}

function normalizedWinningCells(value, columns, rows){
  if (!Array.isArray(value)) return [];
  const max = columns * rows;
  return [...new Set(value.map(Number).filter(cell => Number.isInteger(cell) && cell >= 0 && cell < max))];
}

function slotForCell(container, cell){
  return container.querySelector(`.four-disc-slot[data-four-cell="${cell}"]`);
}

function mountDropEffect(container, active, delayMs){
  const slot = slotForCell(container, active.lastMove);
  if (!(slot instanceof HTMLElement)) return;

  const row = Math.floor(Number(active.lastMove) / Number(active.columns || 7));
  const travelCells = Math.max(1, row + 1);

  slot.dataset.mgwFourDropFx = '1';
  slot.style.setProperty('--mgw-four-drop-cells', String(travelCells));
  slot.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
}

function mountFourLineEffect(container, active, delayMs){
  const cells = Array.isArray(active.winningCells) ? active.winningCells.slice(0, 4) : [];
  if (cells.length < 4) return;

  cells.forEach((cell, index) => {
    const slot = slotForCell(container, cell);
    if (!(slot instanceof HTMLElement)) return;
    slot.dataset.mgwFourSequence = String(index);
    slot.style.setProperty('--mgw-four-seq', String(index));
    slot.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  });

  const grid = container.querySelector('.four-disc-grid');
  if (!(grid instanceof HTMLElement)) return;

  const first = cellPoint(cells[0], active.columns, active.rows);
  const last = cellPoint(cells[cells.length - 1], active.columns, active.rows);
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('mgw-four-live-line-fx');
  svg.setAttribute('viewBox', '0 0 100 100');
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('aria-hidden', 'true');
  svg.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  svg.innerHTML = `
    <line class="mgw-four-live-line-glow" x1="${first.x}" y1="${first.y}" x2="${last.x}" y2="${last.y}"></line>
    <line class="mgw-four-live-line-core" x1="${first.x}" y1="${first.y}" x2="${last.x}" y2="${last.y}"></line>
  `;
  grid.appendChild(svg);
}

function mountVictoryWaveEffect(container, active, delayMs){
  const cells = Array.isArray(active.winningCells) ? active.winningCells.slice(0, 4) : [];
  if (cells.length < 4) return;

  cells.forEach((cell, index) => {
    const slot = slotForCell(container, cell);
    if (!(slot instanceof HTMLElement)) return;
    slot.dataset.mgwFourVictoryCell = String(index);
    slot.style.setProperty('--mgw-four-seq', String(index));
    slot.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  });

  const grid = container.querySelector('.four-disc-grid');
  if (!(grid instanceof HTMLElement)) return;

  const points = cells.map(cell => cellPoint(cell, active.columns, active.rows));
  const centerX = points.reduce((sum, point) => sum + point.x, 0) / points.length;
  const centerY = points.reduce((sum, point) => sum + point.y, 0) / points.length;

  const host = document.createElement('span');
  host.className = 'mgw-four-live-victory-fx';
  host.setAttribute('aria-hidden', 'true');
  host.style.setProperty('--mgw-four-victory-x', `${centerX}%`);
  host.style.setProperty('--mgw-four-victory-y', `${centerY}%`);
  host.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  host.innerHTML = `
    <i class="mgw-four-victory-wave wave-a"></i>
    <i class="mgw-four-victory-wave wave-b"></i>
    <i class="mgw-four-victory-flare"></i>
  `;
  grid.appendChild(host);
}

function cellPoint(cell, columns, rows){
  const col = Number(cell) % Number(columns);
  const row = Math.floor(Number(cell) / Number(columns));
  return {
    x: ((col + 0.5) / Number(columns)) * 100,
    y: ((row + 0.5) / Number(rows)) * 100,
  };
}

function ensureLiveStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/four-in-a-row/live-cosmetics-v1.css?v=1&mvp19_10=live-game-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-four-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwFourLiveCosmetics = 'mvp19-10-live-v1';
  link.href = href;
  document.head.appendChild(link);
}
