import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const liveJs = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const nativeCss = read('app/assets/css/games/domino/live-native-effects-v1.css');
const liveCosmeticsCss = read('app/assets/css/games/domino/live-cosmetics-v2.css');
const migration = read('bot/database/migrations/20260915_0035_add_domino_store_cosmetics.php');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');

for (const itemId of [
  'game-domino-effect-precision-drop',
  'game-domino-effect-stock-pulse',
  'game-domino-effect-chain-finale',
]) {
  assert.ok(liveJs.includes(itemId), `LIVE renderer must know ${itemId}`);
  assert.ok(migration.includes(itemId), `catalog migration must keep ${itemId}`);
}

assert.ok(liveJs.includes("container.dataset.mgwDominoLiveCosmetics = 'full-v4'"), 'accepted table/tile live cosmetic owner must stay active');
assert.ok(liveJs.includes("container.dataset.mgwDominoNativeEffects = 'v1'"), 'native effect owner marker must be active');
assert.ok(liveJs.includes('state?.profileInventory?.equipped'), 'viewer effect must resolve from current equipped inventory');
assert.ok(liveJs.includes("actionType === 'play' && actorEffect === PRECISION_ID"), 'precision must bind to real play event');
assert.ok(liveJs.includes("actionType === 'draw' && actorEffect === STOCK_ID"), 'stock must bind to real draw event');
assert.ok(liveJs.includes("status || '') === 'finished' && finishEffect === FINALE_ID"), 'finale must bind to terminal state');
assert.ok(liveJs.includes('seenEventByGame'), 'polling rerenders must not replay an authoritative event');
assert.ok(liveJs.includes("container.querySelector('.domino-chain-slot.latest')"), 'precision must target the real latest chain slot');
assert.ok(liveJs.includes("latestSlot?.querySelector('.domino-tile')"), 'precision must target the real placed tile');
assert.ok(liveJs.includes("container.querySelector('.domino-stock-count')"), 'stock must target the real stock indicator');
assert.ok(liveJs.includes("container.querySelectorAll('.domino-hand-tile')"), 'stock must target real hand tiles');
assert.ok(liveJs.includes("container.querySelectorAll('.domino-chain-slot .domino-tile')"), 'finale must target the real rendered chain');
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-precision'"), 'precision may use only a small real-contact accent');
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-stock'"), 'stock may use only a path accent between real owners');
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-finale'"), 'finale may use only a board glow accent around the real table');

assert.ok(!liveJs.includes('dominoPreviewMarkup'), 'LIVE must not reuse Store preview markup');
assert.ok(!liveJs.includes('store-screen-domino-store'), 'LIVE must not import Store preview renderer');
assert.ok(!liveJs.includes('store-v2-game-preview'), 'LIVE must not create Store preview scenes');
assert.ok(!liveJs.includes('mgw-domino-preview'), 'LIVE must not create miniature preview board markup');
assert.ok(!liveJs.includes('MutationObserver'), 'LIVE must not use repair observers');
assert.ok(!liveJs.includes('setInterval('), 'LIVE must not add polling loops');
assert.ok(!liveJs.includes('setTimeout('), 'product runtime must use animation lifecycle, not timer cleanup');
assert.ok(liveJs.includes('animationend'), 'transient accents must clean up from animation lifecycle');
assert.ok(liveJs.includes('animationcancel'), 'cancelled effects must also clean up safely');

for (const animation of [
  'mgw-domino-native-precision-tile',
  'mgw-domino-native-precision-ring',
  'mgw-domino-native-stock-source',
  'mgw-domino-native-stock-target',
  'mgw-domino-native-stock-orb',
  'mgw-domino-native-finale-tile',
  'mgw-domino-native-finale-sweep',
  'mgw-domino-native-finale-ring',
]) {
  assert.ok(nativeCss.includes(`@keyframes ${animation}`), `native CSS must own ${animation}`);
}
assert.ok(nativeCss.includes('.domino-chain-slot.latest .domino-tile.mgw-domino-native-precision-tile'), 'precision CSS must animate the real placed tile');
assert.ok(nativeCss.includes('.domino-stock-count.mgw-domino-native-stock-source'), 'stock CSS must animate the real stock');
assert.ok(nativeCss.includes('.domino-hand-tile.mgw-domino-native-stock-target .domino-tile'), 'stock CSS must animate the real drawn hand tile');
assert.ok(nativeCss.includes('.domino-chain-slot .domino-tile.mgw-domino-native-finale-tile'), 'finale CSS must animate real chain tiles');
assert.ok(nativeCss.includes('body[data-mgw-domino-finale] #sheetOverlay'), 'result sheet must remain behind premium finale');
assert.ok(!nativeCss.includes('.store-v2-game-preview'), 'native LIVE CSS must not style a Store preview scene');
assert.ok(!nativeCss.includes('.mgw-domino-preview'), 'native LIVE CSS must not contain miniature board selectors');

assert.ok(liveCosmeticsCss.includes('overflow-x:auto!important'), 'large hand horizontal swipe must remain preserved');
assert.ok(liveCosmeticsCss.includes('overflow-y:auto!important'), 'mobile Domino vertical scroll must remain preserved');
assert.ok(liveCosmeticsCss.includes('height:100dvh!important'), 'bounded Telegram viewport owner must remain preserved');

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-cosmetics-v1.js?v=7&mvp19_9=live-native-effects-v1'"), 'active import graph must route Domino through native effect runtime v1');
assert.ok(liveJs.includes('live-native-effects-v1.css?v=1&mvp19_9=live-native-v1'), 'native runtime must publish a fresh native stylesheet identity');
assert.match(launch, /\/app\/v110\.php\?v=1187/);

console.log('MVP-19.9 Domino LIVE-native effects v1 contract: OK');
