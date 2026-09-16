import {
  renderDominoSurface as renderBaseDominoSurface,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer.js?v=75&base=mvp19-9-live-native-effects-v1';
import { state } from '../../state.js?v=27';

const THEME_SLOT = 'game_domino_theme';
const ELEMENTS_SLOT = 'game_domino_elements';
const EFFECT_SLOT = 'game_domino_effect';
const TABLE_PREFIX = 'game-domino-table-';
const TILES_PREFIX = 'game-domino-tiles-';
const PRECISION_ID = 'game-domino-effect-precision-drop';
const STOCK_ID = 'game-domino-effect-stock-pulse';
const FINALE_ID = 'game-domino-effect-chain-finale';
const EFFECT_IDS = new Set([PRECISION_ID, STOCK_ID, FINALE_ID]);
const THEME_VARIANTS = new Set(['felt', 'midnight', 'walnut', 'neon']);
const ELEMENT_VARIANTS = new Set(['ivory', 'ebony', 'marble', 'neon']);
const cosmeticsByGamePlayer = new Map();
const seenEventByGame = new Map();

ensureLiveStyles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const { game, me, container } = args || {};
  cachePlayerCosmetics(game);
  renderBaseDominoSurface(args);
  decorateLiveDomino({ game, me, container });
}

function decorateLiveDomino({ game, me, container }){
  if (!(container instanceof HTMLElement)) return;

  const gameId = String(game?.id || '');
  if (!gameId) return;

  const players = Array.isArray(game?.players) ? game.players : [];
  const myId = String(me?.id || '');
  const viewer = players.find(player => String(player?.id || '') === myId) || null;
  const presentationOwner = viewer || players[0] || null;
  const presentationSlots = slotsFor(gameId, presentationOwner, me);
  const themeVariant = variantFromItem(presentationSlots[THEME_SLOT], TABLE_PREFIX, THEME_VARIANTS);
  const elementsVariant = variantFromItem(presentationSlots[ELEMENTS_SLOT], TILES_PREFIX, ELEMENT_VARIANTS);
  const viewerEffect = normalizedEffectId(presentationSlots[EFFECT_SLOT]);

  container.dataset.mgwDominoLiveCosmetics = 'full-v4';
  container.dataset.mgwDominoNativeEffects = 'v1';
  container.dataset.dominoTheme = themeVariant;
  container.dataset.dominoElements = elementsVariant;
  container.dataset.dominoEffect = viewerEffect || 'base';

  const table = container.querySelector('.domino-table');
  if (table instanceof HTMLElement) {
    table.dataset.dominoTheme = themeVariant;
    table.dataset.dominoElements = elementsVariant;
  }

  const action = game?.last_action || {};
  const actionType = String(action?.type || '');
  const actor = actionPlayer(game, players);
  const actorIsViewer = String(actor?.id || '') !== '' && String(actor?.id || '') === myId;
  const actorEffect = effectForPlayer(gameId, actor, me) || (actorIsViewer ? viewerEffect : '');
  const finishOwner = finishPlayer(game, players, me);
  const finishIsViewer = String(finishOwner?.id || '') !== '' && String(finishOwner?.id || '') === myId;
  const finishEffect = effectForPlayer(gameId, finishOwner, me) || (finishIsViewer ? viewerEffect : '');
  const signature = eventSignature(game);

  suppressBaseFallback(container, actionType, actorEffect);

  let effectKind = '';
  if (String(game?.status || '') === 'finished' && finishEffect === FINALE_ID) {
    effectKind = 'finale';
  } else if (actionType === 'play' && actorEffect === PRECISION_ID) {
    effectKind = 'precision';
  } else if (actionType === 'draw' && actorEffect === STOCK_ID) {
    effectKind = 'stock';
  }

  if (!signature || !effectKind) return;
  if (seenEventByGame.get(gameId) === signature) return;
  seenEventByGame.set(gameId, signature);
  clearNativeAccents(gameId);

  if (effectKind === 'precision') mountPrecisionNative(gameId, container);
  if (effectKind === 'stock') mountStockNative(gameId, container, Number(action?.drawn_count || 1), actorIsViewer);
  if (effectKind === 'finale') mountFinaleNative(gameId, container);
}

