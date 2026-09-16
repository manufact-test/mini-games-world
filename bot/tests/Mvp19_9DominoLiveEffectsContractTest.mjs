import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const liveJs = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const correctiveJs = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25.js');
const nativeCss = read('app/assets/css/games/domino/live-native-effects-v1.css');
const correctiveCss = read('app/assets/css/games/domino/live-native-manual-v25.css');
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
assert.ok(liveJs.includes("container.dataset.mgwDominoNativeEffects = 'v1'"), 'native effect owner marker must stay active');
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
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-precision'"), 'precision may use only a real-contact accent');
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-stock'"), 'stock may use only a path accent between real owners');
assert.ok(liveJs.includes("accent.className = 'domino-native-fx-accent is-finale'"), 'finale may use only a board glow accent around the real table');

for (const forbidden of ['dominoPreviewMarkup', 'store-screen-domino-store', 'store-v2-game-preview', 'mgw-domino-preview', 'MutationObserver', 'setInterval(', 'setTimeout(']) {
  assert.ok(!liveJs.includes(forbidden), `base native LIVE must not contain ${forbidden}`);
  assert.ok(!correctiveJs.includes(forbidden), `corrective LIVE must not contain ${forbidden}`);
}
assert.ok(liveJs.includes('animationend'), 'transient native accents must clean up from animation lifecycle');
assert.ok(liveJs.includes('animationcancel'), 'cancelled native effects must clean up safely');
assert.ok(correctiveJs.includes('animationend'), 'QA finale must clean up from animation lifecycle');
assert.ok(correctiveJs.includes('animationcancel'), 'QA finale cancellation must clean up safely');

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

assert.ok(correctiveJs.includes("container.dataset.mgwDominoManualCorrective = 'v25'"), 'manual corrective runtime marker must be active');
assert.ok(correctiveJs.includes("button.dataset.dominoFinaleQa = 'preview'"), 'finale must expose a temporary local QA trigger');
assert.ok(correctiveJs.includes("String(game?.status || '') !== 'active'"), 'finale QA trigger must only exist during an active game');
assert.ok(correctiveJs.includes("viewerEffect(game, me) !== FINALE_ID"), 'finale QA trigger must only exist for the equipped finale owner');
assert.ok(correctiveJs.includes("container.querySelectorAll('.domino-chain-slot .domino-tile')"), 'finale QA must animate the current real chain');
assert.ok(!correctiveJs.includes('onAction'), 'finale QA must not mutate authoritative game state');

assert.ok(correctiveJs.includes("hand.dataset.dominoHandDrag = 'v26'"), 'real hand must expose the v26 drag owner');
assert.ok(correctiveJs.includes("hand.addEventListener('touchmove'"), 'real hand must own the touchmove gesture');
assert.ok(correctiveJs.includes('{ passive:false }'), 'horizontal touchmove must be non-passive so Telegram cannot steal the gesture');
assert.ok(correctiveJs.includes('event.preventDefault()'), 'horizontal drag must cancel native nested-scroll ownership after direction lock');
assert.ok(correctiveJs.includes('handScrollLeft'), 'hand horizontal position must survive polling rerenders for the same game');
assert.ok(correctiveJs.includes('suppressNextClick'), 'a completed drag must not accidentally play the tile under the finger');

assert.ok(correctiveCss.includes('touch-action:pan-x pan-y!important'), 'Telegram nested gesture ownership must allow horizontal hand swipes');
assert.ok(correctiveCss.includes('mgw-domino-native-precision-tile-v25'), 'precision corrective must visibly strengthen the real placed tile impact');
assert.ok(correctiveCss.includes('mgw-domino-native-precision-shock-v25'), 'precision corrective must expose a readable contact shockwave');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-source-v25'), 'stock corrective must visibly pulse the real stock owner');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-orb-v25'), 'stock corrective must provide a readable travelling energy core');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-arrival-v25'), 'stock corrective must visibly resolve at the real hand destination');
assert.ok(!correctiveCss.includes('.store-v2-game-preview'), 'corrective CSS must not style a Store preview scene');
assert.ok(!correctiveCss.includes('.mgw-domino-preview'), 'corrective CSS must not contain miniature board selectors');

assert.ok(liveCosmeticsCss.includes('overflow-x:auto!important'), 'large hand horizontal overflow owner must remain preserved');
assert.ok(liveCosmeticsCss.includes('overflow-y:auto!important'), 'mobile Domino vertical scroll must remain preserved');
assert.ok(liveCosmeticsCss.includes('height:100dvh!important'), 'bounded Telegram viewport owner must remain preserved');

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-cosmetics-corrective-v25.js?v=1&mvp19_9=manual-corrective-v25&hand_drag=v26'"), 'active import graph must route Domino through corrective v25 runtime with v26 hand-drag identity');
assert.ok(correctiveJs.includes('live-native-manual-v25.css?v=1&mvp19_9=manual-corrective-v25&hand_drag=v26'), 'corrective runtime must publish the fresh v26 stylesheet identity');
assert.match(launch, /\/app\/v110\.php\?v=1188&hand_drag=26/);

console.log('MVP-19.9 Domino manual corrective v25 + hand drag v26 contract: OK');
