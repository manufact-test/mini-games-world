import {
  renderBattleshipSurface as renderBaseBattleshipSurface,
  battleshipMeta,
  battleshipPlayerMark,
  battleshipStatus,
} from './renderer.js?v=60&shot=miss-no-impact&base=mvp19_12-live-maps-fleets-v4';
import { state } from '../../state.js?v=27';

const THEME_SLOT = 'game_battleship_theme';
const ELEMENTS_SLOT = 'game_battleship_elements';
const EFFECT_SLOT = 'game_battleship_effect';

const MAP_PREFIX = 'game-battleship-map-';
const FLEET_PREFIX = 'game-battleship-fleet-';
const SHOT_ID = 'game-battleship-effect-shot';
const HIT_ID = 'game-battleship-effect-hit';
const DESTROY_ID = 'game-battleship-effect-destroy';
const EFFECT_IDS = new Set([SHOT_ID, HIT_ID, DESTROY_ID]);

const MAP_VARIANTS = new Set(['sea','dark-military','storm','neon']);
const FLEET_VARIANTS = new Set(['classic','modern','armored','neon']);

const cosmeticsByGamePlayer = new Map();
const observedShotByGame = new Map();
const playedShotByGame = new Map();
const observedImpactByGame = new Map();
const playedImpactByGame = new Map();
const localShotContextByGame = new Map();
let shotQueueListenerInstalled = false;

ensureLiveStyles();
ensureShotQueueListener();

export { battleshipMeta, battleshipPlayerMark, battleshipStatus };