function suppressBaseFallback(container, actionType, actorEffect){
  if (actionType === 'play' && actorEffect === PRECISION_ID) {
    container.querySelector('.domino-chain-slot.latest')?.classList.remove('animate-in');
  }
  if (actionType === 'draw' && actorEffect === STOCK_ID) {
    container.querySelector('.domino-hand')?.classList.remove('draw-pulse');
  }
}

function mountPrecisionNative(gameId, container){
  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) return;

  latestTile.classList.remove('mgw-domino-native-precision-tile');
  void latestTile.offsetWidth;
  latestTile.classList.add('mgw-domino-native-precision-tile');
  removeClassOnAnimationEnd(latestTile, 'mgw-domino-native-precision-tile', 'mgw-domino-native-precision-tile');

  const slots = [...container.querySelectorAll('.domino-chain-slot')]
    .filter(slot => slot instanceof HTMLElement);
  const latestIndex = slots.indexOf(latestSlot);
  const neighbor = adjacentChainSlot(slots, latestIndex);
  const latestRect = latestTile.getBoundingClientRect();
  const neighborTile = neighbor?.querySelector('.domino-tile');
  const neighborRect = neighborTile instanceof HTMLElement ? neighborTile.getBoundingClientRect() : null;
  const contact = precisionContactGeometry(latestRect, neighborRect, latestSlot);

  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-precision';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'precision';
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${contact.x}px`;
  accent.style.top = `${contact.y}px`;
  accent.style.setProperty('--mgw-domino-native-angle', `${contact.angle}deg`);
  accent.innerHTML = '<i class="ring"></i><i class="spark s1"></i><i class="spark s2"></i><i class="spark s3"></i><i class="spark s4"></i>';
  document.body.appendChild(accent);
  removeNodeOnAnimationEnd(accent, accent.querySelector('.ring'), 'mgw-domino-native-precision-ring');
}

function mountStockNative(gameId, container, drawnCount, actorIsViewer){
  const stock = container.querySelector('.domino-stock-count');
  if (!(stock instanceof HTMLElement)) return;

  stock.classList.remove('mgw-domino-native-stock-source');
  void stock.offsetWidth;
  stock.classList.add('mgw-domino-native-stock-source');
  removeClassOnAnimationEnd(stock, 'mgw-domino-native-stock-source', 'mgw-domino-native-stock-source');

  const handTiles = [...container.querySelectorAll('.domino-hand-tile')]
    .filter(tile => tile instanceof HTMLElement);
  const count = Math.max(1, Math.min(Math.max(1, drawnCount), handTiles.length));
  const targets = actorIsViewer ? handTiles.slice(-count) : [];
  targets.forEach((button, index) => {
    button.style.setProperty('--mgw-domino-native-draw-index', String(index));
    button.classList.remove('mgw-domino-native-stock-target');
    void button.offsetWidth;
    button.classList.add('mgw-domino-native-stock-target');
    const actor = button.querySelector('.domino-tile');
    if (actor instanceof HTMLElement) {
      removeClassOnAnimationEnd(button, 'mgw-domino-native-stock-target', 'mgw-domino-native-stock-target', actor);
    }
  });

  const stockRect = stock.getBoundingClientRect();
  const target = targets[targets.length - 1] || container.querySelector('.domino-hand');
  const targetRect = target instanceof HTMLElement ? target.getBoundingClientRect() : null;
  if (!targetRect) return;

  const start = rectCenter(stockRect);
  const end = rectCenter(targetRect);
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const distance = Math.hypot(dx, dy);
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-stock';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'stock';
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${start.x}px`;
  accent.style.top = `${start.y}px`;
  accent.style.setProperty('--mgw-domino-native-path-length', `${Math.max(24, distance)}px`);
  accent.style.setProperty('--mgw-domino-native-angle', `${angle}deg`);
  accent.innerHTML = '<i class="stock-line"></i><i class="stock-orb"></i>';
  document.body.appendChild(accent);
  removeNodeOnAnimationEnd(accent, accent.querySelector('.stock-orb'), 'mgw-domino-native-stock-orb');
}

