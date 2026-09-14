import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-reversi-parity.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-reversi-store-parity-v1.css');
const tabFixCssPath = path.join(root, 'app/assets/css/screens/profile-reversi-tabs-touch-fix-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const liveRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const tabFixCss = fs.readFileSync(tabFixCssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const liveRenderer = fs.readFileSync(liveRendererPath, 'utf8');
const launch = fs.readFileSync(launchPath, 'utf8');

const itemIds = [
  'game-reversi-field-green',
  'game-reversi-field-dark',
  'game-reversi-field-marble',
  'game-reversi-field-neon',
  'game-reversi-pieces-classic',
  'game-reversi-pieces-marble',
  'game-reversi-pieces-metal',
  'game-reversi-pieces-neon',
  'game-reversi-effect-placement',
  'game-reversi-effect-line',
  'game-reversi-effect-mass-flip',
];

for (const id of itemIds) assert.ok(profile.includes(`'${id}'`), `Profile parity must know ${id}`);
for (const group of ['Поля','Фишки','Эффекты']) assert.ok(profile.includes(group), `Profile must expose ${group}`);
for (const layer of ['theme','elements','effect']) assert.ok(profile.includes(layer), `Profile must support ${layer}`);
for (const variant of ['green','dark','marble','neon','classic','metal','placement','line','mass-flip']) {
  assert.ok(profile.includes(variant), `Profile preview must support ${variant}`);
}

assert.ok(profile.includes("item.owned === true"), 'Profile must render owned Reversi cosmetics only');
assert.ok(profile.includes("state.profileInventory"), 'Profile must use authoritative profile inventory');
assert.ok(profile.includes("inventory.equipped"), 'Profile must derive selected state from equipped slots');
assert.ok(profile.includes("data-profile-game-cosmetic"), 'Profile cards must preserve the canonical game cosmetic action contract');
assert.ok(profile.includes("mgwGameCosmeticEquip"), 'Profile parity must repair after canonical equip/unequip action');
assert.ok(profile.includes("api.profileV2"), 'Profile parity must converge after authoritative profile refresh');
assert.ok(profile.includes("data-game-type=\"reversi\""), 'Profile previews must identify themselves as Reversi previews');
assert.ok(profile.includes("store-cosmetics-v1.css"), 'Profile must reuse accepted Reversi Store cosmetic artwork CSS');

assert.ok(layout.includes("mgw-profile-reversi-parity.js?v=2&mvp19_7=reversi-profile-parity-v1&card_geometry=full-square-v2"), 'Accepted Profile wrapper must cache-bust the Reversi parity child module');
assert.ok(layout.includes('initProfileReversiParity();'), 'Accepted Profile wrapper must initialize Reversi parity');
assert.ok(layout.includes('prepareProfileGameTabInputMode();'), 'Active Profile wrapper must own game-tab input before shared parity initialization');
assert.ok(layout.includes("screen.dataset.mgwGameTabsScroller = '1'"), 'Active wrapper must prevent the legacy eager-capture drag helper from installing');
assert.ok(layout.includes("screen.dataset.mgwGameTabsScrollerMode = 'delayed-capture-v2'"), 'Active wrapper must publish the delayed-capture input owner');
assert.ok(layout.includes('PROFILE_GAME_TAB_DRAG_THRESHOLD = 5'), 'Profile tab drag must keep an explicit movement threshold');
assert.ok(layout.includes("event.pointerType !== 'mouse'"), 'Touch pointers must remain on native overflow scrolling');
assert.ok(layout.includes('Math.abs(delta) < PROFILE_GAME_TAB_DRAG_THRESHOLD'), 'Pointer movement below the threshold must remain a click candidate');
assert.ok(layout.includes('drag.moved = true;'), 'Desktop drag must only activate after the threshold is crossed');
assert.ok(layout.includes('drag.strip.setPointerCapture?.(event.pointerId);'), 'Pointer capture must still protect an actual desktop drag');
assert.ok(layout.indexOf('drag.moved = true;') < layout.indexOf('drag.strip.setPointerCapture?.(event.pointerId);'), 'Pointer capture must happen only after drag activation, never on pointerdown');
assert.ok(layout.includes('suppressProfileGameTabClick = drag.moved;'), 'Only a real drag may suppress its trailing tab click');
assert.ok(layout.includes('suppressProfileGameTabClick = false;'), 'Suppression must clear so the next genuine tab tap cannot stay blocked');
assert.ok(layout.includes('profile-reversi-tabs-touch-fix-v1.css'), 'Active Profile wrapper must load the Reversi tab/touch corrective CSS');
assert.ok(layout.includes('profile-reversi-store-parity-v1.css?v=2&mvp19_7=full-card-store-square-v2'), 'Active Profile wrapper must force the accepted full-square Reversi Profile stylesheet');
assert.ok(manifest.includes('mgw-profile-chess-layout-v2.js?v=15'), 'Active Profile owner must remain the accepted cache-busted layout wrapper');
assert.ok(manifest.includes('mvp19_7=reversi-profile-parity-v1'), 'Active profile URL must retain Reversi Profile parity');
assert.ok(manifest.includes('profile_tabs=delayed-capture-v2'), 'Active profile URL must publish the delayed-capture tab owner');
assert.ok(manifest.includes('reversi_tab_mark=separated-v1'), 'Active profile URL must retain the separated Reversi tab mark');
assert.ok(manifest.includes('reversi_cards=store-square-v2'), 'Active profile URL must retain the full-square card identity');
assert.ok(launch.includes('/app/v110.php?v=1128'), 'Telegram launch URL must force a fresh v110 document for the Reversi Profile cache-chain corrective');

assert.ok(css.includes('data-profile-game-tab="reversi"'), 'Reversi Profile tab needs dedicated mark styling');
assert.ok(css.includes('data-profile-game-panel="reversi"'), 'Reversi Profile card geometry must be scoped to the active Reversi panel');
assert.ok(css.includes('aspect-ratio:1 / 1!important'), 'Reversi Profile card previews must keep a strict square media canvas');
assert.ok(css.includes('height:auto!important'), 'Reversi Profile cards must override the legacy fixed preview height');
assert.ok(css.includes('max-height:none!important'), 'Reversi Profile cards must not inherit a compact max-height');
assert.ok(css.includes('flex-direction:column!important'), 'Reversi Profile card shell must stack full preview and label vertically');
assert.ok(css.includes('data-game-type="reversi"'), 'Reversi Profile CSS must scope to Reversi previews');
assert.ok(tabFixCss.includes('width:11px!important'), 'Reversi tab discs must be smaller than the shared 24px mark lane so they do not overlap');
assert.ok(tabFixCss.includes('left:0!important'), 'Black Reversi tab disc must anchor to the left edge');
assert.ok(tabFixCss.includes('right:0!important'), 'White Reversi tab disc must anchor to the right edge');
assert.ok(tabFixCss.includes('touch-action:pan-x pan-y!important'), 'Touch rail must keep native horizontal and vertical gesture arbitration');
assert.ok(storeCss.includes('.mgw-reversi-preview.theme-marble'), 'Accepted Store marble artwork must remain available to Profile');
assert.ok(storeCss.includes('.mgw-reversi-preview.pieces-classic'), 'Accepted Store Classic piece artwork must remain available to Profile');
assert.ok(storeCss.includes('.mgw-reversi-preview.effect-mass-flip'), 'Accepted Store effects must remain available to Profile');

assert.ok(!profile.includes('gameAction('), 'Profile parity must not own gameplay actions');
assert.ok(!profile.includes('last_flipped_cells'), 'Profile parity must not own live Reversi flip state');
assert.ok(liveRenderer.includes('last_flipped_cells'), 'Live Reversi renderer stays the gameplay owner');

console.log(`MVP-19.7 Reversi Profile parity contract passed (${itemIds.length} catalogue items).`);
