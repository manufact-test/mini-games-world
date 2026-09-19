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

const MAP_VARIANTS = new Set(['sea','dark-military','storm','neon']);
const FLEET_VARIANTS = new Set(['classic','modern','armored','neon']);

const cosmeticsByGamePlayer = new Map();
const observedShotByGame = new Map();
const playedShotByGame = new Map();
const shotCaptureHandlers = new WeakMap();

ensureLiveStyles();

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
  const viewerEffect = normalizedShotEffectId(slots[EFFECT_SLOT]);

  renderBaseBattleshipSurface(args);

  if (!(container instanceof HTMLElement)) return;
  container.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4';
  container.dataset.mgwBattleshipLiveEffect = viewerEffect === SHOT_ID ? 'shot-v1' : 'base';
  container.dataset.battleshipMap = mapVariant;
  container.dataset.battleshipFleet = fleetVariant;
  container.dataset.battleshipEffect = viewerEffect || 'base';

  installShotCapture({ game, me, container, viewerEffect });
  maybePlayAuthoritativeShot({ game, me, container, players });
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
  return normalizedShotEffectId(playerPresentationSlots(gameId, player, me, game)[EFFECT_SLOT]);
}

function normalizedShotEffectId(value){
  return String(value || '') === SHOT_ID ? SHOT_ID : '';
}

function variantFromItem(value, prefix, allowed){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return allowed.has(variant) ? variant : 'base';
}

function installShotCapture({ game, me, container, viewerEffect }){
  const previous = shotCaptureHandlers.get(container);
  if (previous) {
    container.removeEventListener('click', previous, true);
    shotCaptureHandlers.delete(container);
  }

  const gameId = String(game?.id || '');
  const myId = String(me?.id || '');
  const canFire = viewerEffect === SHOT_ID
    && String(game?.status || '') === 'active'
    && String(game?.turn || '') === myId;

  if (!gameId || !myId || !canFire) return;

  const handler = event => {
    const target = event.target instanceof Element
      ? event.target.closest('[data-battleship-cell]')
      : null;
    if (!(target instanceof HTMLButtonElement) || !container.contains(target)) return;
    if (target.disabled || String(target.dataset.cellState || '') !== 'unknown') return;
    if (!target.classList.contains('interactive')) return;

    const cell = Number(target.dataset.battleshipCell);
    if (!Number.isInteger(cell) || cell < 0 || cell > 99) return;

    const key = shotEventKeyFromParts(gameId, myId, cell);
    const recent = playedShotByGame.get(gameId);
    if (recent?.key === key && Date.now() - Number(recent.at || 0) < 1200) return;

    playedShotByGame.set(gameId, { key, at:Date.now() });
    mountShotEffect({
      targetCell:target,
      container,
      gameId,
      ownerId:myId,
      source:'local-fire',
    });
  };

  container.addEventListener('click', handler, true);
  shotCaptureHandlers.set(container, handler);
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
      { opacity:.96, transform:`rotate(${angle}deg) scaleX(.16)`, offset:.25 },
      { opacity:1, transform:`rotate(${angle}deg) scaleX(1)`, offset:.62 },
      { opacity:0, transform:`rotate(${angle}deg) scaleX(1)`, offset:1 },
    ], { duration:420, delay:90, easing:'cubic-bezier(.18,.72,.2,1)', fill:'forwards' }),
    bolt.animate([
      { opacity:0, transform:'translate(-50%,-50%) translateX(0px) scale(.62)' },
      { opacity:1, transform:'translate(-50%,-50%) translateX(0px) scale(1)', offset:.12 },
      { opacity:1, transform:`translate(-50%,-50%) translateX(${Math.max(0, distance - 5)}px) scale(1.12)`, offset:.72 },
      { opacity:0, transform:`translate(-50%,-50%) translateX(${distance}px) scale(.72)` },
    ], { duration:390, delay:92, easing:'cubic-bezier(.12,.7,.12,1)', fill:'forwards' }),
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
  const href = new URL('../../../css/games/battleship/live-cosmetics-v1.css?v=5&mvp19_12=live-maps-fleets-v4&frame=full-v1&neon_fleet=tube-v4&shot=plasma-lock-v1', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-battleship-live-cosmetics]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4-shot-v1';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwBattleshipLiveCosmetics = 'maps-fleets-v4-shot-v1';
  link.href = href;
  document.head.appendChild(link);
}