export function renderBattleshipSurface(args){
  const { game, me, container } = args || {};
  const gameId = String(game?.id || '');
  const players = Array.isArray(game?.players) ? game.players : [];
  const myId = String(me?.id || '');

  cachePlayerCosmetics(gameId, players);

  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const slots = viewerPresentationSlots(game, me, viewer);
  const mapVariant = variantFromItem(slots[THEME_SLOT], MAP_PREFIX, MAP_VARIANTS);
  const fleetVariant = variantFromItem(slots[ELEMENTS_SLOT], FLEET_PREFIX, FLEET_VARIANTS);
  const viewerEffect = normalizedEffectId(slots[EFFECT_SLOT]);

  renderBaseBattleshipSurface(args);

  if (!(container instanceof HTMLElement)) return;
  container.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4';
  container.dataset.mgwBattleshipLiveEffect = viewerEffect || 'base';
  container.dataset.battleshipMap = mapVariant;
  container.dataset.battleshipFleet = fleetVariant;
  container.dataset.battleshipEffect = viewerEffect || 'base';

  rememberLocalShotContext({ gameId, myId, container, viewerEffect });
  maybePlayAuthoritativeShot({ game, me, container, players });
  maybePlayResultEffect({ game, me, container, players });
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

function viewerPresentationSlots(game, me, viewer){
  const local = state?.profileInventory?.equipped;
  if (local && typeof local === 'object') return local;

  const directMe = me?.game_cosmetics?.slots;
  if (directMe && typeof directMe === 'object') return directMe;

  const directGame = game?.my_game_cosmetics?.slots;
  if (directGame && typeof directGame === 'object') return directGame;

  const playerSlots = viewer?.game_cosmetics?.slots;
  return playerSlots && typeof playerSlots === 'object' ? playerSlots : {};
}

function playerPresentationSlots(gameId, player, me, game){
  const playerId = String(player?.id || '');
  const myId = String(me?.id || '');

  if (playerId && myId && playerId === myId) {
    return viewerPresentationSlots(game, me, player);
  }

  const directPlayer = player?.game_cosmetics?.slots;
  if (directPlayer && typeof directPlayer === 'object') return directPlayer;

  if (!gameId || !playerId) return {};
  return cosmeticsByGamePlayer.get(`${gameId}:${playerId}`) || {};
}

function effectForPlayer(gameId, player, me, game){
  return normalizedEffectId(playerPresentationSlots(gameId, player, me, game)[EFFECT_SLOT]);
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

function rememberLocalShotContext({ gameId, myId, container, viewerEffect }){
  if (!gameId || !myId || !(container instanceof HTMLElement)) return;
  localShotContextByGame.set(gameId, {
    gameId,
    myId,
    container,
    viewerEffect,
  });
}

function ensureShotQueueListener(){
  if (shotQueueListenerInstalled || typeof document === 'undefined') return;
  shotQueueListenerInstalled = true;

  document.addEventListener('mgw:battleship-fire-queued', event => {
    const detail = event?.detail || {};
    const gameId = String(detail.gameId || '');
    const ownerId = String(detail.playerId || '');
    const cell = Number(detail.cell);
    const context = localShotContextByGame.get(gameId);

    if (!context || context.viewerEffect !== SHOT_ID) return;
    if (!ownerId || ownerId !== context.myId) return;
    if (!Number.isInteger(cell) || cell < 0 || cell > 99) return;
    if (!(context.container instanceof HTMLElement) || !context.container.isConnected) return;

    const targetCell = context.container.querySelector(`.battleship-cell[data-battleship-cell="${cell}"]`);
    if (!(targetCell instanceof HTMLElement)) return;

    const key = shotEventKeyFromParts(gameId, ownerId, cell);
    const recent = playedShotByGame.get(gameId);
    if (recent?.key === key && Date.now() - Number(recent.at || 0) < 1200) return;

    playedShotByGame.set(gameId, { key, at:Date.now() });
    mountShotEffect({
      targetCell,
      container:context.container,
      gameId,
      ownerId,
      source:'local-fire',
    });
  });
}

function maybePlayAuthoritativeShot({ game, me, container, players }){
  const gameId = String(game?.id || '');
  if (!gameId) return;

  const key = shotEventKey(game);
  if (!observedShotByGame.has(gameId)) {
    observedShotByGame.set(gameId, key || '__none__');
    return;
  }
  if (!key || observedShotByGame.get(gameId) === key) return;
  observedShotByGame.set(gameId, key);

  const played = playedShotByGame.get(gameId);
  if (played?.key === key) return;

  const ownerId = String(game?.last_shooter_id || '');
  const shooter = players.find(player => String(player?.id || '') === ownerId) || null;
  if (effectForPlayer(gameId, shooter, me, game) !== SHOT_ID) return;

  const cell = Number(game?.last_shot);
  if (!Number.isInteger(cell) || cell < 0 || cell > 99) return;
  const targetCell = container.querySelector(`.battleship-cell[data-battleship-cell="${cell}"]`);
  if (!(targetCell instanceof HTMLElement)) return;

  playedShotByGame.set(gameId, { key, at:Date.now() });
  mountShotEffect({
    targetCell,
    container,
    gameId,
    ownerId,
    source:'authoritative-shot',
  });
}

function maybePlayResultEffect({ game, me, container, players }){
  const gameId = String(game?.id || '');
  if (!gameId) return;

  const key = impactEventKey(game);
  if (!observedImpactByGame.has(gameId)) {
    observedImpactByGame.set(gameId, key || '__none__');
    return;
  }
  if (!key || observedImpactByGame.get(gameId) === key) return;
  observedImpactByGame.set(gameId, key);

  const result = String(game?.last_result || '');
  const requiredEffect = result === 'hit' ? HIT_ID : (result === 'sunk' ? DESTROY_ID : '');
  if (!requiredEffect) return;

  const ownerId = String(game?.last_shooter_id || '');
  const shooter = players.find(player => String(player?.id || '') === ownerId) || null;
  if (effectForPlayer(gameId, shooter, me, game) !== requiredEffect) return;

  const cell = Number(game?.last_shot);
  if (!Number.isInteger(cell) || cell < 0 || cell > 99) return;

  const targetCell = container.querySelector(`.battleship-cell[data-battleship-cell="${cell}"]`);
  if (!(targetCell instanceof HTMLElement)) return;

  const recent = playedImpactByGame.get(gameId);
  if (recent?.key === key) return;
  playedImpactByGame.set(gameId, { key, at:Date.now() });

  // Once a paid result effect owns this event, suppress only the base cell-scale flash
  // so the player sees one coherent impact. The authoritative hit/sunk cell state stays untouched.
  targetCell.classList.remove('shot-impact');

  if (result === 'hit') {
    mountHitEffect({ targetCell, gameId, ownerId });
    return;
  }

  const shipCells = connectedVisibleSunkCells(container, cell);
  mountDestroyEffect({
    targetCells:shipCells.length ? shipCells : [targetCell],
    gameId,
    ownerId,
  });
}

function impactEventKey(game){
  const result = String(game?.last_result || '');
  if (!['hit','sunk'].includes(result)) return '';
  if (game?.last_shot === null || game?.last_shot === undefined) return '';

  const gameId = String(game?.id || '');
  const ownerId = String(game?.last_shooter_id || '');
  const cell = Number(game?.last_shot);
  if (!gameId || !ownerId || !Number.isInteger(cell) || cell < 0 || cell > 99) return '';

  return `${gameId}:${ownerId}:${cell}:${result}`;
}

function connectedVisibleSunkCells(container, originCell){
  const byCell = new Map();
  container.querySelectorAll('.battleship-cell[data-cell-state="sunk"]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    const cell = Number(node.dataset.battleshipCell);
    if (!Number.isInteger(cell) || cell < 0 || cell > 99) return;
    byCell.set(cell, node);
  });

  if (!byCell.has(originCell)) return [];

  const queue = [originCell];
  const seen = new Set([originCell]);
  const result = [];

  while (queue.length) {
    const cell = queue.shift();
    const node = byCell.get(cell);
    if (node) result.push(node);

    const row = Math.floor(cell / 10);
    const col = cell % 10;
    const neighbors = [];
    if (row > 0) neighbors.push(cell - 10);
    if (row < 9) neighbors.push(cell + 10);
    if (col > 0) neighbors.push(cell - 1);
    if (col < 9) neighbors.push(cell + 1);

    neighbors.forEach(next => {
      if (!seen.has(next) && byCell.has(next)) {
        seen.add(next);
        queue.push(next);
      }
    });
  }

  return result;
}

function mountHitEffect({ targetCell, gameId, ownerId }){
  if (!(targetCell instanceof HTMLElement) || typeof document === 'undefined') return;

  const rect = targetCell.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return;

  const x = rect.left + rect.width / 2;
  const y = rect.top + rect.height / 2;
  const size = Math.max(42, Math.min(86, Math.max(rect.width, rect.height) * 2.5));

  const root = document.createElement('span');
  root.className = 'mgw-bs-live-hit-fx';
  root.dataset.battleshipHitFx = 'impact-flash-v2';
  root.dataset.battleshipHitGame = gameId;
  root.dataset.battleshipHitOwner = ownerId;
  root.setAttribute('aria-hidden', 'true');

  const core = document.createElement('i');
  core.className = 'mgw-bs-live-hit-core';
  const ring = document.createElement('i');
  ring.className = 'mgw-bs-live-hit-ring';
  const flare = document.createElement('i');
  flare.className = 'mgw-bs-live-hit-flare';

  [core, ring, flare].forEach(node => {
    node.style.left = `${x}px`;
    node.style.top = `${y}px`;
    node.style.width = `${size}px`;
    node.style.height = `${size}px`;
  });

  const sparks = [];
  for (let index = 0; index < 6; index += 1) {
    const angle = -90 + index * 60;
    const spark = document.createElement('i');
    spark.className = 'mgw-bs-live-hit-spark';
    spark.style.left = `${x}px`;
    spark.style.top = `${y}px`;
    spark.style.setProperty('--mgw-bs-hit-angle', `${angle}deg`);
    root.appendChild(spark);
    sparks.push({ node:spark, angle });
  }

  root.append(core, ring, flare);
  document.body.appendChild(root);

  const reducedMotion = typeof globalThis.matchMedia === 'function'
    && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const cleanup = () => root.remove();

  if (reducedMotion || typeof core.animate !== 'function') {
    root.classList.add('is-reduced-motion');
    globalThis.setTimeout(cleanup, reducedMotion ? 320 : 1050);
    return;
  }

  const animations = [
    core.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.22)' },
      { opacity:1, transform:'translate(-50%,-50%) scale(.82)', offset:.2 },
      { opacity:.98, transform:'translate(-50%,-50%) scale(1)', offset:.44 },
      { opacity:.42, transform:'translate(-50%,-50%) scale(1.14)', offset:.72 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.3)' },
    ], { duration:980, easing:'cubic-bezier(.12,.8,.2,1)', fill:'forwards' }),
    ring.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.3)' },
      { opacity:.96, transform:'translate(-50%,-50%) scale(.62)', offset:.18 },
      { opacity:.72, transform:'translate(-50%,-50%) scale(1)', offset:.48 },
      { opacity:.28, transform:'translate(-50%,-50%) scale(1.34)', offset:.72 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.62)' },
    ], { duration:1120, easing:'cubic-bezier(.14,.74,.18,1)', fill:'forwards' }),
    flare.animate([
      { opacity:0, transform:'translate(-50%,-50%) rotate(-16deg) scale(.45)' },
      { opacity:.98, transform:'translate(-50%,-50%) rotate(3deg) scale(1)', offset:.22 },
      { opacity:.68, transform:'translate(-50%,-50%) rotate(9deg) scale(1.08)', offset:.56 },
      { opacity:0, transform:'translate(-50%,-50%) rotate(18deg) scale(1.24)' },
    ], { duration:900, easing:'ease-out', fill:'forwards' }),
  ];

  sparks.forEach(({ node, angle }, index) => {
    const radians = angle * Math.PI / 180;
    const distance = size * (.72 + (index % 2) * .09);
    const dx = Math.cos(radians) * distance;
    const dy = Math.sin(radians) * distance;
    animations.push(node.animate([
      { opacity:0, transform:`translate(-50%,-50%) translate(0px,0px) rotate(${angle + 90}deg) scaleY(.5)` },
      { opacity:1, transform:`translate(-50%,-50%) translate(${(dx * .12).toFixed(2)}px,${(dy * .12).toFixed(2)}px) rotate(${angle + 90}deg) scaleY(1.08)`, offset:.16 },
      { opacity:.96, transform:`translate(-50%,-50%) translate(${(dx * .56).toFixed(2)}px,${(dy * .56).toFixed(2)}px) rotate(${angle + 94}deg) scaleY(1)`, offset:.58 },
      { opacity:0, transform:`translate(-50%,-50%) translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) rotate(${angle + 102}deg) scaleY(.7)` },
    ], { duration:1080, delay:index * 22, easing:'cubic-bezier(.12,.72,.18,1)', fill:'forwards' }));
  });

  Promise.allSettled(animations.map(animation => animation.finished)).then(cleanup);
  globalThis.setTimeout(cleanup, 1450);
}

