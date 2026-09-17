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
assert.ok(wrapper.includes("container.dataset.mgwDominoLiveEffects = 'v35'"), 'live corrective marker must publish v35');
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
assert.ok(wrapper.includes("local.dataset.dominoPrecisionVisual = 'shimmer-spark-v35'"), 'Precision must publish the new non-flashlight v35 visual');
assert.ok(wrapper.includes('latestSlot.appendChild(local)'), 'visible Precision must be physically owned by the real latest chain slot');
assert.ok(wrapper.includes("local.innerHTML = '<i class=\"tile-aura\"></i><i class=\"tile-wave wave-1\"></i><i class=\"tile-wave wave-2\"></i>'"), 'Precision must keep two deliberate domino echoes plus one aura');
assert.ok(wrapper.includes('removeNativePrecisionAccents(gameId)'), 'legacy detached Precision accent must be removed before local rendering');
assert.ok(wrapper.includes("live-effects-v32.css?v=5&mvp19_9=precision-shimmer-stock-static-sparks-v35"), 'wrapper must publish the fresh v35 visual stylesheet identity');
assert.ok(liveEffectsCss.includes('.mgw-domino-precision-local-v33'), 'Precision waves must be styled inside the real chain slot');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-precision-local-v35'), 'Precision local domino-wave animation must exist');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-precision-shimmer-v35'), 'Precision must add a visible travelling shimmer across the tile');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-precision-sparks-v35'), 'Precision must add a compact spark burst');
assert.ok(liveEffectsCss.includes('box-shadow:'), 'Precision spark burst must be visible without extra runtime DOM');
assert.ok(!liveEffectsCss.includes('.wave-3'), 'Precision still avoids noisy third-wave spam');

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
assert.ok(wrapper.includes("accent.dataset.dominoStockGeometry = 'stable-hand-button-v35'"), 'Stock endpoint must use the stable hand-button geometry instead of an animated inner tile');
assert.ok(wrapper.includes('const end = rectCenter(targetButton.getBoundingClientRect())'), 'Stock endpoint measurement must come from the stable hand slot');
assert.ok(wrapper.includes('removeNativeStockVisuals(gameId, container)'), 'legacy stale-target Stock beam/arrival must be removed');
assert.ok(wrapper.includes('stock-spark-v33 p8'), 'Stock beam must retain multiple explicit trail particles');
assert.ok(liveEffectsCss.includes('height:.64px'), 'Stock line must remain thinner than the previous 1px beam');
assert.ok(liveEffectsCss.includes('@keyframes mgw-domino-stock-spark-v35'), 'Stock larger spark burst animation must exist');
assert.ok(liveEffectsCss.includes('--spark-size:6px'), 'Stock path must include visibly larger sparks');
assert.ok(!liveEffectsCss.includes('left:var(--spark-end)'), 'v34 animated-left trail must stay rolled back to avoid jank/geometry confusion');
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
assert.ok(entry.includes("$imports[$dominoRendererImportKey] .= '&live_effects=v35';"), 'entry must publish a fresh v35 module identity for Telegram/Hostinger');
assert.ok(entry.includes("header('X-MGW-Domino-Live-Precision: local-owner-shimmer-spark-v35');"), 'entry must expose v35 Precision diagnostics');
assert.ok(entry.includes("header('X-MGW-Domino-Live-Effects: precision-shimmer-stock-static-sparks-v35');"), 'entry must expose deployed v35 diagnostics');
assert.match(launch, /\/app\/v110\.php\?v=1192&domino_stability=30&runtime_fix=1/);

assert.ok(store.includes('domino'), 'accepted Store source remains present');

console.log('MVP-19.9 Domino hand stability + live effects v35 contract: OK');
