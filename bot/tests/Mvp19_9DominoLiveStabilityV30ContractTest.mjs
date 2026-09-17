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
assert.ok(wrapper.includes("container.dataset.mgwDominoLiveEffects = 'v38'"), 'v38 viewport-particle runtime marker must be active');
assert.ok(wrapper.includes('const handObservers = new WeakMap()'), 'hand layout observer must bind once per live container');
assert.ok(wrapper.includes('new MutationObserver'), 'internal base rerenders must be repaired without requiring an outer rerender');
assert.ok(wrapper.includes('count >= 9'), '9+ tiles must enter the multi-row layout');
assert.ok(wrapper.includes('Math.ceil(count / 2)'), '9+ hands must target two deterministic rows');
assert.ok(wrapper.includes("hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row'"), 'hand must expose explicit layout state');
assert.ok(wrapper.includes('--mgw-domino-hand-columns'), 'hand must publish its deterministic grid column count');
assert.ok(wrapper.includes("hand.style.setProperty('display', 'grid', 'important')"), 'v30 runtime must outrank the accepted v29 flex fallback');
assert.ok(wrapper.includes("hand.style.setProperty('grid-template-columns'"), 'v30 runtime must own deterministic grid columns after every rerender');

assert.ok(wrapper.includes("const PRECISION_ID = 'game-domino-effect-precision-drop'"), 'Precision owner id must remain explicit');
assert.ok(wrapper.includes('function mountViewportPrecisionV38'), 'Precision v38 viewport renderer must exist');
assert.ok(wrapper.includes("String(action?.type || '') !== 'play'"), 'Precision must remain tied to real play actions');
assert.ok(wrapper.includes('actionEffectId(args, container) !== PRECISION_ID'), 'Precision must be gated by the action owner equipped effect, not blindly by the viewer effect');
assert.ok(wrapper.includes('const precisionSeenByGame = new Map()'), 'Precision must deduplicate polling rerenders per game');
assert.ok(wrapper.includes('precisionSeenByGame.get(gameId) === signature'), 'Precision must not relaunch the same authoritative play event');
assert.ok(wrapper.includes("container.querySelector('.domino-chain-slot.latest')"), 'Precision must anchor from the actual latest chain slot');
assert.ok(wrapper.includes("latestSlot?.querySelector('.domino-tile')"), 'Precision must resolve the actual placed domino');
assert.ok(wrapper.includes("root.dataset.dominoPrecisionAnchor = 'latest-tile-viewport-v38'"), 'Precision must publish its real tile viewport anchor');
assert.ok(wrapper.includes("root.dataset.dominoPrecisionVisual = 'eight-visible-shards-v38'"), 'Precision must publish the v38 eight-shard visual');
assert.ok(wrapper.includes("root.dataset.dominoPrecisionShardCount = '8'"), 'Precision must explicitly expose eight particles');
assert.ok(wrapper.includes('const distances = [88, 96, 84, 94, 90, 96, 82, 92]'), 'Precision particles must travel well outside the domino');
assert.ok(wrapper.includes('for (let index = 0; index < 8; index += 1)'), 'Precision must create eight independent particles');
assert.ok(wrapper.includes("duration:1700"), 'Precision particle motion must remain readable rather than blink-length');
assert.ok(wrapper.includes('requestAnimationFrame(track)'), 'Precision viewport origin must follow the real placed domino while the board settles');
assert.ok(wrapper.includes('Promise.allSettled(animations.map(animation => animation.finished))'), 'Precision cleanup must wait for actual Web Animation completion');
assert.ok(wrapper.includes('removeLegacyPrecisionVisuals(gameId, container)'), 'legacy Precision flash layers must be removed before v38 rendering');
assert.ok(!wrapper.includes('mountTileLocalPrecisionV33'), 'rejected nested CSS-only Precision owner must stay removed');
assert.ok(!wrapper.includes('precision-streak s8'), 'rejected local CSS streak implementation must stay removed');
assert.ok(liveEffectsCss.includes('.mgw-domino-live-fx-layer-v38'), 'v38 must use a dedicated viewport effects layer');
assert.ok(liveEffectsCss.includes('z-index:10050!important'), 'viewport effects layer must paint above the board and clipping ancestors');
assert.ok(liveEffectsCss.includes('.mgw-domino-precision-burst-v38 .precision-shard-v38'), 'Precision viewport shards must have dedicated visible styling');
assert.ok(liveEffectsCss.includes('width:6px!important'), 'Precision primary shards must have readable mobile width');
assert.ok(liveEffectsCss.includes('height:22px!important'), 'Precision primary shards must have readable mobile length');
assert.ok(liveEffectsCss.includes('box-shadow:0 0 6px rgba(255,247,188,1)'), 'Precision shards must carry visible glow rather than a lone dot');
assert.ok(!liveEffectsCss.includes('@keyframes mgw-domino-precision-streak-v37'), 'rejected v37 CSS-only shard animation must stay removed');
assert.ok(!liveEffectsCss.includes('animation-duration:.46s'), 'purchased Domino effects must never collapse into the old 0.46s blink');
assert.ok(!liveEffectsCss.includes('mgw-domino-precision-shimmer-v35'), 'rejected flashlight/shimmer visual must stay removed');

