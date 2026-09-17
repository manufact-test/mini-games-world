import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const liveJs = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const correctiveJs = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25.js');
const nativeCss = read('app/assets/css/games/domino/live-native-effects-v1.css');
const correctiveCss = read('app/assets/css/games/domino/live-native-manual-v25.css');
const handGestureCss = read('app/assets/css/games/domino/live-hand-gesture-v27.css');
const liveCosmeticsCss = read('app/assets/css/games/domino/live-cosmetics-v2.css');
const migration = read('bot/database/migrations/20260915_0035_add_domino_store_cosmetics.php');
const manifest = read('app/runtime/client/version-manifest.php');
const entry = read('app/v110.php');
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

assert.ok(correctiveJs.includes("hand.dataset.dominoHandDrag = 'v26'"), 'legacy real-hand drag owner must remain available');
assert.ok(correctiveJs.includes("hand.dataset.dominoHandGesture = 'v27'"), 'v27 gesture marker must remain available');
assert.ok(correctiveJs.includes("container.dataset.mgwDominoHandPointer = 'v28'"), 'stable gameBoard pointer owner must be active');
assert.ok(correctiveJs.includes('const pointerOwners = new WeakMap()'), 'stable pointer ownership must bind once per gameBoard');
assert.ok(correctiveJs.includes("container.addEventListener('pointerdown'"), 'stable gameBoard must own pointerdown');
assert.ok(correctiveJs.includes("container.addEventListener('pointermove'"), 'stable gameBoard must own pointermove');
assert.ok(correctiveJs.includes('container.setPointerCapture(drag.pointerId)'), 'horizontal pointer intent must use capture');
assert.ok(correctiveJs.includes('hand.scrollLeft = next'), 'drag owner must still be able to move a scrollable hand');
assert.ok(correctiveJs.includes('event.stopImmediatePropagation()'), 'completed drag must not accidentally play a tile');
assert.ok(correctiveJs.includes('live-hand-gesture-v27.css?v=2&mvp19_9=hand-gesture-owner-v27&hand_layout=v29'), 'legacy v29 hand fallback stylesheet must remain available beneath v30');

assert.ok(correctiveCss.includes('touch-action:pan-x pan-y!important'), 'outer Domino content may remain a normal two-axis browser surface');
assert.ok(handGestureCss.includes('touch-action:pan-y!important'), 'browser must retain vertical panning around the real hand');
assert.ok(!handGestureCss.includes('touch-action:pan-x'), 'hand must not advertise horizontal native panning back to Chromium');
assert.ok(handGestureCss.includes('.domino-hand:has(> .domino-hand-tile:nth-child(9))'), 'legacy 9+ visibility fallback must remain available');
assert.ok(handGestureCss.includes('flex-wrap:wrap!important'), 'legacy 9+ fallback must still wrap when supported');
assert.ok(handGestureCss.includes('overflow-x:visible!important'), 'legacy 9+ fallback must not hide the playable tail off-screen');
assert.ok(correctiveCss.includes('mgw-domino-native-precision-tile-v25'), 'precision corrective must visibly strengthen the real placed tile impact');
assert.ok(correctiveCss.includes('mgw-domino-native-precision-shock-v25'), 'precision corrective must expose a readable contact shockwave');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-source-v25'), 'stock corrective must visibly pulse the real stock owner');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-orb-v25'), 'stock corrective must provide a readable travelling energy core');
assert.ok(correctiveCss.includes('mgw-domino-native-stock-arrival-v25'), 'stock corrective must visibly resolve at the real hand destination');
assert.ok(!correctiveCss.includes('.store-v2-game-preview'), 'corrective CSS must not style a Store preview scene');
assert.ok(!correctiveCss.includes('.mgw-domino-preview'), 'corrective CSS must not contain miniature board selectors');

assert.ok(liveCosmeticsCss.includes('overflow-x:auto!important'), 'ordinary hand horizontal overflow owner must remain preserved beneath v30');
assert.ok(liveCosmeticsCss.includes('overflow-y:auto!important'), 'mobile Domino vertical scroll must remain preserved');
assert.ok(liveCosmeticsCss.includes('height:100dvh!important'), 'bounded Telegram viewport owner must remain preserved');

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-live-manual-v30.js?v=1&mvp19_9=manual-stability-v30&parent=manual-corrective-v25'"), 'manifest must publish the v30 Domino stability owner');
assert.ok(entry.includes("$imports[$dominoRendererImportKey] .= '&gesture_owner=v27';"), 'active entry must remain canonical v110 and keep its accepted gesture hook');
assert.ok(entry.includes("header('X-MGW-Domino-Hand-Gesture: v27-pan-y-js-horizontal');"), 'active entry must retain the existing Domino diagnostic header');
assert.match(launch, /\/app\/v110\.php\?v=1191&domino_stability=30/);

console.log('MVP-19.9 Domino legacy effects + v30 live stability contract: OK');
