import {
  renderDominoSurface as renderCorrectiveV25,
  dominoMeta,
  dominoPlayerMark,
  dominoStatus,
} from './renderer-cosmetics-corrective-v25.js?v=2&mvp19_9=manual-corrective-v25&hand_drag=v26&pointer_owner=v28&hand_layout=v29';

const PRECISION_ID = 'game-domino-effect-precision-drop';
const STOCK_ID = 'game-domino-effect-stock-pulse';
const EFFECT_SLOT = 'game_domino_effect';
const handObservers = new WeakMap();
const precisionSeenByGame = new Map();
const stockSeenByGame = new Map();
const viewerHandIdsByGame = new Map();

ensureStabilityStyles();
ensureLiveEffectsV31Styles();
ensureLiveEffectsV33Styles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const game = args?.game;
  const gameId = String(game?.id || '');
  const previousViewerHandIds = gameId ? viewerHandIdsByGame.get(gameId) || null : null;

  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualStability = 'v30';
  container.dataset.mgwDominoLiveEffects = 'v33';
  markHandLayout(container);
  ensureHandLayoutObserver(container);
  mountTileLocalPrecisionV33(args, container);
  correctStockBeamV33(args, container, previousViewerHandIds);
  removeFinaleQaControlV32(container);
  enhanceFinaleAccents(container);
  rememberViewerHand(game);
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

function mountTileLocalPrecisionV33(args, container){
  const game = args?.game;
  const gameId = String(game?.id || '');
  if (!gameId) return;

  removeNativePrecisionAccents(gameId);

  const action = game?.last_action || {};
  if (String(action?.type || '') !== 'play') return;
  if (actionEffectId(args, container) !== PRECISION_ID) return;

  const signature = precisionSignature(game);
  if (!signature || precisionSeenByGame.get(gameId) === signature) return;

  const latestSlot = container.querySelector('.domino-chain-slot.latest');
  const latestTile = latestSlot?.querySelector('.domino-tile');
  if (!(latestSlot instanceof HTMLElement) || !(latestTile instanceof HTMLElement)) return;

  precisionSeenByGame.set(gameId, signature);
  container.dataset.dominoPrecisionOwner = String(action?.player_id || '');
  container.dataset.dominoPrecisionSignature = signature;
  container.dataset.dominoPrecisionLaunches = String(Number(container.dataset.dominoPrecisionLaunches || 0) + 1);

  latestSlot.querySelector('.mgw-domino-precision-local-v33')?.remove();
  latestTile.classList.remove('mgw-domino-native-precision-tile', 'mgw-domino-precision-glow-v33');
  void latestTile.offsetWidth;
  latestTile.classList.add('mgw-domino-precision-glow-v33');

  const local = document.createElement('span');
  local.className = 'mgw-domino-precision-local-v33';
  local.dataset.dominoPrecisionAnchor = 'latest-slot-local-v33';
  local.dataset.dominoPrecisionVisual = 'single-pulse-glow-v33';
  local.dataset.dominoPrecisionOwner = String(action?.player_id || '');
  local.dataset.dominoPrecisionSignature = signature;
  local.setAttribute('aria-hidden', 'true');
  local.innerHTML = '<i class="tile-aura"></i><i class="tile-wave wave-1"></i><i class="tile-wave wave-2"></i>';
  latestSlot.appendChild(local);

  const finisher = local.querySelector('.wave-2');
  const cleanup = event => {
    if (event.target !== finisher) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== 'mgw-domino-precision-local-v33') return;
    finisher.removeEventListener('animationend', cleanup);
    finisher.removeEventListener('animationcancel', cleanup);
    local.remove();
    latestTile.classList.remove('mgw-domino-precision-glow-v33');
  };
  if (finisher instanceof HTMLElement) {
    finisher.addEventListener('animationend', cleanup);
    finisher.addEventListener('animationcancel', cleanup);
  }
}