assert.ok(wrapper.includes("const STOCK_ID = 'game-domino-effect-stock-pulse'"), 'Stock owner id must remain explicit');
assert.ok(wrapper.includes('function mountViewportStockV38'), 'Stock v38 viewport renderer must exist');
assert.ok(wrapper.includes("String(action?.type || '') !== 'draw'"), 'Stock must remain a real draw effect');
assert.ok(wrapper.includes('actionEffectId(args, container) !== STOCK_ID'), 'Stock must remain owner-gated');
assert.ok(wrapper.includes('actorId !== myId'), 'exact Stock beam must only resolve when the newly drawn tile is visible in the viewer hand');
assert.ok(wrapper.includes('const viewerHandIdsByGame = new Map()'), 'Stock must keep the prior viewer-hand snapshot');
assert.ok(wrapper.includes('const newIds = currentIds.filter(id => !previousViewerHandIds.has(id))'), 'Stock must target an actually new tile id instead of a stale hand tile');
assert.ok(wrapper.includes("String(node.dataset.dominoTile || '') === targetId"), 'Stock must resolve the exact new DOM tile by id');
assert.ok(wrapper.includes('const stockSeenByGame = new Map()'), 'Stock must deduplicate authoritative draw events');
assert.ok(wrapper.includes("root.dataset.dominoStockSource = 'boneyard-v38'"), 'Stock beam must start at the boneyard');
assert.ok(wrapper.includes("root.dataset.dominoStockTarget = 'exact-new-tile-v38'"), 'Stock beam must target the exact newly added domino');
assert.ok(wrapper.includes("root.dataset.dominoStockGeometry = 'viewport-portal-v38'"), 'Stock must publish viewport geometry ownership');
assert.ok(wrapper.includes('const end = rectCenter(targetButton.getBoundingClientRect())'), 'Stock endpoint measurement must remain the exact hand button center');
assert.ok(wrapper.includes("root.dataset.dominoStockSparkCount = '12'"), 'Stock must create twelve visible off-axis particles');
assert.ok(wrapper.includes('for (let index = 0; index < 12; index += 1)'), 'Stock must create twelve independent particles');
assert.ok(wrapper.includes('const spread = 38 + (index % 4) * 7'), 'Stock particles must travel 38-59px away from the beam axis');
assert.ok(wrapper.includes("duration:1050"), 'Stock scatter must remain on screen long enough to read');
assert.ok(wrapper.includes('removeLegacyStockVisuals(gameId, container)'), 'legacy Stock beam/target visuals must be removed before v38 rendering');
assert.ok(!wrapper.includes('correctStockBeamV33'), 'rejected nested Stock CSS particle owner must stay removed');
assert.ok(liveEffectsCss.includes('.mgw-domino-stock-burst-v38 .stock-spark-v38'), 'Stock viewport sparks must have dedicated visible styling');
assert.ok(liveEffectsCss.includes('height:.62px!important'), 'Stock central line must remain thinner than one pixel');
assert.ok(liveEffectsCss.includes('width:100vw!important'), 'Stock viewport layer must span the screen instead of a clipped board box');
assert.ok(!liveEffectsCss.includes('@keyframes mgw-domino-stock-spark-v37'), 'rejected v37 CSS-only spark animation must stay removed');
assert.ok(!liveEffectsCss.includes('left:var(--spark-end)'), 'old animated-left trail must stay rolled back');
assert.ok(!liveEffectsCss.includes('@keyframes mgw-domino-stock-orb-halo-v34'), 'old infinite orb halo must stay removed');

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

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-live-manual-v30.js?v=1&mvp19_9=manual-stability-v30&parent=manual-corrective-v25&visual_portal=v38'"), 'manifest must publish the fresh v38 Domino renderer identity');
assert.ok(entry.includes("$imports[$dominoRendererImportKey] .= '&live_effects=v37';"), 'entry may retain the prior diagnostic suffix while manifest provides the v38 cache break');
assert.match(launch, /\/app\/v110\.php\?v=1192&domino_stability=30&runtime_fix=1/);

assert.ok(store.includes('domino'), 'accepted Store source remains present');

console.log('MVP-19.9 Domino hand stability + v38 viewport particle contract: OK');