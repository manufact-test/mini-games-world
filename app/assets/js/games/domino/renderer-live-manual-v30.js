import {
  renderDominoSurface as renderCorrectiveV25,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-corrective-v25.js?v=2&mvp19_9=manual-corrective-v25&hand_drag=v26&pointer_owner=v28&hand_layout=v29';

const STOCK_ID = 'game-domino-effect-stock-pulse';
const EFFECT_SLOT = 'game_domino_effect';
const handObservers = new WeakMap();

ensureStabilityStyles();
ensureLiveEffectsV31Styles();
ensureLiveEffectsV32Styles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualStability = 'v30';
  container.dataset.mgwDominoLiveEffects = 'v32';
  markHandLayout(container);
  ensureHandLayoutObserver(container);
  mountTileLocalPrecisionV32(container);
  correctStockBeamV32(args, container);
  removeFinaleQaControlV32(container);
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

function mountTileLocalPrecisionV32(container){
  const accent = document.querySelector('.domino-native-fx-accent.is-precision[data-domino-native-game]');
  if (!(accent instanceof HTMLElement)) return;

  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) {
    accent.remove();
    return;
  }

  // Keep the old fixed accent only as an invisible geometry probe so it can never paint
  // in the viewport again. The visible effect is a child of the real placed slot below.
  const slots = [...container.querySelectorAll('.domino-chain-slot')]
    .filter(slot => slot instanceof HTMLElement);
  const latestIndex = slots.indexOf(latestSlot);
  const neighborSlot = adjacentSlot(slots, latestIndex);
  const neighborTile = neighborSlot?.querySelector('.domino-tile');
  const latestRect = stableSlotTileRect(latestSlot, latestTile);

  if (neighborSlot instanceof HTMLElement && neighborTile instanceof HTMLElement) {
    const neighborRect = stableSlotTileRect(neighborSlot, neighborTile);
    const contact = seamBetweenRects(latestRect, neighborRect);
    accent.dataset.dominoPrecisionAnchor = 'seam-v30';
    accent.style.left = `${contact.x}px`;
    accent.style.top = `${contact.y}px`;
    accent.style.setProperty('--mgw-domino-native-angle', `${contact.angle}deg`);
  }
  accent.dataset.dominoPrecisionGeometry = 'static-v2';
  accent.dataset.dominoPrecisionProbe = 'hidden-v32';
  accent.dataset.dominoPrecisionVisual = 'tile-local-v32';

  latestSlot.querySelector('.mgw-domino-precision-local-v32')?.remove();
  const local = document.createElement('span');
  local.className = 'mgw-domino-precision-local-v32';
  local.dataset.dominoPrecisionAnchor = 'latest-slot-local-v32';
  local.dataset.dominoPrecisionVisual = 'tile-outline-v32';
  local.setAttribute('aria-hidden', 'true');
  local.innerHTML = '<i class="tile-wave wave-1"></i><i class="tile-wave wave-2"></i><i class="tile-wave wave-3"></i>';
  latestSlot.appendChild(local);

  const finisher = local.querySelector('.wave-3');
  removeNodeOnAnimationEnd(local, finisher, 'mgw-domino-precision-local-v32');
}

function correctStockBeamV32(args, container){
  const game = args?.game;
  if (String(game?.last_action?.type || '') !== 'draw') return;
  if (actionEffectId(args, container) !== STOCK_ID) return;

  const gameId = String(game?.id || '');
  const accents = [...document.querySelectorAll('.domino-native-fx-accent.is-stock[data-domino-native-game]')]
    .filter(node => node instanceof HTMLElement && (!gameId || String(node.dataset.dominoNativeGame || '') === gameId));
  if (accents.length === 0) return;

  const accent = accents[accents.length - 1];
  const stock = container.querySelector('.domino-stock-count');
  const markedTargets = [...container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target')]
    .filter(node => node instanceof HTMLElement);
  const targetButton = markedTargets[markedTargets.length - 1] || null;
  const targetTile = targetButton?.querySelector('.domino-tile');

  // Never fall back to the hand or screen centre. A beam is shown only when the real
  // drawn domino exists, otherwise it is suppressed instead of lying about the target.
  if (!(stock instanceof HTMLElement) || !(targetTile instanceof HTMLElement)) {
    accents.forEach(node => node.remove());
    return;
  }

  const start = rectCenter(stock.getBoundingClientRect());
  const end = rectCenter(targetTile.getBoundingClientRect());
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const distance = Math.max(12, Math.hypot(dx, dy));
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  accent.classList.add('is-stock-v32');
  accent.dataset.dominoStockSource = 'boneyard-v32';
  accent.dataset.dominoStockTarget = 'exact-drawn-tile-v32';
  accent.style.left = `${start.x}px`;
  accent.style.top = `${start.y}px`;
  accent.style.width = `${distance}px`;
  accent.style.height = '1px';
  accent.style.setProperty('--mgw-domino-native-path-length', `${distance}px`);
  accent.style.setProperty('--mgw-domino-native-angle', `${angle}deg`);
}

function removeFinaleQaControlV32(container){
  container.querySelectorAll('.domino-finale-qa-row,.domino-finale-qa-button').forEach(node => node.remove());
  document.querySelectorAll('.domino-native-fx-accent[data-domino-native-qa="finale"]').forEach(node => node.remove());
  container.dataset.dominoFinaleQa = 'retired-v32';
}

function enhanceFinaleAccents(container){
  const accents = document.querySelectorAll('.domino-native-fx-accent.is-finale[data-domino-native-game]');
  accents.forEach(accent => {
    if (!(accent instanceof HTMLElement) || accent.dataset.dominoFinaleVisual === 'premium-v31') return;
    accent.dataset.dominoFinaleVisual = 'premium-v31';
    accent.classList.add('is-finale-v31');
    accent.insertAdjacentHTML('beforeend', '<i class="finale-aura-v31"></i><i class="finale-prism-v31"></i><i class="finale-spark-v31 s1"></i><i class="finale-spark-v31 s2"></i><i class="finale-spark-v31 s3"></i><i class="finale-spark-v31 s4"></i><i class="finale-spark-v31 s5"></i><i class="finale-spark-v31 s6"></i>');
  });

  container.querySelector('.domino-table.mgw-domino-native-finale-table')?.setAttribute('data-domino-finale-visual', 'premium-v31');
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

function ensureLiveEffectsV32Styles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-effects-v32.css?v=2&mvp19_9=tile-local-stock-exact-v32', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-live-effects-v32]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoLiveEffectsV32 = 'tile-local-stock-exact-v32';
  link.href = href;
  document.head.appendChild(link);
}