function correctStockBeamV33(args, container, previousViewerHandIds){
  const game = args?.game;
  const gameId = String(game?.id || '');
  if (!gameId) return;

  removeNativeStockVisuals(gameId, container);

  const action = game?.last_action || {};
  if (String(action?.type || '') !== 'draw') return;
  if (actionEffectId(args, container) !== STOCK_ID) return;

  const myId = String(args?.me?.id || '');
  const actorId = String(action?.player_id || '');
  if (!myId || actorId !== myId) return;

  if (!(previousViewerHandIds instanceof Set)) return;

  const currentIds = viewerHandIds(game);
  const newIds = currentIds.filter(id => !previousViewerHandIds.has(id));
  if (newIds.length === 0) return;

  const targetId = newIds[newIds.length - 1];
  const targetButton = [...container.querySelectorAll('.domino-hand-tile')]
    .find(node => node instanceof HTMLElement && String(node.dataset.dominoTile || '') === targetId) || null;
  const targetTile = targetButton?.querySelector('.domino-tile');
  const stock = container.querySelector('.domino-stock-count');
  if (!(stock instanceof HTMLElement) || !(targetTile instanceof HTMLElement)) return;

  const signature = stockSignature(game, targetId);
  if (!signature || stockSeenByGame.get(gameId) === signature) return;
  stockSeenByGame.set(gameId, signature);

  const start = rectCenter(stock.getBoundingClientRect());
  const end = rectCenter(targetTile.getBoundingClientRect());
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const distance = Math.max(12, Math.hypot(dx, dy));
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  const accent = document.createElement('span');
  accent.className = 'domino-native-fx-accent is-stock-v33';
  accent.dataset.dominoNativeGame = gameId;
  accent.dataset.dominoNativeEffect = 'stock-v33';
  accent.dataset.dominoStockSource = 'boneyard-v33';
  accent.dataset.dominoStockTarget = 'exact-new-tile-v33';
  accent.dataset.dominoStockTargetTile = targetId;
  accent.dataset.dominoStockSignature = signature;
  accent.setAttribute('aria-hidden', 'true');
  accent.style.left = `${start.x}px`;
  accent.style.top = `${start.y}px`;
  accent.style.width = `${distance}px`;
  accent.style.setProperty('--mgw-domino-stock-distance', `${distance}px`);
  accent.style.setProperty('--mgw-domino-stock-angle', `${angle}deg`);
  accent.innerHTML = '<i class="stock-line-v33"></i><i class="stock-orb-v33"></i><i class="stock-spark-v33 p1"></i><i class="stock-spark-v33 p2"></i><i class="stock-spark-v33 p3"></i><i class="stock-spark-v33 p4"></i><i class="stock-spark-v33 p5"></i><i class="stock-spark-v33 p6"></i><i class="stock-spark-v33 p7"></i><i class="stock-spark-v33 p8"></i>';
  document.body.appendChild(accent);

  stock.classList.add('mgw-domino-stock-source-v33');
  targetTile.classList.add('mgw-domino-stock-target-v33');
  container.dataset.dominoStockTargetTile = targetId;
  container.dataset.dominoStockSignature = signature;
  container.dataset.dominoStockLaunches = String(Number(container.dataset.dominoStockLaunches || 0) + 1);

  const finisher = accent.querySelector('.stock-orb-v33');
  const cleanup = event => {
    if (event.target !== finisher) return;
    if (event.type === 'animationend' && String(event.animationName || '') !== 'mgw-domino-stock-orb-v33') return;
    finisher.removeEventListener('animationend', cleanup);
    finisher.removeEventListener('animationcancel', cleanup);
    accent.remove();
    stock.classList.remove('mgw-domino-stock-source-v33');
    targetTile.classList.remove('mgw-domino-stock-target-v33');
  };
  if (finisher instanceof HTMLElement) {
    finisher.addEventListener('animationend', cleanup);
    finisher.addEventListener('animationcancel', cleanup);
  }
}

function removeNativePrecisionAccents(gameId){
  document.querySelectorAll('.domino-native-fx-accent.is-precision[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
}

function removeNativeStockVisuals(gameId, container){
  document.querySelectorAll('.domino-native-fx-accent.is-stock[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
  container.querySelector('.domino-stock-count')?.classList.remove('mgw-domino-native-stock-source');
  container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target').forEach(node => {
    node.classList.remove('mgw-domino-native-stock-target');
  });
}

function rememberViewerHand(game){
  const gameId = String(game?.id || '');
  if (!gameId) return;
  viewerHandIdsByGame.set(gameId, new Set(viewerHandIds(game)));
}

function viewerHandIds(game){
  const hand = Array.isArray(game?.viewer_hand) ? game.viewer_hand : [];
  return hand.map(tile => String(tile?.id || '')).filter(Boolean);
}

function precisionSignature(game){
  const action = game?.last_action || {};
  if (String(action?.type || '') !== 'play') return '';
  return `precision:${String(game?.id || '')}:${Number(game?.move_count || 0)}:${String(action?.player_id || '')}:${String(action?.tile || '')}:${String(action?.side || '')}`;
}

function stockSignature(game, targetId){
  const action = game?.last_action || {};
  if (String(action?.type || '') !== 'draw') return '';
  return `stock:${String(game?.id || '')}:${String(action?.player_id || '')}:${Number(action?.drawn_count || 0)}:${Number(game?.stock_count || 0)}:${String(targetId || '')}`;
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

function rectCenter(rect){
  return {
    x:Number(rect?.left || 0) + Number(rect?.width || 0) / 2,
    y:Number(rect?.top || 0) + Number(rect?.height || 0) / 2,
  };
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

function ensureLiveEffectsV33Styles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-effects-v32.css?v=3&mvp19_9=owner-gated-single-pulse-stock-spark-v33', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-live-effects-v33]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    return;
  }

  document.querySelectorAll('link[data-mgw-domino-live-effects-v32]').forEach(node => node.remove());
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoLiveEffectsV33 = 'owner-gated-single-pulse-stock-spark-v33';
  link.href = href;
  document.head.appendChild(link);
}