function mountDestroyEffect({ targetCells, gameId, ownerId }){
  const cells = targetCells.filter(node => node instanceof HTMLElement);
  if (!cells.length || typeof document === 'undefined') return;

  const rects = cells.map(node => node.getBoundingClientRect()).filter(rect => rect.width > 0 && rect.height > 0);
  if (!rects.length) return;

  const left = Math.min(...rects.map(rect => rect.left));
  const right = Math.max(...rects.map(rect => rect.right));
  const top = Math.min(...rects.map(rect => rect.top));
  const bottom = Math.max(...rects.map(rect => rect.bottom));
  const x = (left + right) / 2;
  const y = (top + bottom) / 2;
  const cellHeight = Math.max(...rects.map(rect => rect.height));
  const visualY = y + Math.max(2, Math.min(5, cellHeight * .12));
  const shipWidth = Math.max(24, right - left);
  const shipHeight = Math.max(24, bottom - top);
  const blastSize = Math.max(74, Math.min(170, Math.max(shipWidth, shipHeight) * 2.45));

  const root = document.createElement('span');
  root.className = 'mgw-bs-live-destroy-fx';
  root.dataset.battleshipDestroyFx = 'critical-sink-v2';
  root.dataset.battleshipDestroyGame = gameId;
  root.dataset.battleshipDestroyOwner = ownerId;
  root.dataset.battleshipDestroyCells = String(cells.length);
  root.setAttribute('aria-hidden', 'true');

  const flash = document.createElement('i');
  flash.className = 'mgw-bs-live-destroy-flash';
  const ring = document.createElement('i');
  ring.className = 'mgw-bs-live-destroy-ring';
  const ring2 = document.createElement('i');
  ring2.className = 'mgw-bs-live-destroy-ring secondary';
  const wreck = document.createElement('i');
  wreck.className = 'mgw-bs-live-destroy-wreck';

  [flash, ring, ring2, wreck].forEach(node => {
    node.style.left = `${x}px`;
    node.style.top = `${visualY}px`;
    node.style.width = `${blastSize}px`;
    node.style.height = `${blastSize}px`;
  });

  const smokeNodes = [];
  const smokeOffsets = [
    [-.18, -.08, .42],
    [.10, -.18, .52],
    [.22, .02, .36],
  ];
  smokeOffsets.forEach(([ox, oy, scale], index) => {
    const smoke = document.createElement('i');
    smoke.className = 'mgw-bs-live-destroy-smoke';
    smoke.style.left = `${x + blastSize * ox}px`;
    smoke.style.top = `${visualY + blastSize * oy}px`;
    smoke.style.width = `${blastSize * scale}px`;
    smoke.style.height = `${blastSize * scale}px`;
    smoke.dataset.smoke = String(index + 1);
    root.appendChild(smoke);
    smokeNodes.push(smoke);
  });

  const shards = [];
  for (let index = 0; index < 10; index += 1) {
    const angle = -102 + index * 36 + (index % 2 ? 7 : -4);
    const shard = document.createElement('i');
    shard.className = 'mgw-bs-live-destroy-shard';
    shard.style.left = `${x}px`;
    shard.style.top = `${visualY}px`;
    root.appendChild(shard);
    shards.push({ node:shard, angle });
  }

  root.append(flash, ring, ring2, wreck);
  document.body.appendChild(root);

  const reducedMotion = typeof globalThis.matchMedia === 'function'
    && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const cleanup = () => root.remove();

  if (reducedMotion || typeof flash.animate !== 'function') {
    root.classList.add('is-reduced-motion');
    globalThis.setTimeout(cleanup, reducedMotion ? 360 : 1500);
    return;
  }

  const animations = [
    flash.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.18) rotate(-10deg)' },
      { opacity:1, transform:'translate(-50%,-50%) scale(.82) rotate(1deg)', offset:.18 },
      { opacity:.94, transform:'translate(-50%,-50%) scale(1.08) rotate(6deg)', offset:.34 },
      { opacity:.2, transform:'translate(-50%,-50%) scale(1.34) rotate(12deg)', offset:.64 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.48) rotate(15deg)' },
    ], { duration:1500, easing:'cubic-bezier(.1,.78,.16,1)', fill:'forwards' }),
    ring.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.25)' },
      { opacity:.95, transform:'translate(-50%,-50%) scale(.52)', offset:.16 },
      { opacity:.62, transform:'translate(-50%,-50%) scale(1.08)', offset:.5 },
      { opacity:.28, transform:'translate(-50%,-50%) scale(1.42)', offset:.72 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.78)' },
    ], { duration:1650, easing:'cubic-bezier(.12,.72,.18,1)', fill:'forwards' }),
    ring2.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.35)' },
      { opacity:.76, transform:'translate(-50%,-50%) scale(.62)', offset:.22 },
      { opacity:.42, transform:'translate(-50%,-50%) scale(1.25)', offset:.58 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.98)' },
    ], { duration:1780, delay:110, easing:'ease-out', fill:'forwards' }),
    wreck.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.72)' },
      { opacity:.94, transform:'translate(-50%,-50%) scale(1)', offset:.2 },
      { opacity:.72, transform:'translate(-50%,-50%) scale(1.08)', offset:.56 },
      { opacity:.28, transform:'translate(-50%,-50%) scale(1.14)', offset:.76 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.2)' },
    ], { duration:1520, delay:45, easing:'ease-out', fill:'forwards' }),
  ];

  smokeNodes.forEach((node, index) => {
    const driftX = (index - 1) * blastSize * .12;
    const driftY = -blastSize * (.34 + index * .04);
    animations.push(node.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.48)' },
      { opacity:.62, transform:'translate(-50%,-50%) scale(.82)', offset:.26 },
      { opacity:.48, transform:`translate(-50%,-50%) translate(${(driftX * .4).toFixed(2)}px,${(driftY * .45).toFixed(2)}px) scale(1.04)`, offset:.58 },
      { opacity:0, transform:`translate(-50%,-50%) translate(${driftX.toFixed(2)}px,${driftY.toFixed(2)}px) scale(1.3)` },
    ], { duration:1900, delay:150 + index * 60, easing:'cubic-bezier(.18,.6,.24,1)', fill:'forwards' }));
  });

  shards.forEach(({ node, angle }, index) => {
    const radians = angle * Math.PI / 180;
    const distance = blastSize * (.46 + (index % 3) * .08);
    const dx = Math.cos(radians) * distance;
    const dy = Math.sin(radians) * distance;
    animations.push(node.animate([
      { opacity:0, transform:`translate(-50%,-50%) translate(0px,0px) rotate(${angle + 90}deg) scale(.55)` },
      { opacity:1, transform:`translate(-50%,-50%) translate(${(dx * .12).toFixed(2)}px,${(dy * .12).toFixed(2)}px) rotate(${angle + 96}deg) scale(1)`, offset:.18 },
      { opacity:.86, transform:`translate(-50%,-50%) translate(${(dx * .68).toFixed(2)}px,${(dy * .68).toFixed(2)}px) rotate(${angle + 128}deg) scale(.86)`, offset:.62 },
      { opacity:0, transform:`translate(-50%,-50%) translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) rotate(${angle + 164}deg) scale(.62)` },
    ], { duration:1550, delay:45 + index * 24, easing:'cubic-bezier(.12,.72,.18,1)', fill:'forwards' }));
  });

  Promise.allSettled(animations.map(animation => animation.finished)).then(cleanup);
  globalThis.setTimeout(cleanup, 2250);
}

