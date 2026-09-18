import {
  renderFourInARowSurface as renderBaseFourInARowSurface,
  fourInARowMeta,
  fourInARowPlayerMark,
} from './renderer.js?v=53&base=mvp19-10-live-v12';
import { state } from '../../state.js?v=27';

const THEME_SLOT = 'game_four_in_a_row_theme';
const ELEMENTS_SLOT = 'game_four_in_a_row_elements';
const EFFECT_SLOT = 'game_four_in_a_row_effect';

const FIELD_PREFIX = 'game-four-field-';
const DISCS_PREFIX = 'game-four-discs-';

const DROP_ID = 'game-four-effect-drop';
const PULSE_ID = 'game-four-effect-four';
const VICTORY_ID = 'game-four-effect-victory-wave';
const EFFECT_IDS = new Set([DROP_ID, PULSE_ID, VICTORY_ID]);
const FIELD_VARIANTS = new Set(['blue', 'dark', 'metal', 'neon']);
const DISC_VARIANTS = new Set(['classic', '3d', 'metal', 'neon']);

const cosmeticsByGamePlayer = new Map();
const seenMoveByGame = new Map();
const activeEffectByGame = new Map();

const EFFECT_DURATION_MS = Object.freeze({
  drop: 900,
  pulse: 1080,
  victory: 3900,
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
      && winnerEffect === VICTORY_ID
    ) {
      kind = 'victory';
      ownerId = String(winner?.id || '');
    } else if (moverEffect === PULSE_ID && lastMove !== null) {
      kind = 'pulse';
      ownerId = String(mover?.id || '');
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

  container.dataset.mgwFourLiveCosmetics = 'v12';
  container.dataset.fourTheme = themeVariant;
  container.dataset.fourDiscs = discsVariant;
  container.dataset.fourEffect = viewerEffect || 'base';
  delete container.dataset.fourActiveFx;

  const currentMover = moverPlayer(players, board, lastMove);
  const currentMoverIsViewer = String(currentMover?.id || '') !== '' && String(currentMover?.id || '') === myId;
  const currentMoverEffect = effectForPlayer(gameId, currentMover, me)
    || (currentMoverIsViewer ? viewerEffect : '');
  container.dataset.fourLastMoveEffect = currentMoverEffect || 'base';

  const frame = container.querySelector('.four-board-frame');
  if (frame instanceof HTMLElement) {
    frame.dataset.fourTheme = themeVariant;
    frame.dataset.fourDiscs = discsVariant;
  }

  if (optimistic && viewerEffect === DROP_ID && lastMove !== null) {
    const pendingSlot = slotForCell(container, lastMove);
    if (pendingSlot instanceof HTMLElement) pendingSlot.dataset.mgwFourPendingDrop = '1';
    mountDropTargetLock(container, lastMove, 0, true);
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
  if (active.kind === 'pulse') {
    mountPulseEffect(container, active, delayMs);
    return;
  }
  if (active.kind === 'victory') {
    mountVictoryOverdriveEffect(container, active, delayMs);
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
  const index = Number(cell);
  if (!Number.isInteger(index) || index < 0) return null;
  const slots = container.querySelectorAll('.four-disc-slot');
  const slot = slots.item(index);
  return slot instanceof HTMLElement ? slot : null;
}

function mountDropEffect(container, active, delayMs){
  const slot = slotForCell(container, active.lastMove);
  const grid = container.querySelector('.four-disc-grid');
  const disc = slot?.querySelector('span');
  if (!(slot instanceof HTMLElement) || !(grid instanceof HTMLElement) || !(disc instanceof HTMLElement)) return;

  const gridRect = grid.getBoundingClientRect();
  const slotRect = slot.getBoundingClientRect();
  if (gridRect.width <= 0 || gridRect.height <= 0 || slotRect.width <= 0) return;

  const computed = window.getComputedStyle(disc);
  const naturalWidth = Number.parseFloat(computed.width);
  const naturalHeight = Number.parseFloat(computed.height);
  const size = Math.max(18, Math.min(
    Number.isFinite(naturalWidth) && naturalWidth > 0 ? naturalWidth : slotRect.width * .82,
    Number.isFinite(naturalHeight) && naturalHeight > 0 ? naturalHeight : slotRect.height * .82,
  ));

  const centerX = slotRect.left - gridRect.left + (slotRect.width / 2);
  const centerY = slotRect.top - gridRect.top + (slotRect.height / 2);
  const startOffset = -(centerY + size * .62);

  slot.dataset.mgwFourDropFx = '1';
  slot.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);

  mountDropTargetLock(container, active.lastMove, delayMs, false);

  const falling = document.createElement('span');
  falling.className = 'mgw-four-live-drop-disc';
  falling.setAttribute('aria-hidden', 'true');
  falling.style.left = `${centerX}px`;
  falling.style.top = `${centerY}px`;
  falling.style.width = `${size}px`;
  falling.style.height = `${size}px`;
  falling.style.background = computed.background;
  falling.style.boxShadow = computed.boxShadow;
  falling.style.border = computed.border;
  falling.style.setProperty('--mgw-four-drop-start', `${startOffset}px`);
  falling.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  grid.appendChild(falling);
}

function mountDropTargetLock(container, cell, delayMs, pending){
  const slot = slotForCell(container, cell);
  const grid = container.querySelector('.four-disc-grid');
  if (!(slot instanceof HTMLElement) || !(grid instanceof HTMLElement)) return;

  const gridRect = grid.getBoundingClientRect();
  const slotRect = slot.getBoundingClientRect();
  if (gridRect.width <= 0 || gridRect.height <= 0 || slotRect.width <= 0) return;

  const centerX = slotRect.left - gridRect.left + (slotRect.width / 2);
  const centerY = slotRect.top - gridRect.top + (slotRect.height / 2);
  const targetSize = Math.max(30, slotRect.width * 1.28);

  const target = document.createElement('span');
  target.className = `mgw-four-drop-target-lock${pending ? ' is-pending' : ''}`;
  target.setAttribute('aria-hidden', 'true');
  target.style.left = `${centerX}px`;
  target.style.top = `${centerY}px`;
  target.style.width = `${targetSize}px`;
  target.style.height = `${targetSize}px`;
  target.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  target.innerHTML = `
    <i class="mgw-four-drop-reticle"></i>
    <i class="mgw-four-drop-corner corner-tl"></i>
    <i class="mgw-four-drop-corner corner-tr"></i>
    <i class="mgw-four-drop-corner corner-bl"></i>
    <i class="mgw-four-drop-corner corner-br"></i>
    <i class="mgw-four-drop-cross cross-h"></i>
    <i class="mgw-four-drop-cross cross-v"></i>
    <i class="mgw-four-drop-lock-dot"></i>
  `;
  grid.appendChild(target);

  const laser = document.createElement('span');
  laser.className = `mgw-four-drop-laser${pending ? ' is-pending' : ''}`;
  laser.setAttribute('aria-hidden', 'true');
  laser.style.left = `${centerX}px`;
  laser.style.top = '0px';
  laser.style.height = `${Math.max(12, centerY - targetSize * .34)}px`;
  laser.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  grid.appendChild(laser);
}

function mountPulseEffect(container, active, delayMs){
  const slot = slotForCell(container, active.lastMove);
  const grid = container.querySelector('.four-disc-grid');
  if (!(slot instanceof HTMLElement) || !(grid instanceof HTMLElement)) return;

  const gridRect = grid.getBoundingClientRect();
  const slotRect = slot.getBoundingClientRect();
  if (gridRect.width <= 0 || gridRect.height <= 0 || slotRect.width <= 0) return;

  const centerX = slotRect.left - gridRect.left + (slotRect.width / 2);
  const centerY = slotRect.top - gridRect.top + (slotRect.height / 2);

  slot.dataset.mgwFourPulseCell = '1';
  slot.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);

  const targets = pulseNeighborCells(active)
    .map(({ cell, step }, index) => {
      const neighbor = slotForCell(container, cell);
      if (!(neighbor instanceof HTMLElement)) return null;
      const rect = neighbor.getBoundingClientRect();
      neighbor.dataset.mgwFourPulseNeighbor = '1';
      neighbor.style.setProperty('--mgw-four-pulse-delay', `${delayMs + (step * 75)}ms`);
      return {
        index,
        step,
        x: rect.left - gridRect.left + (rect.width / 2),
        y: rect.top - gridRect.top + (rect.height / 2),
      };
    })
    .filter(Boolean);

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('mgw-four-live-pulse-fx');
  svg.setAttribute('viewBox', `0 0 ${gridRect.width} ${gridRect.height}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('aria-hidden', 'true');
  svg.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);

  const paths = targets.map(target => {
    const path = lightningPath(centerX, centerY, target.x, target.y, target.index);
    const className = target.index % 2 === 0 ? 'bolt-cyan' : 'bolt-magenta';
    const delay = delayMs + 55 + (target.step * 55) + (target.index * 24);
    return `<path class="mgw-four-pulse-bolt ${className}" pathLength="1" d="${path}" style="--mgw-four-bolt-delay:${delay}ms"></path>`;
  }).join('');

  svg.innerHTML = `
    <circle class="mgw-four-pulse-node node-glow" cx="${centerX}" cy="${centerY}" r="${Math.max(14, slotRect.width * .56)}"></circle>
    <circle class="mgw-four-pulse-node node-core" cx="${centerX}" cy="${centerY}" r="${Math.max(4, slotRect.width * .13)}"></circle>
    ${paths}
  `;
  grid.appendChild(svg);
}

function pulseNeighborCells(active){
  const cell = Number(active.lastMove);
  const columns = Number(active.columns || 7);
  const rows = Number(active.rows || 6);
  const row = Math.floor(cell / columns);
  const col = cell % columns;

  const patterns = [
    [[0,-1,1],[0,1,1]],
    [[-1,-1,1],[-1,1,1],[1,0,1]],
    [[0,-2,2],[0,2,2],[-2,0,2],[2,0,2]],
    [[-1,0,1],[1,1,1],[0,-2,2],[1,-2,2]],
  ];

  const fallback = [
    [0,-1,1],[0,1,1],[-1,0,1],[1,0,1],
    [-1,-1,2],[-1,1,2],[1,-1,2],[1,1,2],
    [0,-2,2],[0,2,2],[-2,0,2],[2,0,2],
  ];

  const patternIndex = stablePatternIndex(active?.key, patterns.length);
  const ordered = [...patterns[patternIndex], ...fallback];
  const seen = new Set();
  const result = [];

  for (const [dr, dc, step] of ordered) {
    const r = row + dr;
    const c = col + dc;
    if (r < 0 || r >= rows || c < 0 || c >= columns) continue;
    const target = r * columns + c;
    if (target === cell || seen.has(target)) continue;
    seen.add(target);
    result.push({ cell:target, step });

    const desiredCount = [2,3,4,4][patternIndex];
    if (result.length >= desiredCount) break;
  }

  return result;
}

function stablePatternIndex(key, count){
  const value = String(key || '');
  let hash = 2166136261;
  for (let index = 0; index < value.length; index++) {
    hash ^= value.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return Math.abs(hash >>> 0) % Math.max(1, Number(count || 1));
}

function lightningPath(x1, y1, x2, y2, seed){
  const dx = x2 - x1;
  const dy = y2 - y1;
  const length = Math.max(1, Math.hypot(dx, dy));
  const nx = -dy / length;
  const ny = dx / length;
  const sign = seed % 2 === 0 ? 1 : -1;
  const points = [
    [x1, y1],
    [x1 + dx * .28 + nx * 6 * sign, y1 + dy * .28 + ny * 6 * sign],
    [x1 + dx * .52 - nx * 4 * sign, y1 + dy * .52 - ny * 4 * sign],
    [x1 + dx * .76 + nx * 5 * sign, y1 + dy * .76 + ny * 5 * sign],
    [x2, y2],
  ];
  return points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
}

function mountVictoryOverdriveEffect(container, active, delayMs){
  const cells = Array.isArray(active.winningCells) ? active.winningCells.slice(0, 4) : [];
  if (cells.length < 4) return;

  const grid = container.querySelector('.four-disc-grid');
  if (!(grid instanceof HTMLElement)) return;

  const gridRect = grid.getBoundingClientRect();
  if (gridRect.width <= 0 || gridRect.height <= 0) return;

  const points = cells
    .map((cell, index) => {
      const slot = slotForCell(container, cell);
      if (!(slot instanceof HTMLElement)) return null;
      const rect = slot.getBoundingClientRect();
      return {
        cell,
        slot,
        sourceIndex:index,
        x:rect.left - gridRect.left + (rect.width / 2),
        y:rect.top - gridRect.top + (rect.height / 2),
        size:Math.max(18, Math.min(rect.width, rect.height) * .84),
      };
    })
    .filter(Boolean);

  if (points.length < 4) return;

  const ordered = orderVictoryPoints(points);
  ordered.forEach((point, index) => {
    point.slot.dataset.mgwFourVictoryCell = String(index);
    point.slot.style.setProperty('--mgw-four-victory-delay', `${delayMs + (index * 125)}ms`);
  });

  const path = victoryPath(ordered);
  const centerX = ordered.reduce((sum, point) => sum + point.x, 0) / ordered.length;
  const centerY = ordered.reduce((sum, point) => sum + point.y, 0) / ordered.length;
  const nodeSize = Math.max(9, Math.min(...ordered.map(point => point.size)) * .34);

  const host = document.createElement('span');
  host.className = 'mgw-four-live-victory-fx mgw-four-victory-overdrive';
  host.setAttribute('aria-hidden', 'true');
  host.style.setProperty('--mgw-four-fx-delay', `${delayMs}ms`);
  host.style.setProperty('--mgw-four-victory-x', `${centerX}px`);
  host.style.setProperty('--mgw-four-victory-y', `${centerY}px`);

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('mgw-four-victory-rail');
  svg.setAttribute('viewBox', `0 0 ${gridRect.width} ${gridRect.height}`);
  svg.setAttribute('preserveAspectRatio', 'none');

  const nodes = ordered.map((point, index) => {
    const delay = delayMs + (index * 125);
    return `
      <g class="mgw-four-victory-node-group" transform="translate(${point.x.toFixed(1)} ${point.y.toFixed(1)}) rotate(45)">
        <rect class="mgw-four-victory-node-lock" x="${(-nodeSize / 2).toFixed(1)}" y="${(-nodeSize / 2).toFixed(1)}" width="${nodeSize.toFixed(1)}" height="${nodeSize.toFixed(1)}" rx="2" style="--mgw-four-node-delay:${delay}ms"></rect>
      </g>
    `;
  }).join('');

  svg.innerHTML = `
    <path class="mgw-four-victory-rail rail-glow" pathLength="1" d="${path}"></path>
    <path class="mgw-four-victory-rail rail-color" pathLength="1" d="${path}"></path>
    <path class="mgw-four-victory-rail rail-core" pathLength="1" d="${path}"></path>
    <path class="mgw-four-victory-rail rail-scan" pathLength="1" d="${path}"></path>
    ${nodes}
  `;

  host.innerHTML = `
    <i class="mgw-four-victory-shade"></i>
    <i class="mgw-four-victory-prism">
      <b class="prism-shell"></b>
      <b class="prism-core"></b>
      <b class="prism-cut cut-a"></b>
      <b class="prism-cut cut-b"></b>
    </i>
    <i class="mgw-four-victory-blade blade-a"></i>
    <i class="mgw-four-victory-blade blade-b"></i>
    <i class="mgw-four-victory-wave"></i>
    ${victoryShards(centerX, centerY, delayMs)}
  `;
  host.prepend(svg);
  grid.appendChild(host);
}

function orderVictoryPoints(points){
  const xs = points.map(point => point.x);
  const ys = points.map(point => point.y);
  const xSpread = Math.max(...xs) - Math.min(...xs);
  const ySpread = Math.max(...ys) - Math.min(...ys);
  return [...points].sort((a, b) => xSpread >= ySpread ? (a.x - b.x) : (a.y - b.y));
}

function victoryPath(points){
  return points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ');
}

function victoryShards(centerX, centerY, delayMs){
  const vectors = [
    [-82,-64,-24],[-42,-92,-12],[18,-96,8],[72,-70,24],
    [94,-20,44],[88,42,66],[46,88,82],[-16,98,102],
    [-72,70,126],[-98,22,148],[-88,-34,166],[-38,-72,188],
  ];

  return vectors.map(([dx, dy, rot], index) => {
    const delay = delayMs + 2250 + (index * 34);
    const tone = index % 3 === 0 ? 'gold' : (index % 2 === 0 ? 'cyan' : 'magenta');
    return `<i class="mgw-four-victory-shard shard-${tone}" style="left:${centerX}px;top:${centerY}px;--mgw-four-shard-x:${dx}px;--mgw-four-shard-y:${dy}px;--mgw-four-shard-rot:${rot}deg;--mgw-four-shard-delay:${delay}ms"></i>`;
  }).join('');
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
  const href = new URL('../../../css/games/four-in-a-row/live-cosmetics-v1.css?v=12&mvp19_10=live-game-v12&victory=full-finale-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-four-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwFourLiveCosmetics = 'mvp19-10-live-v12';
    return;
  }
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwFourLiveCosmetics = 'mvp19-10-live-v12';
  link.href = href;
  document.head.appendChild(link);
}
