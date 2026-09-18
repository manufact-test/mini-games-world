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
ensureLiveEffectsV38Styles();

export { dominoMeta, dominoPlayerMark, dominoStatus };

export function renderDominoSurface(args){
  const game = args?.game;
  const gameId = String(game?.id || '');
  const previousViewerHandIds = gameId ? viewerHandIdsByGame.get(gameId) || null : null;

  renderCorrectiveV25(args);

  const container = args?.container;
  if (!(container instanceof HTMLElement)) return;

  container.dataset.mgwDominoManualStability = 'v30';
  container.dataset.mgwDominoLiveEffects = 'v38';
  markHandLayout(container);
  ensureHandLayoutObserver(container);
  mountViewportPrecisionV38(args, container);
  mountViewportStockV38(args, container, previousViewerHandIds);
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

function mountViewportPrecisionV38(args, container){
  const game = args?.game;
  const gameId = String(game?.id || '');
  if (!gameId) return;

  removeLegacyPrecisionVisuals(gameId, container);

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

  const layer = ensureViewportFxLayer();
  document.querySelectorAll(`.mgw-domino-precision-burst-v38[data-domino-native-game="${cssEscape(gameId)}"]`).forEach(node => node.remove());

  const root = document.createElement('span');
  root.className = 'mgw-domino-precision-burst-v38';
  root.dataset.dominoNativeGame = gameId;
  root.dataset.dominoPrecisionAnchor = 'latest-tile-viewport-v38';
  root.dataset.dominoPrecisionVisual = 'eight-visible-shards-v38';
  root.dataset.dominoPrecisionOwner = String(action?.player_id || '');
  root.dataset.dominoPrecisionSignature = signature;
  root.dataset.dominoPrecisionShardCount = '8';
  root.dataset.dominoPrecisionGeometry = 'compact-v41';
  root.dataset.dominoPrecisionRadius = '41-48px';
  root.setAttribute('aria-hidden', 'true');

  const ring = document.createElement('i');
  ring.className = 'precision-ring-v38';
  root.appendChild(ring);

  const distances = [44, 48, 42, 47, 45, 48, 41, 46];
  const animations = [];
  for (let index = 0; index < 8; index += 1) {
    const angle = -90 + index * 45;
    const radians = angle * Math.PI / 180;
    const distance = distances[index];
    const dx = Math.cos(radians) * distance;
    const dy = Math.sin(radians) * distance;
    const shard = document.createElement('i');
    shard.className = `precision-shard-v38 s${index + 1}`;
    shard.dataset.dominoPrecisionShard = String(index + 1);
    shard.dataset.dominoPrecisionDx = dx.toFixed(2);
    shard.dataset.dominoPrecisionDy = dy.toFixed(2);
    root.appendChild(shard);

    if (typeof shard.animate === 'function') {
      const animation = shard.animate([
        { opacity:0, transform:`translate(0px,0px) rotate(${angle + 90}deg) scaleY(.55)`, offset:0 },
        { opacity:1, transform:`translate(${(dx * .08).toFixed(2)}px,${(dy * .08).toFixed(2)}px) rotate(${angle + 90}deg) scaleY(1)`, offset:.12 },
        { opacity:1, transform:`translate(${(dx * .68).toFixed(2)}px,${(dy * .68).toFixed(2)}px) rotate(${angle + 96}deg) scaleY(1.04)`, offset:.68 },
        { opacity:.92, transform:`translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) rotate(${angle + 104}deg) scaleY(.9)`, offset:.9 },
        { opacity:0, transform:`translate(${(dx * 1.08).toFixed(2)}px,${(dy * 1.08).toFixed(2)}px) rotate(${angle + 110}deg) scaleY(.72)`, offset:1 },
      ], {
        duration:1700,
        delay:index * 35,
        easing:'cubic-bezier(.18,.68,.16,1)',
        fill:'forwards',
      });
      animations.push(animation);
    }
  }

  layer.appendChild(root);

  const ringAnimation = typeof ring.animate === 'function'
    ? ring.animate([
        { opacity:0, transform:'scale(.82)' },
        { opacity:.92, transform:'scale(1)', offset:.12 },
        { opacity:.52, transform:'scale(1.16)', offset:.62 },
        { opacity:0, transform:'scale(1.32)' },
      ], { duration:1450, easing:'cubic-bezier(.18,.72,.2,1)', fill:'forwards' })
    : null;
  if (ringAnimation) animations.push(ringAnimation);

  let rafId = 0;
  const track = () => {
    if (!root.isConnected || !latestTile.isConnected) return;
    const rect = latestTile.getBoundingClientRect();
    const center = rectCenter(rect);
    root.style.left = `${center.x}px`;
    root.style.top = `${center.y}px`;
    const ringWidth = Math.max(32, rect.width + 8);
    const ringHeight = Math.max(22, rect.height + 8);
    ring.style.width = `${ringWidth}px`;
    ring.style.height = `${ringHeight}px`;
    ring.style.left = `${-ringWidth / 2}px`;
    ring.style.top = `${-ringHeight / 2}px`;
    rafId = requestAnimationFrame(track);
  };
  track();

  const cleanup = () => {
    if (rafId) cancelAnimationFrame(rafId);
    root.remove();
  };
  if (animations.length) {
    Promise.allSettled(animations.map(animation => animation.finished)).then(cleanup);
  }
}

function mountViewportStockV38(args, container, previousViewerHandIds){
  const game = args?.game;
  const gameId = String(game?.id || '');
  if (!gameId) return;

  removeLegacyStockVisuals(gameId, container);

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
  if (!(stock instanceof HTMLElement) || !(targetButton instanceof HTMLElement) || !(targetTile instanceof HTMLElement)) return;

  const signature = stockSignature(game, targetId);
  if (!signature || stockSeenByGame.get(gameId) === signature) return;

  const start = rectCenter(stock.getBoundingClientRect());
  const end = rectCenter(targetButton.getBoundingClientRect());
  const dx = end.x - start.x;
  const dy = end.y - start.y;
  const distance = Math.max(12, Math.hypot(dx, dy));
  const ux = dx / distance;
  const uy = dy / distance;
  const px = -uy;
  const py = ux;
  const angle = Math.atan2(dy, dx) * 180 / Math.PI;

  stockSeenByGame.set(gameId, signature);
  container.dataset.dominoStockTargetTile = targetId;
  container.dataset.dominoStockSignature = signature;
  container.dataset.dominoStockLaunches = String(Number(container.dataset.dominoStockLaunches || 0) + 1);

  const layer = ensureViewportFxLayer();
  document.querySelectorAll(`.mgw-domino-stock-burst-v38[data-domino-native-game="${cssEscape(gameId)}"]`).forEach(node => node.remove());

  const root = document.createElement('span');
  root.className = 'mgw-domino-stock-burst-v38';
  root.dataset.dominoNativeGame = gameId;
  root.dataset.dominoStockSource = 'boneyard-v38';
  root.dataset.dominoStockTarget = 'exact-new-tile-v38';
  root.dataset.dominoStockGeometry = 'viewport-portal-v38';
  root.dataset.dominoStockTargetTile = targetId;
  root.dataset.dominoStockSignature = signature;
  root.dataset.dominoStockSparkCount = '12';
  root.setAttribute('aria-hidden', 'true');

  const line = document.createElement('i');
  line.className = 'stock-line-v38';
  line.style.left = `${start.x}px`;
  line.style.top = `${start.y}px`;
  line.style.width = `${distance}px`;
  line.style.setProperty('--mgw-domino-stock-angle-v38', `${angle}deg`);
  root.appendChild(line);

  const orb = document.createElement('i');
  orb.className = 'stock-orb-v38';
  orb.style.left = `${start.x}px`;
  orb.style.top = `${start.y}px`;
  root.appendChild(orb);

  const animations = [];
  if (typeof line.animate === 'function') {
    animations.push(line.animate([
      { opacity:0, transform:`rotate(${angle}deg) scaleX(0)` },
      { opacity:1, transform:`rotate(${angle}deg) scaleX(.22)`, offset:.12 },
      { opacity:.96, transform:`rotate(${angle}deg) scaleX(1)`, offset:.7 },
      { opacity:0, transform:`rotate(${angle}deg) scaleX(1)` },
    ], { duration:900, easing:'cubic-bezier(.16,.76,.18,1)', fill:'forwards' }));
  }
  if (typeof orb.animate === 'function') {
    animations.push(orb.animate([
      { opacity:0, transform:'translate(0px,0px) scale(.45)' },
      { opacity:1, transform:`translate(${(dx * .08).toFixed(2)}px,${(dy * .08).toFixed(2)}px) scale(1)`, offset:.1 },
      { opacity:1, transform:`translate(${(dx * .86).toFixed(2)}px,${(dy * .86).toFixed(2)}px) scale(.9)`, offset:.82 },
      { opacity:0, transform:`translate(${dx.toFixed(2)}px,${dy.toFixed(2)}px) scale(.65)` },
    ], { duration:900, easing:'cubic-bezier(.15,.78,.18,1)', fill:'forwards' }));
  }

  for (let index = 0; index < 12; index += 1) {
    const progress = (index + 1) / 13;
    const baseX = start.x + dx * progress;
    const baseY = start.y + dy * progress;
    const side = index % 2 === 0 ? -1 : 1;
    const spread = 38 + (index % 4) * 7;
    const along = 8 + (index % 3) * 5;
    const outX = px * spread * side + ux * along;
    const outY = py * spread * side + uy * along;

    const spark = document.createElement('i');
    spark.className = `stock-spark-v38 p${index + 1}`;
    spark.dataset.dominoStockSpark = String(index + 1);
    spark.dataset.dominoStockOutX = outX.toFixed(2);
    spark.dataset.dominoStockOutY = outY.toFixed(2);
    spark.style.left = `${baseX}px`;
    spark.style.top = `${baseY}px`;
    spark.style.width = `${6 + (index % 3)}px`;
    spark.style.height = `${6 + (index % 3)}px`;
    root.appendChild(spark);

    if (typeof spark.animate === 'function') {
      const animation = spark.animate([
        { opacity:0, transform:'translate(0px,0px) scale(.45)', offset:0 },
        { opacity:1, transform:`translate(${(outX * .08).toFixed(2)}px,${(outY * .08).toFixed(2)}px) scale(1.12)`, offset:.14 },
        { opacity:1, transform:`translate(${(outX * .62).toFixed(2)}px,${(outY * .62).toFixed(2)}px) scale(1)`, offset:.64 },
        { opacity:.9, transform:`translate(${outX.toFixed(2)}px,${outY.toFixed(2)}px) scale(.82)`, offset:.9 },
        { opacity:0, transform:`translate(${(outX * 1.12).toFixed(2)}px,${(outY * 1.12).toFixed(2)}px) scale(.35)`, offset:1 },
      ], {
        duration:1050,
        delay:90 + index * 38,
        easing:'cubic-bezier(.18,.68,.16,1)',
        fill:'forwards',
      });
      animations.push(animation);
    }
  }

  layer.appendChild(root);
  stock.classList.add('mgw-domino-stock-source-v38');
  targetTile.classList.add('mgw-domino-stock-target-v38');

  const cleanup = () => {
    root.remove();
    stock.classList.remove('mgw-domino-stock-source-v38');
    targetTile.classList.remove('mgw-domino-stock-target-v38');
  };
  if (animations.length) {
    Promise.allSettled(animations.map(animation => animation.finished)).then(cleanup);
  }
}

function ensureViewportFxLayer(){
  let layer = document.querySelector('.mgw-domino-live-fx-layer-v38');
  if (layer instanceof HTMLElement) return layer;
  layer = document.createElement('div');
  layer.className = 'mgw-domino-live-fx-layer-v38';
  layer.dataset.mgwDominoLiveFxLayer = 'v38';
  layer.setAttribute('aria-hidden', 'true');
  document.body.appendChild(layer);
  return layer;
}

function removeLegacyPrecisionVisuals(gameId, container){
  document.querySelectorAll('.domino-native-fx-accent.is-precision[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
  container.querySelectorAll('.mgw-domino-precision-local-v33').forEach(node => node.remove());
  container.querySelectorAll('.domino-tile.mgw-domino-native-precision-tile,.domino-tile.mgw-domino-precision-glow-v33').forEach(node => {
    node.classList.remove('mgw-domino-native-precision-tile', 'mgw-domino-precision-glow-v33');
  });
}

function removeLegacyStockVisuals(gameId, container){
  document.querySelectorAll('.domino-native-fx-accent.is-stock[data-domino-native-game],.domino-native-fx-accent.is-stock-v33[data-domino-native-game]').forEach(node => {
    if (!(node instanceof HTMLElement)) return;
    if (String(node.dataset.dominoNativeGame || '') !== gameId) return;
    node.remove();
  });
  container.querySelector('.domino-stock-count')?.classList.remove('mgw-domino-native-stock-source', 'mgw-domino-stock-source-v33');
  container.querySelectorAll('.domino-hand-tile.mgw-domino-native-stock-target').forEach(node => {
    node.classList.remove('mgw-domino-native-stock-target');
  });
  container.querySelectorAll('.domino-tile.mgw-domino-stock-target-v33').forEach(node => {
    node.classList.remove('mgw-domino-stock-target-v33');
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

function cssEscape(value){
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') return CSS.escape(String(value));
  return String(value).replace(/["\\]/g, '\\$&');
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

function ensureLiveEffectsV38Styles(){
  if (typeof document === 'undefined') return;
  const href = new URL('../../../css/games/domino/live-effects-v32.css?v=9&mvp19_9=precision-compact-v41', import.meta.url).href;
  const existing = document.querySelector('link[data-mgw-domino-live-effects-v38],link[data-mgw-domino-live-effects-v33]');
  if (existing instanceof HTMLLinkElement) {
    if (existing.href !== href) existing.href = href;
    existing.dataset.mgwDominoLiveEffectsV33 = 'viewport-particles-v38';
    existing.dataset.mgwDominoLiveEffectsV38 = 'viewport-particles-v38';
    return;
  }

  document.querySelectorAll('link[data-mgw-domino-live-effects-v32]').forEach(node => node.remove());
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.dataset.mgwDominoLiveEffectsV33 = 'viewport-particles-v38';
  link.dataset.mgwDominoLiveEffectsV38 = 'viewport-particles-v38';
  link.href = href;
  document.head.appendChild(link);
}