import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const wrapper = read('app/assets/js/games/domino/renderer-live-manual-v30.js');
const css = read('app/assets/css/games/domino/live-mobile-stability-v30.css');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');
const store = read('app/assets/js/screens/store-screen-domino-store-v1.js');

assert.ok(wrapper.includes("renderer-cosmetics-corrective-v25.js?v=2"), 'v30 must wrap the accepted v25 live owner');
assert.ok(wrapper.includes("container.dataset.mgwDominoManualStability = 'v30'"), 'v30 runtime marker must be present');
assert.ok(wrapper.includes('const handObservers = new WeakMap()'), 'hand layout observer must bind once per live container');
assert.ok(wrapper.includes('new MutationObserver'), 'internal base rerenders must be repaired without requiring an outer rerender');
assert.ok(wrapper.includes("count >= 9"), '9+ tiles must enter the multi-row layout');
assert.ok(wrapper.includes('Math.ceil(count / 2)'), '9+ hands must target two deterministic rows');
assert.ok(wrapper.includes("hand.dataset.dominoHandLayout = twoRow ? 'two-row' : 'single-row'"), 'hand must expose explicit layout state');
assert.ok(wrapper.includes("--mgw-domino-hand-columns"), 'hand must publish its deterministic grid column count');
assert.ok(wrapper.includes("hand.style.setProperty('display', 'grid', 'important')"), 'v30 runtime must outrank the accepted v29 flex fallback');
assert.ok(wrapper.includes("hand.style.setProperty('grid-template-columns'"), 'v30 runtime must own deterministic grid columns after every rerender');
assert.ok(wrapper.includes("accent.dataset.dominoPrecisionAnchor = 'seam-v30'"), 'precision accent must expose the corrected seam anchor');
assert.ok(wrapper.includes('const horizontal = Math.abs(latest.x - neighbor.x) >= Math.abs(latest.y - neighbor.y)'), 'precision seam must resolve on the dominant touching axis');
assert.ok(wrapper.includes('(latestEdgeX + neighborEdgeX) / 2'), 'horizontal precision contact must resolve between facing edges');
assert.ok(wrapper.includes('(latestEdgeY + neighborEdgeY) / 2'), 'vertical precision contact must resolve between facing edges');
assert.ok(!wrapper.includes('rayBoxDistance'), 'old ray/support precision geometry must not remain active');
assert.ok(!wrapper.includes('onAction?.'), 'v30 must not mutate Domino mechanics');
assert.ok(!wrapper.includes('store-screen-domino-store'), 'v30 must not depend on Store preview code');

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
assert.match(launch, /\/app\/v110\.php\?v=1192&domino_stability=30&runtime_fix=1/);

assert.ok(store.includes('domino'), 'accepted Store source remains present');

console.log('MVP-19.9 Domino live stability v30 contract: OK');
