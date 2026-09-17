import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const wrapper = read('app/assets/js/games/domino/renderer-live-manual-v30.js');
const css = read('app/assets/css/games/domino/live-mobile-stability-v30.css');
const liveEffectsCss = read('app/assets/css/games/domino/live-effects-v32.css');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');
const entry = read('app/v110.php');
const store = read('app/assets/js/screens/store-screen-domino-store-v1.js');

assert.ok(wrapper.includes("renderer-cosmetics-corrective-v25.js?v=2"), 'v30 wrapper must keep the accepted v25 live owner');
assert.ok(wrapper.includes("container.dataset.mgwDominoManualStability = 'v30'"), 'v30 hand/runtime marker must remain present');
assert.ok(wrapper.includes("container.dataset.mgwDominoLiveEffects = 'v36'"), 'existing deployed marker stays stable while v37 is a visual/cache corrective');
assert.ok(wrapper.includes('const handObservers = new WeakMap()'), 'hand layout observer must bind once per live container');
assert.ok(wrapper.includes('new MutationObserver'), 'internal base rerenders must be repaired without requiring an outer rerender');
assert.ok(wrapper.includes('count >= 9'), '9+ tiles must enter the multi-row layout');
assert.ok(wrapper.includes('Math.ceil(count / 2)'), '9+ hands must target two deterministic rows');
assert.ok(wrapper.includes("hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row'"), 'hand must expose explicit layout state');
assert.ok(wrapper.includes('--mgw-domino-hand-columns'), 'hand must publish its deterministic grid column count');
assert.ok(wrapper.includes("hand.style.setProperty('display', 'grid', 'important')"), 'v30 runtime must outrank the accepted v29 flex fallback');
assert.ok(wrapper.includes("hand.style.setProperty('grid-template-columns'"), 'v30 runtime must own deterministic grid columns after every rerender');

assert.ok(wrapper.includes("const PRECISION_ID = 'game-domino-effect-precision-drop'"), 'Precision owner id must be explicit');
assert.ok(wrapper.includes('function mountTileLocalPrecisionV33'), 'Precision corrective must exist');
assert.ok(wrapper.includes("String(action?.type || '') !== 'play'"), 'Precision must remain tied to real play actions');
assert.ok(wrapper.includes('actionEffectId(args, container) !== PRECISION_ID'), 'Precision must be gated by the action owner equipped effect, not the viewer effect');
assert.ok(wrapper.includes('const precisionSeenByGame = new Map()'), 'Precision must deduplicate polling rerenders per game');
assert.ok(wrapper.includes('precisionSeenByGame.get(gameId) === signature'), 'Precision must not relaunch the same authoritative play event');
assert.ok(wrapper.includes("local.dataset.dominoPrecisionAnchor = 'latest-slot-local-v33'"), 'visible Precision must publish a chain-local anchor');
assert.ok(wrapper.includes("local.dataset.dominoPrecisionVisual = 'edge-shard-burst-v36'"), 'existing visual diagnostic stays stable while v37 changes readability');
assert.ok(wrapper.includes('latestSlot.appendChild(local)'), 'visible Precision must be physically owned by the real latest chain slot');
assert.ok(wrapper.includes('precision-streak s8'), 'Precision must render eight deliberate outward streaks');
assert.ok(wrapper.includes("String(event.animationName || '') !== 'mgw-domino-precision-streak-v37'"), 'Precision cleanup must wait for the v37 long shard animation');
assert.ok(wrapper.includes('removeNativePrecisionAccents(gameId)'), 'legacy detached Precision accent must be removed before local rendering');
assert.ok(wrapper.includes("live-effects-v32.css?v=7&mvp19_9=precision-readable-radial-stock-crossburst-v37"), 'wrapper must publish the fresh v37 visual stylesheet identity');
assert.ok(liveEffectsCss.includes('z-index:2'), 'Precision visual owner must remain above the tile');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-precision-streak-v37'), 'Precision readable radial shard animation must exist');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-precision-frame-v37'), 'Precision origin outline pulse must exist');
assert.ok(liveEffectsCss.includes('--dx:60px'), 'Precision horizontal shards must travel a clearly visible 60px');
assert.ok(liveEffectsCss.includes('--dy:-58px'), 'Precision vertical shards must travel well outside the domino');
assert.ok(liveEffectsCss.includes('2.2s cubic-bezier'), 'Precision shard lifetime must be slow enough to read');
assert.ok(liveEffectsCss.includes('.precision-echo{\n  display:none!important'), 'rejected glow-like silhouette echoes must be suppressed');
assert.ok(!liveEffectsCss.includes('animation-duration:.46s'), 'purchased Domino effects must never collapse into the old 0.46s blink');
assert.ok(!liveEffectsCss.includes('mgw-domino-precision-shimmer-v35'), 'rejected flashlight/shimmer visual must stay removed');

