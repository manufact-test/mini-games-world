import {
  renderDominoSurface as renderCorrectiveV25,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-corrective-v25.js?v=2&mvp19_9=manual-corrective-v25&hand_drag=v26&pointer_owner=v28&hand_layout=v29';

const STOCK_ID = 'game-domino-effect-stock-pulse';
const EFFECT_SLOT = 'game_domino_effect';
const handObservers = new WeakMap();
const joinedBeamSeenByGame = new Map();
let finaleQaEnhancerBound = false;

ensureStabilityStyles();
ensureLiveEffectsV31Styles();
ensureFinaleQaEnhancer();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualStability = 'v30';
  container.dataset.mgwDominoLiveEffects = 'v31';
  markHandLayout(container);
  ensureHandLayoutObserver(container);
  correctPrecisionContact(container);
  suppressLegacyStockDraw(args, container);
  mountJoinedBeamV31(args, container);
  enhanceFinaleAccents(container);
}

function markHandLayout(container){
  const hand = container.querySelector('.domino-hand');
  if (!(hand instanceof HTMLElement)) return;

  const count = hand.querySelectorAll(':scope > .domino-hand-tile').length;
  const twoRow = count >= 9;
  const columns = twoRow ? Math.max(1, Math.ceil(count / 2)) : Math.max(1, count);

  hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row';
  hand.dataset.dominoHandCount = String(count);
  hand.style.setProperty('--mgw-domino-hand-columns', String(columns));
  hand.style.setProperty('display', 'grid', 'important');
  hand.style.setProperty('grid-template-columns', `repeat(${columns}, minmax(0,1fr))`, 'important');
  hand.style.setProperty('flex-wrap', 'nowrap', 'important');
}

function ensureHandLayoutObserver(container){
  if (handObservers.has(container) || typeof MutationObserver !== 'function') return;

  let scheduled = false;
  const observer = new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => {
      scheduled = false;
      if (container.isConnected) markHandLayout(container);
    });
  });
  observer.observe(container, { childList:true, subtree:true });
  handObservers.set(container, observer);
}

function correctPrecisionContact(container){
  const accent = document.querySelector('.domino-native-fx-accent.is-precision[data-domino-native-game]');
  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(accent instanceof HTMLElement) || !(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) return;

  const slots = [...container.querySelectorAll('.domino-chain-slot')]
    .filter(slot => slot instanceof HTMLElement);
  const latestIndex = slots.indexOf(latestSlot);
  const neighborSlot = adjacentSlot(slots, latestIndex);
  const neighborTile = neighborSlot?.querySelector('.domino-tile');
  const latestRect = stableSlotTileRect(latestSlot, latestTile);
  if (accent.dataset.dominoPrecisionVisual === 'tile-outline-v31') return;

  if (neighborSlot instanceof HTMLElement && neighborTile instanceof HTMLElement) {
    const neighborRect = stableSlotTileRect(neighborSlot, neighborTile);
    const contact = seamBetweenRects(latestRect, neighborRect);
    accent.dataset.dominoPrecisionAnchor = 'seam-v30';
    accent.style.setProperty('--mgw-domino-native-angle', `${contact.angle}deg`);
  }

  accent.classList.add('is-precision-v31');
  accent.dataset.dominoPrecisionGeometry = 'static-v2';
  accent.dataset.dominoPrecisionVisual = 'tile-outline-v31';
  accent.style.left = `${latestRect.left}px`;
  accent.style.top = `${latestRect.top}px`;
  accent.style.width = `${latestRect.width}px`;
  accent.style.height = `${latestRect.height}px`;
  accent.style.transform = 'none';
  accent.innerHTML = '<i class="tile-wave wave-1"></i><i class="tile-wave wave-2"></i><i class="tile-wave wave-3"></i>';

  const finisher = accent.querySelector('.wave-3');
  removeNodeOnAnimationEndV31(accent, finisher, 'mgw-domino-precision-outline-v31');
}

