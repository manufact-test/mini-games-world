import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');

const liveJs = read('app/assets/js/games/domino/renderer-cosmetics-v1.js');
const pointerJs = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25.js');
const correctiveJs = read('app/assets/js/games/domino/renderer-cosmetics-corrective-v25-base.js');
const nativeCss = read('app/assets/css/games/domino/live-native-effects-v1.css');
const correctiveCss = read('app/assets/css/games/domino/live-native-manual-v25.css');
const handGestureCss = read('app/assets/css/games/domino/live-hand-gesture-v27.css');
const liveCosmeticsCss = read('app/assets/css/games/domino/live-cosmetics-v2.css');
const migration = read('bot/database/migrations/20260915_0035_add_domino_store_cosmetics.php');
const manifest = read('app/runtime/client/version-manifest.php');
const entryV110 = read('app/v110.php');
const entryV111 = read('app/v111.php');
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
assert.ok(liveJs.includes("container.querySelector('.domino-stock-count')"), 'stock must target the real stock indicator');
assert.ok(liveJs.includes("container.querySelectorAll('.domino-chain-slot .domino-tile')"), 'finale must target the real rendered chain');

for (const forbidden of ['dominoPreviewMarkup', 'store-screen-domino-store', 'store-v2-game-preview', 'mgw-domino-preview', 'MutationObserver', 'setInterval(', 'setTimeout(']) {
  for (const [name, source] of [['base native LIVE', liveJs], ['v25/v27 compatibility base', correctiveJs], ['v28 pointer owner', pointerJs]]) {
    assert.ok(!source.includes(forbidden), `${name} must not contain ${forbidden}`);
  }
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
]) assert.ok(nativeCss.includes(`@keyframes ${animation}`), `native CSS must own ${animation}`);

assert.ok(correctiveJs.includes("container.dataset.mgwDominoManualCorrective = 'v25'"), 'v25 corrective marker must remain in compatibility base');
assert.ok(correctiveJs.includes("button.dataset.dominoFinaleQa = 'preview'"), 'finale must keep the temporary local QA trigger');
assert.ok(correctiveJs.includes("String(game?.status || '') !== 'active'"), 'finale QA trigger must only exist during an active game');
assert.ok(correctiveJs.includes("viewerEffect(game, me) !== FINALE_ID"), 'finale QA trigger must only exist for the equipped finale owner');
assert.ok(correctiveJs.includes("container.querySelectorAll('.domino-chain-slot .domino-tile')"), 'finale QA must animate the current real chain');
assert.ok(!correctiveJs.includes('onAction'), 'finale QA must not mutate authoritative game state');

assert.ok(correctiveJs.includes("hand.dataset.dominoHandDrag = 'v26'"), 'v26 touch compatibility owner must remain intact inside the frozen base');
assert.ok(correctiveJs.includes("hand.dataset.dominoHandGesture = 'v27'"), 'v27 pan-y compatibility marker must remain intact inside the frozen base');
assert.ok(correctiveJs.includes("hand.addEventListener('touchmove'"), 'legacy touch compatibility path must remain available');
assert.ok(correctiveJs.includes('handScrollLeft'), 'hand position must still survive ordinary polling rerenders');

assert.ok(pointerJs.includes("container.dataset.mgwDominoHandPointer = 'v28'"), 'stable gameBoard owner must expose the v28 marker');
assert.ok(pointerJs.includes("hand.dataset.dominoHandPointer = 'v28'"), 'current real hand must expose the v28 marker');
assert.ok(pointerJs.includes('const pointerOwners = new WeakMap()'), 'v28 must bind one owner per stable gameBoard container');
assert.ok(pointerJs.includes("container.addEventListener('pointerdown'"), 'stable gameBoard must own pointerdown');
assert.ok(pointerJs.includes("container.addEventListener('pointermove'"), 'stable gameBoard must own pointermove');
assert.ok(pointerJs.includes('container.setPointerCapture(drag.pointerId)'), 'horizontal direction lock must capture the active pointer');
assert.ok(pointerJs.includes('hand.scrollLeft = next'), 'v28 must directly move the real hand scroll position');
assert.ok(pointerJs.includes('!hand.isConnected'), 'v28 must recover if polling replaces the hand during an active gesture');
assert.ok(pointerJs.includes('event.stopImmediatePropagation()'), 'a completed swipe must not accidentally play a tile');
assert.ok(!pointerJs.includes("hand.addEventListener('pointermove'"), 'v28 ownership must not be attached to the replaceable hand node');

assert.ok(correctiveCss.includes('touch-action:pan-x pan-y!important'), 'outer Domino content may remain a normal two-axis browser surface');
assert.ok(handGestureCss.includes('touch-action:pan-y!important'), 'browser must retain vertical panning while JS owns horizontal hand drag');
assert.ok(!handGestureCss.includes('touch-action:pan-x'), 'hand must not advertise horizontal native panning back to Chromium');
assert.ok(liveCosmeticsCss.includes('overflow-x:auto!important'), 'large hand horizontal overflow owner must remain preserved');
assert.ok(liveCosmeticsCss.includes('overflow-y:auto!important'), 'mobile Domino vertical scroll must remain preserved');
assert.ok(liveCosmeticsCss.includes('height:100dvh!important'), 'bounded Telegram viewport owner must remain preserved');

assert.ok(manifest.includes("'./assets/js/games/domino/renderer.js?v=74' => './assets/js/games/domino/renderer-cosmetics-corrective-v25.js?v=1&mvp19_9=manual-corrective-v25&hand_drag=v26'"), 'manifest must keep the accepted corrective route');
assert.ok(entryV110.includes("$imports[$dominoRendererImportKey] .= '&gesture_owner=v27';"), 'v110 must remain the accepted v27 base entry');
assert.ok(entryV111.includes("$replacement = $needle . '&pointer_owner=v28';"), 'v111 must publish a fresh v28 module identity');
assert.ok(entryV111.includes("X-MGW-Domino-Hand-Pointer: v28-stable-container-capture"), 'v111 must identify the stable pointer owner');
assert.match(launch, /\/app\/v111\.php\?v=1189&hand_gesture=27&pointer_owner=28/);

console.log('MVP-19.9 Domino v25 effects + v28 stable pointer owner contract: OK');