function mountFinaleNative(gameId, container){
  const table = container.querySelector('.domino-table');
  if (!(table instanceof HTMLElement)) return;

  const chainTiles = [...container.querySelectorAll('.domino-chain-slot .domino-tile')]
    .filter(tile => tile instanceof HTMLElement);
  const visibleTiles = chainTiles.length ? chainTiles : [...container.querySelectorAll('.domino-tile')]
    .filter(tile => tile instanceof HTMLElement);

  const step = visibleTiles.length > 24 ? 34 : (visibleTiles.length > 14 ? 48 : 62);
  visibleTiles.forEach((tile, index) => {
    tile.style.setProperty('--mgw-domino-native-finale-index', String(index));
    tile.style.setProperty('--mgw-domino-native-finale-step', `${step}ms`);
    tile.classList.add('mgw-domino-native-finale-tile');
  });
  table.classList.add('mgw-domino-native-finale-table');
  document.body.dataset.mgwDominoFinale = gameId;

  const tableRect = table.getBoundingClientRect();
  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-finale';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'finale';
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${tableRect.left}px`;
  accent.style.top = `${tableRect.top}px`;
  accent.style.width = `${tableRect.width}px`;
  accent.style.height = `${tableRect.height}px`;
  accent.style.setProperty('--mgw-domino-native-finale-delay', `${Math.max(240, (visibleTiles.length - 1) * step)}ms`);
  accent.innerHTML = '<i class="finale-sweep"></i><i class="finale-flash"></i><i class="finale-ring"></i>';
  document.body.appendChild(accent);

  const finisher = accent.querySelector('.finale-ring');
  const finish = event => {
    if (event.target !== finisher) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== 'mgw-domino-native-finale-ring') return;
    finisher.removeEventListener('animationend', finish);
    finisher.removeEventListener('animationcancel', finish);
    accent.remove();
    releaseFinaleGate(gameId);
  };
  if (finisher instanceof HTMLElement) {
    finisher.addEventListener('animationend', finish);
    finisher.addEventListener('animationcancel', finish);
  } else {
    accent.remove();
    releaseFinaleGate(gameId);
  }
}

function removeClassOnAnimationEnd(element, className, animationName, actor = element){
  if (!(element instanceof HTMLElement) || !(actor instanceof HTMLElement)) return;
  const finish = event => {
    if (event.target !== actor) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== animationName) return;
    actor.removeEventListener('animationend', finish);
    actor.removeEventListener('animationcancel', finish);
    element.classList.remove(className);
  };
  actor.addEventListener('animationend', finish);
  actor.addEventListener('animationcancel', finish);
}

function removeNodeOnAnimationEnd(node, actor, animationName){
  if (!(node instanceof HTMLElement) || !(actor instanceof HTMLElement)) {
    node?.remove?.();
    return;
  }
  const finish = event => {
    if (event.target !== actor) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== animationName) return;
    actor.removeEventListener('animationend', finish);
    actor.removeEventListener('animationcancel', finish);
    node.remove();
  };
  actor.addEventListener('animationend', finish);
  actor.addEventListener('animationcancel', finish);
}

function clearNativeAccents(gameId){
  document.querySelectorAll('.domino-native-fx-accent[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
}

function releaseFinaleGate(gameId){
  if (typeof document === 'undefined') return;
  if (String(document.body?.dataset?.mgwDominoFinale || '') === gameId) {
    delete document.body.dataset.mgwDominoFinale;
  }
}

function adjacentChainSlot(slots, latestIndex){
  if (latestIndex < 0 || slots.length < 2) return null;
  if (latestIndex === 0) return slots[1] || null;
  if (latestIndex === slots.length - 1) return slots[latestIndex - 1] || null;
  return slots[latestIndex - 1] || slots[latestIndex + 1] || null;
}

function precisionContactGeometry(latestRect, neighborRect, latestSlot){
  const latestCenter = rectCenter(latestRect);
  if (neighborRect) {
    const neighborCenter = rectCenter(neighborRect);
    const dx = latestCenter.x - neighborCenter.x;
    const dy = latestCenter.y - neighborCenter.y;
    const length = Math.hypot(dx, dy);
    if (length > 0.5) {
      const ux = dx / length;
      const uy = dy / length;
      const halfExtent = Math.abs(ux) * latestRect.width / 2 + Math.abs(uy) * latestRect.height / 2;
      return {
        x:latestCenter.x - ux * halfExtent,
        y:latestCenter.y - uy * halfExtent,
        angle:Math.atan2(uy, ux) * 180 / Math.PI,
      };
    }
  }

  const vertical = latestSlot instanceof HTMLElement && latestSlot.classList.contains('vertical');
  const rotation = cssNumber(latestSlot, '--domino-rotation', 0) + (vertical ? 90 : 0);
  const radians = rotation * Math.PI / 180;
  const ux = Math.cos(radians);
  const uy = Math.sin(radians);
  const halfExtent = Math.abs(ux) * latestRect.width / 2 + Math.abs(uy) * latestRect.height / 2;
  return {
    x:latestCenter.x - ux * halfExtent,
    y:latestCenter.y - uy * halfExtent,
    angle:rotation,
  };
}

function rectCenter(rect){
  return {
    x:Number(rect?.left || 0) + Number(rect?.width || 0) / 2,
    y:Number(rect?.top || 0) + Number(rect?.height || 0) / 2,
  };
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

function normalizedEffectId(value){
  const itemId = String(value || '');
  return EFFECT_IDS.has(itemId) ? itemId : '';
}

function effectForPlayer(gameId, player, me){
  return normalizedEffectId(slotsFor(gameId, player, me)[EFFECT_SLOT]);
}

function variantFromItem(value, prefix, allowed){
  const itemId = String(value || '');
  if (!itemId.startsWith(prefix)) return 'base';
  const variant = itemId.slice(prefix.length);
  return allowed.has(variant) ? variant : 'base';
}

function actionPlayer(game, players){
  const playerId = String(game?.last_action?.player_id || '');
  if (playerId) {
    const direct = players.find(player => String(player?.id || '') === playerId);
    if (direct) return direct;
  }

  const turnId = String(game?.turn || '');
  if (turnId && players.length === 2) {
    return players.find(player => String(player?.id || '') !== turnId) || null;
  }
  return null;
}

function finishPlayer(game, players, me){
  const winnerId = String(game?.winner_id || '');
  if (winnerId) {
    const winner = players.find(player => String(player?.id || '') === winnerId);
    if (winner) return winner;
  }
  return actionPlayer(game, players)
    || players.find(player => String(player?.id || '') === String(me?.id || ''))
    || players[0]
    || null;
}

function eventSignature(game){
  const gameId = String(game?.id || '');
  const status = String(game?.status || '');
  const moveCount = Number(game?.move_count || 0);
  const action = game?.last_action || {};
  const type = String(action?.type || '');

  if (status === 'finished') {
    return `finish:${gameId}:${moveCount}:${String(game?.winner_id || '')}:${String(game?.end_reason || game?.finish_reason || '')}`;
  }
  if (type === 'play') {
    return `play:${gameId}:${moveCount}:${String(action?.player_id || '')}:${String(action?.tile || '')}:${String(action?.side || '')}`;
  }
  if (type === 'draw') {
    return `draw:${gameId}:${moveCount}:${String(action?.player_id || '')}:${Number(action?.drawn_count || 0)}:${Number(game?.stock_count || 0)}`;
  }
  return '';
}

function cssNumber(element, property, fallback){
  if (!(element instanceof HTMLElement)) return fallback;
  const raw = getComputedStyle(element).getPropertyValue(property);
  const parsed = Number.parseFloat(raw);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function ensureStylesheet(selector, marker, markerValue, href){
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

function ensureLiveStyles(){
  if (typeof document === 'undefined') return;
  ensureStylesheet(
    'link[data-mgw-domino-live-cosmetics]',
    'mgwDominoLiveCosmetics',
    'mvp19-9-native-v1',
    new URL('../../../css/games/domino/live-cosmetics-v2.css?v=4&mvp19_9=live-native-v1', import.meta.url).href,
  );
  ensureStylesheet(
    'link[data-mgw-domino-live-native-effects]',
    'mgwDominoLiveNativeEffects',
    'mvp19-9-native-effects-v1',
    new URL('../../../css/games/domino/live-native-effects-v1.css?v=1&mvp19_9=live-native-v1', import.meta.url).href,
  );
}