function suppressLegacyStockDraw(args, container){
  const game = args?.game;
  if (String(game?.last_action?.type || '') !== 'draw') return;
  if (actionEffectId(args, container) !== STOCK_ID) return;

  const gameId = String(game?.id || '');
  document.querySelectorAll('.domino-native-fx-accent.is-stock[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (gameId && String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
  container.querySelector('.domino-stock-count')?.classList.remove('mgw-domino-native-stock-source');
  container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target').forEach(node => {
    node.classList.remove('mgw-domino-native-stock-target');
  });
}

function mountJoinedBeamV31(args, container){
  const game = args?.game;
  const action = game?.last_action || {};
  if (String(action?.type || '') !== 'play') return;
  if (actionEffectId(args, container) !== STOCK_ID) return;

  const gameId = String(game?.id || '');
  if (!gameId) return;
  const signature = `join:${gameId}:${Number(game?.move_count || 0)}:${String(action?.player_id || '')}:${String(action?.tile || '')}:${String(action?.side || '')}`;
  if (joinedBeamSeenByGame.get(gameId) === signature) return;
  joinedBeamSeenByGame.set(gameId, signature);

  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) return;

  const slots = [...container.querySelectorAll('.domino-chain-slot')]
    .filter(slot => slot instanceof HTMLElement);
  const latestIndex = slots.indexOf(latestSlot);
  const neighborSlot = adjacentSlot(slots, latestIndex);
  const neighborTile = neighborSlot?.querySelector('.domino-tile');
  if (!(neighborSlot instanceof HTMLElement) || !(neighborTile instanceof HTMLElement)) return;

  document.querySelectorAll('.domino-native-fx-accent.is-join-beam-v31[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') === gameId) node.remove();
  });

  const latestRect = stableSlotTileRect(latestSlot, latestTile);
  const neighborRect = stableSlotTileRect(neighborSlot, neighborTile);
  const start = rectCenter(latestRect);
  const end = seamBetweenRects(latestRect, neighborRect);
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const distance = Math.max(8, Math.hypot(dx, dy));
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-join-beam-v31';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'stock-joined-v31';
  accent.dataset.dominoBeamTarget = 'real-join-seam-v31';
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${start.x}px`;
  accent.style.top = `${start.y}px`;
  accent.style.width = `${distance}px`;
  accent.style.setProperty('--mgw-domino-join-angle', `${angle}deg`);
  accent.style.setProperty('--mgw-domino-join-distance', `${distance}px`);
  accent.innerHTML = '<i class="join-beam"></i><i class="join-orb"></i><i class="join-impact"></i>';
  document.body.appendChild(accent);

  latestTile.classList.add('mgw-domino-join-source-v31');
  neighborTile.classList.add('mgw-domino-join-target-v31');
  const finisher = accent.querySelector('.join-impact');
  const finish = event => {
    if (event.target !== finisher) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== 'mgw-domino-join-impact-v31') return;
    finisher.removeEventListener('animationend', finish);
    finisher.removeEventListener('animationcancel', finish);
    accent.remove();
    latestTile.classList.remove('mgw-domino-join-source-v31');
    neighborTile.classList.remove('mgw-domino-join-target-v31');
  };
  if (finisher instanceof HTMLElement) {
    finisher.addEventListener('animationend', finish);
    finisher.addEventListener('animationcancel', finish);
  }
}

function enhanceFinaleAccents(container){
  const accents = document.querySelectorAll('.domino-native-fx-accent.is-finale[data-domino-native-game]');
  accents.forEach(accent => {
    if (!(accent instanceof HTMLElement) || accent.dataset.dominoFinaleVisual === 'premium-v31') return;
    accent.dataset.dominoFinaleVisual = 'premium-v31';
    accent.classList.add('is-finale-v31');
    accent.insertAdjacentHTML('beforeend', '<i class="finale-aura-v31"></i><i class="finale-prism-v31"></i><i class="finale-spark-v31 s1"></i><i class="finale-spark-v31 s2"></i><i class="finale-spark-v31 s3"></i><i class="finale-spark-v31 s4"></i><i class="finale-spark-v31 s5"></i><i class="finale-spark-v31 s6"></i>');
  });

  if (!(container instanceof HTMLElement)) return;
  container.querySelector('.domino-table.mgw-domino-native-finale-table')?.setAttribute('data-domino-finale-visual', 'premium-v31');
}

function ensureFinaleQaEnhancer(){
  if (finaleQaEnhancerBound || typeof document === 'undefined') return;
  finaleQaEnhancerBound = true;
  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!(target?.closest('.domino-finale-qa-button') instanceof HTMLElement)) return;
    requestAnimationFrame(() => enhanceFinaleAccents(document.querySelector('.domino-surface')));
  });
}

function actionEffectId(args, container){
  const game = args?.game;
  const me = args?.me;
  const players = Array.isArray(game?.players) ? game.players : [];
  const actionPlayerId = String(game?.last_action?.player_id || '');
  const actor = actionPlayerId
    ? players.find(player => String(player?.id || '') === actionPlayerId) || null
    : null;
  const myId = String(me?.id || '');

  if (actor && String(actor?.id || '') === myId) {
    const local = String(container?.dataset?.dominoEffect || '');
    if (local) return local;
  }

  const direct = actor?.game_cosmetics?.slots?.[EFFECT_SLOT];
  return String(direct || '');
}

function adjacentSlot(slots, latestIndex){
  if (latestIndex < 0 || slots.length < 2) return null;
  if (latestIndex === 0) return slots[1] || null;
  if (latestIndex === slots.length - 1) return slots[latestIndex - 1] || null;
  return slots[latestIndex - 1] || slots[latestIndex + 1] || null;
}

function stableSlotTileRect(slot, tile){
  const slotRect = slot.getBoundingClientRect();
  const center = rectCenter(slotRect);
  const baseWidth = Number(tile.offsetWidth || 0) || Number(tile.getBoundingClientRect().width || 0);
  const baseHeight = Number(tile.offsetHeight || 0) || Number(tile.getBoundingClientRect().height || 0);
  const vertical = slot.classList.contains('vertical');
  const isDouble = slot.classList.contains('is-double');
  const quarterTurn = (vertical && !isDouble) || (!vertical && isDouble);
  const width = quarterTurn ? baseHeight : baseWidth;
  const height = quarterTurn ? baseWidth : baseHeight;

  return {
    left:center.x - width / 2,
    right:center.x + width / 2,
    top:center.y - height / 2,
    bottom:center.y + height / 2,
    width,
    height,
  };
}

function seamBetweenRects(latestRect, neighborRect){
  const latest = rectCenter(latestRect);
  const neighbor = rectCenter(neighborRect);
  const horizontal = Math.abs(latest.x - neighbor.x) >= Math.abs(latest.y - neighbor.y);
  const angle = Math.atan2(latest.y - neighbor.y, latest.x - neighbor.x) * 180 / Math.PI;

  if (horizontal) {
    const latestEdgeX = latest.x > neighbor.x ? latestRect.left : latestRect.right;
    const neighborEdgeX = latest.x > neighbor.x ? neighborRect.right : neighborRect.left;
    return {
      x:(latestEdgeX + neighborEdgeX) / 2,
      y:(latest.y + neighbor.y) / 2,
      angle,
    };
  }

  const latestEdgeY = latest.y > neighbor.y ? latestRect.top : latestRect.bottom;
  const neighborEdgeY = latest.y > neighbor.y ? neighborRect.bottom : neighborRect.top;
  return {
    x:(latest.x + neighbor.x) / 2,
    y:(latestEdgeY + neighborEdgeY) / 2,
    angle,
  };
}

function rectCenter(rect){
  return {
    x:Number(rect?.left || 0) + Number(rect?.width || 0) / 2,
    y:Number(rect?.top || 0) + Number(rect?.height || 0) / 2,
  };
}

function removeNodeOnAnimationEndV31(node, actor, animationName){
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

function ensureStabilityStyles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-mobile-stability-v30.css?v=1&mvp19_9=mobile-stability-v30', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-mobile-stability]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoMobileStability = 'v30';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoMobileStability = 'v30';
  link.href = href;
  document.head.appendChild(link);
}

function ensureLiveEffectsV31Styles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-effects-v31.css?v=1&mvp19_9=live-effects-v31', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-live-effects-v31]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoLiveEffectsV31 = 'premium-v31';
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoLiveEffectsV31 = 'premium-v31';
  link.href = href;
  document.head.appendChild(link);
}
