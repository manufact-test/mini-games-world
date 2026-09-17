import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const wrapper = read('app/assets/js/games/domino/renderer-live-manual-v30.js');
const css = read('app/assets/css/games/domino/live-mobile-stability-v30.css');
const liveEffectsV32Css = read('app/assets/css/games/domino/live-effects-v32.css');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');
const entry = read('app/v110.php');
const store = read('app/assets/js/screens/store-screen-domino-store-v1.js');

assert.ok(wrapper.includes("renderer-cosmetics-corrective-v25.js?v=2"), 'v30 wrapper must keep the accepted v25 live owner');
assert.ok(wrapper.includes("container.dataset.mgwDominoManualStability = 'v30'"), 'v30 hand/runtime marker must remain present');
assert.ok(wrapper.includes("container.dataset.mgwDominoLiveEffects = 'v32'"), 'live corrective marker must publish v32');
assert.ok(wrapper.includes('const handObservers = new WeakMap()'), 'hand layout observer must bind once per live container');
assert.ok(wrapper.includes('new MutationObserver'), 'internal base rerenders must be repaired without requiring an outer rerender');
assert.ok(wrapper.includes('count >= 9'), '9+ tiles must enter the multi-row layout');
assert.ok(wrapper.includes('Math.ceil(count / 2)'), '9+ hands must target two deterministic rows');
assert.ok(wrapper.includes("hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row'"), 'hand must expose explicit layout state');
assert.ok(wrapper.includes('--mgw-domino-hand-columns'), 'hand must publish its deterministic grid column count');
assert.ok(wrapper.includes("hand.style.setProperty('display', 'grid', 'important')"), 'v30 runtime must outrank the accepted v29 flex fallback');
assert.ok(wrapper.includes("hand.style.setProperty('grid-template-columns'"), 'v30 runtime must own deterministic grid columns after every rerender');

assert.ok(wrapper.includes('function mountTileLocalPrecisionV32'), 'Precision v32 corrective must exist');
assert.ok(wrapper.includes("accent.remove();"), 'old body-level Precision accent must be removed');
assert.ok(wrapper.includes("local.dataset.dominoPrecisionAnchor = 'latest-slot-local-v32'"), 'Precision must publish a chain-local anchor');
assert.ok(wrapper.includes("local.dataset.dominoPrecisionVisual = 'tile-outline-v32'"), 'Precision must publish the tile outline visual');
assert.ok(wrapper.includes('latestSlot.appendChild(local)'), 'Precision outline must be physically owned by the real latest chain slot');
assert.ok(!wrapper.includes("accent.dataset.dominoPrecisionAnchor = 'seam-v30'"), 'old fixed viewport Precision anchor must no longer be active');
assert.ok(liveEffectsV32Css.includes('.domino-chain-slot.latest > .mgw-domino-precision-local-v32'), 'Precision waves must be styled inside the real chain slot');
assert.ok(liveEffectsV32Css.includes('@keyframes mgw-domino-precision-local-v32'), 'Precision local wave animation must exist');

assert.ok(wrapper.includes('function correctStockBeamV32'), 'Stock v32 corrective must exist');
assert.ok(wrapper.includes("String(game?.last_action?.type || '') !== 'draw'"), 'Stock must remain a real draw effect');
assert.ok(wrapper.includes(".domino-hand-tile.mgw-domino-native-stock-target"), 'Stock must resolve the exact native drawn tile marker');
assert.ok(wrapper.includes("accent.dataset.dominoStockSource = 'boneyard-v32'"), 'Stock beam must retain the boneyard as source');
assert.ok(wrapper.includes("accent.dataset.dominoStockTarget = 'exact-drawn-tile-v32'"), 'Stock beam must target the exact drawn domino');
assert.ok(wrapper.includes('accents.forEach(node => node.remove())'), 'Stock must suppress itself instead of falling back to an incorrect hand/screen centre');
assert.ok(!wrapper.includes('mountJoinedBeamV31'), 'wrong domino-to-domino Stock replacement must be gone');
assert.ok(!wrapper.includes('suppressLegacyStockDraw'), 'original Stock draw effect must not be suppressed');
assert.ok(liveEffectsV32Css.includes('height:1px!important'), 'Stock beam must be visibly thin');
assert.ok(liveEffectsV32Css.includes('exact-drawn-tile-v32'), 'Stock CSS must only restore the beam after exact target resolution');

assert.ok(wrapper.includes('function removeFinaleQaControlV32'), 'accepted Finale must remove the staging-only QA control');
assert.ok(wrapper.includes(".domino-finale-qa-row,.domino-finale-qa-button"), 'QA row/button must be removed from the rendered panel');
assert.ok(wrapper.includes("accent.dataset.dominoFinaleVisual = 'premium-v31'"), 'accepted premium Finale v31 visual must remain unchanged');
assert.ok(liveEffectsV32Css.includes('.domino-finale-qa-row'), 'QA control must also be hidden before removal to prevent flash');

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

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-live-manual-v30.js?v=1&mvp19_9=manual-stability-v30&parent=manual-corrective-v25'"), 'manifest must publish v30 live Domino owner');
assert.ok(entry.includes("$imports[$dominoRendererImportKey] .= '&live_effects=v32';"), 'entry must publish a fresh v32 module identity for Telegram/Hostinger');
assert.ok(entry.includes("header('X-MGW-Domino-Live-Effects: tile-local-stock-exact-v32');"), 'entry must expose deployed v32 diagnostics');
assert.match(launch, /\/app\/v110\.php\?v=1192&domino_stability=30&runtime_fix=1/);

assert.ok(store.includes('domino'), 'accepted Store source remains present');

console.log('MVP-19.9 Domino hand stability + live effects v32 contract: OK');