function shotEventKey(game){
  if (game?.last_shot === null || game?.last_shot === undefined) return '';
  const gameId = String(game?.id || '');
  const ownerId = String(game?.last_shooter_id || '');
  const cell = Number(game?.last_shot);
  if (!gameId || !ownerId || !Number.isInteger(cell) || cell < 0 || cell > 99) return '';
  return shotEventKeyFromParts(gameId, ownerId, cell);
}

function shotEventKeyFromParts(gameId, ownerId, cell){
  return `${gameId}:${ownerId}:${cell}`;
}

function mountShotEffect({ targetCell, container, gameId, ownerId, source }){
  if (!(targetCell instanceof HTMLElement) || typeof document === 'undefined') return;

  const targetRect = targetCell.getBoundingClientRect();
  const board = container.querySelector('.battleship-coordinate-board');
  const boardRect = board instanceof HTMLElement ? board.getBoundingClientRect() : targetRect;
  if (targetRect.width <= 0 || targetRect.height <= 0) return;

  const targetX = targetRect.left + targetRect.width / 2;
  const targetY = targetRect.top + targetRect.height / 2;
  const viewportWidth = Math.max(1, globalThis.innerWidth || document.documentElement.clientWidth || 1);
  const viewportHeight = Math.max(1, globalThis.innerHeight || document.documentElement.clientHeight || 1);
  const fromLeft = targetX >= boardRect.left + boardRect.width / 2;
  const startX = clamp(
    fromLeft ? boardRect.left - Math.max(16, targetRect.width) : boardRect.right + Math.max(16, targetRect.width),
    8,
    viewportWidth - 8
  );
  const startY = clamp(boardRect.top - Math.max(10, targetRect.height * .7), 8, viewportHeight - 8);
  const dx = targetX - startX;
  const dy = targetY - startY;
  const distance = Math.max(12, Math.hypot(dx, dy));
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;
  const reticleSize = Math.max(26, Math.min(54, Math.max(targetRect.width, targetRect.height) * 1.72));

  const root = document.createElement('span');
  root.className = 'mgw-bs-live-shot-fx';
  root.dataset.battleshipShotFx = 'plasma-lock-v1';
  root.dataset.battleshipShotGame = gameId;
  root.dataset.battleshipShotOwner = ownerId;
  root.dataset.battleshipShotSource = source;
  root.setAttribute('aria-hidden', 'true');

  const tracer = document.createElement('i');
  tracer.className = 'mgw-bs-live-shot-tracer';
  tracer.style.left = `${startX}px`;
  tracer.style.top = `${startY}px`;
  tracer.style.width = `${distance}px`;
  tracer.style.transform = `rotate(${angle}deg) scaleX(.04)`;

  const bolt = document.createElement('b');
  bolt.className = 'mgw-bs-live-shot-bolt';
  tracer.appendChild(bolt);

  const reticle = document.createElement('i');
  reticle.className = 'mgw-bs-live-shot-reticle';
  reticle.style.left = `${targetX}px`;
  reticle.style.top = `${targetY}px`;
  reticle.style.width = `${reticleSize}px`;
  reticle.style.height = `${reticleSize}px`;

  const ping = document.createElement('i');
  ping.className = 'mgw-bs-live-shot-ping';
  ping.style.left = `${targetX}px`;
  ping.style.top = `${targetY}px`;
  ping.style.width = `${Math.max(18, reticleSize * .72)}px`;
  ping.style.height = `${Math.max(18, reticleSize * .72)}px`;

  root.append(tracer, reticle, ping);
  document.body.appendChild(root);

  const reducedMotion = typeof globalThis.matchMedia === 'function'
    && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const cleanup = () => root.remove();
  if (reducedMotion || typeof reticle.animate !== 'function') {
    root.classList.add('is-reduced-motion');
    globalThis.setTimeout(cleanup, reducedMotion ? 220 : 620);
    return;
  }

  const animations = [
    reticle.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(1.42) rotate(-14deg)' },
      { opacity:1, transform:'translate(-50%,-50%) scale(.92) rotate(0deg)', offset:.28 },
      { opacity:.96, transform:'translate(-50%,-50%) scale(1.03) rotate(0deg)', offset:.64 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.08) rotate(0deg)' },
    ], { duration:560, easing:'cubic-bezier(.18,.78,.18,1)', fill:'forwards' }),
    tracer.animate([
      { opacity:0, transform:`rotate(${angle}deg) scaleX(.04)`, offset:0 },
      { opacity:.96, transform:`rotate(${angle}deg) scaleX(.16)`, offset:.22 },
      { opacity:1, transform:`rotate(${angle}deg) scaleX(1)`, offset:.68 },
      { opacity:0, transform:`rotate(${angle}deg) scaleX(1)`, offset:1 },
    ], { duration:560, delay:90, easing:'cubic-bezier(.18,.72,.2,1)', fill:'forwards' }),
    bolt.animate([
      { opacity:0, transform:'translate(-50%,-50%) translateX(0px) scale(.62)' },
      { opacity:1, transform:'translate(-50%,-50%) translateX(0px) scale(1)', offset:.12 },
      { opacity:1, transform:`translate(-50%,-50%) translateX(${distance}px) scale(1.12)`, offset:.82 },
      { opacity:0, transform:`translate(-50%,-50%) translateX(${distance}px) scale(.72)` },
    ], { duration:540, delay:92, easing:'cubic-bezier(.12,.7,.12,1)', fill:'forwards' }),
    ping.animate([
      { opacity:0, transform:'translate(-50%,-50%) scale(.7)' },
      { opacity:.72, transform:'translate(-50%,-50%) scale(.86)', offset:.36 },
      { opacity:.28, transform:'translate(-50%,-50%) scale(1.1)', offset:.72 },
      { opacity:0, transform:'translate(-50%,-50%) scale(1.24)' },
    ], { duration:500, delay:40, easing:'ease-out', fill:'forwards' }),
  ];

  Promise.allSettled(animations.map(animation => animation.finished)).then(cleanup);
  globalThis.setTimeout(cleanup, 900);
}

function clamp(value, min, max){
  return Math.min(max, Math.max(min, value));
}

function ensureLiveStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/battleship/live-cosmetics-v1.css?v=10&mvp19_12=live-maps-fleets-v4&frame=full-v1&neon_fleet=tube-v4&effects=accepted-three-v1&fire=pending-truth-v3&shot_motion=readable-v2&hit=preview-parity-v2&destroy=readable-centered-v2', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-battleship-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4-effects-v1';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4-effects-v1';
  link.href = href;
  document.head.appendChild(link);
}
