import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const liveJs = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const liveCss = read('app/assets/css/games/domino/live-effects-v1.css');
const timing = read('app/assets/css/games/domino/store-effects-timing-parity-v18.css');
const alignment = read('app/assets/css/games/domino/store-effects-precision-alignment-v19.css');
const visual = read('app/assets/css/games/domino/store-effects-visual-parity-v16.css');
const store = read('app/assets/js/screens/store-screen-domino-store-v1.js');
const index = read('app/index.html');
const migration = read('bot/database/migrations/20260915_0035_add_domino_store_cosmetics.php');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');

for (const itemId of [
  'game-domino-effect-precision-drop',
  'game-domino-effect-stock-pulse',
  'game-domino-effect-chain-finale',
]) {
  assert.ok(liveJs.includes(itemId), `live renderer must know ${itemId}`);
  assert.ok(migration.includes(itemId), `catalog migration must contain ${itemId}`);
}
assert.ok(liveJs.includes("const EFFECT_SLOT = 'game_domino_effect'"), 'live renderer must use authoritative Domino effect slot');
assert.ok(migration.includes("'slot'=>'game_domino_effect'"), 'catalog must keep the same authoritative effect slot');
assert.ok(migration.includes("'event'=>'play'"), 'precision event must remain play');
assert.ok(migration.includes("'event'=>'draw'"), 'stock event must remain draw');
assert.ok(migration.includes("'event'=>'finish'"), 'finale event must remain finish');

assert.ok(liveJs.includes('dominoPreviewMarkup'), 'live must reuse the accepted Store/Buy preview primitive');
assert.ok(liveJs.includes("dominoPreviewMarkup('effect', variant)"), 'live scene markup must come from the accepted primitive');
assert.ok(liveJs.includes("actionType === 'play' && actorEffect === PRECISION_ID"), 'precision must bind to authoritative play');
assert.ok(liveJs.includes("actionType === 'draw' && actorEffect === STOCK_ID"), 'stock must bind to authoritative draw');
assert.ok(liveJs.includes("status || '') === 'finished' && finishEffect === FINALE_ID"), 'finale must bind to terminal state');
assert.ok(liveJs.includes('seenEventByGame'), 'live events must be de-duplicated across polling renders');
assert.ok(liveJs.includes('document.body.appendChild(host)'), 'transient effects must survive board innerHTML polling rebuilds');
assert.ok(liveJs.includes("prefers-reduced-motion: reduce"), 'live renderer must respect reduced motion');
assert.ok(liveJs.includes('suppressBaseFallback'), 'premium live effect must suppress the base fallback on every polling render');
assert.ok(liveJs.includes('adjacentChainSlot'), 'precision must use the real adjacent snake-chain tile');
assert.ok(liveJs.includes('precisionContactGeometry'), 'precision must anchor to the real chain joint');
assert.ok(liveJs.includes('sceneWidthFromLongEdge'), 'precision/stock must scale from real live tile geometry');
assert.ok(liveJs.includes('sceneWidthFromShortEdge'), 'finale must scale from real live tile geometry');
assert.ok(liveJs.includes('animationcancel'), 'animation cancellation must release transient live hosts safely');
assert.ok(!liveJs.includes('MutationObserver'), 'live renderer must not use DOM repair observers');
assert.ok(!liveJs.includes('setInterval('), 'live renderer must not add polling');
assert.ok(!liveJs.includes('setTimeout('), 'live renderer must not use timer cleanup');

for (const canonical of [
  'mgw-domino-v18-precision-flight',
  'mgw-domino-v18-chain-kick-left',
  'mgw-domino-v18-chain-kick-mid',
  'mgw-domino-v18-precision-wave',
  'mgw-domino-v18-precision-spark',
  'mgw-domino-v18-stock-flight',
  'mgw-domino-v18-stock-back',
  'mgw-domino-v18-stock-face',
  'mgw-domino-v18-stock-source-pulse',
  'mgw-domino-v18-stock-land-glow',
  'mgw-domino-v18-cascade-1',
  'mgw-domino-v18-cascade-2',
  'mgw-domino-v18-cascade-3',
  'mgw-domino-v18-cascade-4',
  'mgw-domino-v18-cascade-5',
  'mgw-domino-v18-finish-dust',
]) {
  assert.ok(timing.includes(`@keyframes ${canonical}`), `accepted timing owner must keep ${canonical}`);
  assert.ok(!liveCss.includes(`@keyframes ${canonical}`), `live bridge must not duplicate ${canonical}`);
}
assert.ok(!liveCss.includes('@keyframes'), 'live CSS must not own a second animation timeline');
assert.ok(liveCss.includes('animation-iteration-count:1!important'), 'live bridge may only make the accepted loop one-shot');
assert.ok(liveCss.includes('width:min(var(--mgw-domino-live-scene-width,230px),92vw)!important'), 'live scene width must derive from real tile geometry');
assert.ok(liveCss.includes('transform:translate(-61.968%,-57.216%)'), 'precision anchor must use final v16/v18/v19 contact geometry');
assert.ok(liveCss.includes('transform:translate(-74.2%,-41.904%)'), 'mirrored stock source must use final v16/v18 source geometry');
assert.ok(liveCss.includes('transform:scaleX(-1)!important'), 'stock live coordinate adaptation must send the accepted flight inward from top-right stock');
assert.ok(liveCss.includes('body[data-mgw-domino-finale] #sheetOverlay'), 'result sheet must not cover the accepted 3.5s finale');
assert.ok(!liveCss.includes(':has('), 'live result-sheet gate must not depend on :has support');
assert.ok(alignment.includes('top:58.2%!important'), 'accepted precision v19 alignment must remain canonical');
assert.ok(visual.includes('left:63.6%!important;top:59%!important'), 'accepted v16 precision contact x must remain canonical');
assert.ok(visual.includes('left:9%!important;'), 'accepted v16 stock source left edge must remain canonical');
assert.ok(visual.includes('width:27%!important;'), 'accepted v16 horizontal effect piece width must remain canonical');
assert.ok(visual.includes('width:12%!important;'), 'accepted v16 finale piece width must remain canonical');

assert.ok(store.includes('data-mgw-domino-fx-v14'), 'accepted Store primitive signature must stay present');
assert.ok(index.includes('data-mgw-domino-effects-visual-parity="v16"'), 'active shell must load accepted v16 visual parity');
assert.ok(index.includes('data-mgw-domino-effects-timing-parity="v18"'), 'active shell must load accepted v18 timing parity');
assert.ok(index.includes('data-mgw-domino-effects-precision-alignment="v19"'), 'active shell must load accepted v19 precision alignment');
assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-cosmetics-v1.js?v=1&mvp19_9=accepted-preview-live-v1'"), 'active import graph must route Domino through live cosmetics');
assert.match(launch, /\/app\/v110\.php\?v=1181/);

console.log('MVP-19.9 Domino live effects contract: OK');