assert.ok(wrapper.includes('function correctStockBeamV33'), 'Stock corrective must exist');
assert.ok(wrapper.includes("String(action?.type || '') !== 'draw'"), 'Stock must remain a real draw effect');
assert.ok(wrapper.includes('actionEffectId(args, container) !== STOCK_ID'), 'Stock must remain owner-gated');
assert.ok(wrapper.includes('actorId !== myId'), 'exact Stock beam must only resolve when the drawn tile is visible in the viewer hand');
assert.ok(wrapper.includes('const viewerHandIdsByGame = new Map()'), 'Stock must keep the prior viewer-hand snapshot');
assert.ok(wrapper.includes('const newIds = currentIds.filter(id => !previousViewerHandIds.has(id))'), 'Stock must target an actually new tile id instead of the last/stale hand tile');
assert.ok(wrapper.includes("String(node.dataset.dominoTile || '') === targetId"), 'Stock must resolve the exact new DOM tile by id');
assert.ok(wrapper.includes('const stockSeenByGame = new Map()'), 'Stock must deduplicate authoritative draw events');
assert.ok(wrapper.includes("accent.dataset.dominoStockSource = 'boneyard-v33'"), 'Stock beam must retain the boneyard as source');
assert.ok(wrapper.includes("accent.dataset.dominoStockTarget = 'exact-new-tile-v33'"), 'Stock beam must target the exact newly added domino');
assert.ok(wrapper.includes("accent.dataset.dominoStockGeometry = 'stable-hand-button-v35'"), 'Stock endpoint must preserve accepted stable hand-button geometry');
assert.ok(wrapper.includes('const end = rectCenter(targetButton.getBoundingClientRect())'), 'Stock endpoint measurement must remain frozen');
assert.ok(wrapper.includes('removeNativeStockVisuals(gameId, container)'), 'legacy stale-target Stock beam/arrival must be removed');
assert.ok(wrapper.includes("const finisher = accent.querySelector('.stock-spark-v33.p8')"), 'Stock cleanup must wait for the final visible particle, not the 0.8s orb');
assert.ok(wrapper.includes("String(event.animationName || '') !== 'mgw-domino-stock-spark-v37'"), 'Stock cleanup must key off the v37 particle animation');
assert.ok(liveEffectsCss.includes('height:.64px'), 'Stock line must remain thinner than the previous 1px beam');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-stock-spark-v37'), 'Stock cross-beam particle animation must exist');
assert.ok(liveEffectsCss.includes('--spark-side:-36px'), 'Stock particles must visibly leave the beam path by more than 30px');
assert.ok(liveEffectsCss.includes('--spark-side:34px'), 'Stock particles must spread to both sides of the beam');
assert.ok(liveEffectsCss.includes('1.15s cubic-bezier'), 'Stock particles must stay visible for more than a second');
assert.ok(liveEffectsCss.includes('width:8px'), 'Stock particles must be thick enough to read on mobile');
assert.ok(!liveEffectsCss.includes('left:var(--spark-end)'), 'v34 animated-left trail must stay rolled back');
assert.ok(!liveEffectsCss.includes('@keyframes mgw-domino-stock-orb-halo-v34'), 'v34 infinite orb halo must stay removed');

assert.ok(wrapper.includes('function removeFinaleQaControlV32'), 'accepted Finale must keep the staging QA control retired');
assert.ok(wrapper.includes('.domino-finale-qa-row,.domino-finale-qa-button'), 'QA row/button must remain removed from the rendered panel');
assert.ok(wrapper.includes("accent.dataset.dominoFinaleVisual = 'premium-v31'"), 'accepted premium Finale v31 visual must remain unchanged');
assert.ok(liveEffectsCss.includes('.domino-finale-qa-row'), 'QA control must remain hidden before removal to prevent flash');

assert.ok(!wrapper.includes('rayBoxDistance'), 'old ray/support precision geometry must not remain active');
assert.ok(!wrapper.includes('onAction?.'), 'wrapper must not mutate Domino mechanics');
assert.ok(!wrapper.includes('store-screen-domino-store'), 'wrapper must not depend on Store preview code');

assert.ok(css.includes('height:232px!important'), 'mobile chain area must have a stable height');
assert.ok(css.includes('min-height:232px!important'), 'stable height must not collapse on short chains');
assert.ok(css.includes('max-height:232px!important'), 'stable height must not grow on long chains');
assert.ok(css.includes('transition:none!important'), 'table geometry must not animate between chain density states');
assert.ok(css.includes('display:grid!important'), 'mobile hand must use deterministic grid geometry');
assert.ok(css.includes('repeat(var(--mgw-domino-hand-columns),minmax(0,1fr))'), 'grid must use the runtime column count');
assert.ok(css.includes('column-gap:2px!important'), 'mobile tile spacing must remain compact');
assert.ok(css.includes('overflow-x:visible!important'), 'hand tail must not be clipped behind horizontal overflow');
assert.ok(css.includes('touch-action:pan-y!important'), 'vertical page scrolling must remain native around the hand');

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-live-manual-v30.js?v=1&mvp19_9=manual-stability-v30&parent=manual-corrective-v25'"), 'manifest must keep publishing the v30 live Domino owner');
assert.ok(entry.includes("$imports[$dominoRendererImportKey] .= '&live_effects=v37';"), 'entry must publish a fresh v37 module identity for Telegram/Hostinger');
assert.ok(entry.includes("header('X-MGW-Domino-Live-Precision: local-owner-readable-radial-v37');"), 'entry must expose v37 Precision diagnostics');
assert.ok(entry.includes("header('X-MGW-Domino-Live-Effects: precision-readable-radial-stock-crossburst-v37');"), 'entry must expose deployed v37 diagnostics');
assert.match(launch, /\/app\/v110\.php\?v=1192&domino_stability=30&runtime_fix=1/);

assert.ok(store.includes('domino'), 'accepted Store source remains present');

console.log('MVP-19.9 Domino hand stability + live effects v37 readability contract: OK');