import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-reversi-parity.js');
const hardSquarePath = path.join(root, 'app/assets/js/profile/mgw-profile-reversi-hard-square-v1.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-reversi-store-parity-v1.css');
const exactCssPath = path.join(root, 'app/assets/css/screens/profile-reversi-store-exact-v2.css');
const tabFixCssPath = path.join(root, 'app/assets/css/screens/profile-reversi-tabs-touch-fix-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const liveRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const hardSquare = fs.readFileSync(hardSquarePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const exactCss = fs.readFileSync(exactCssPath, 'utf8');
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
assert.ok(layout.includes("mgw-profile-reversi-hard-square-v1.js?v=1&mvp19_7=profile-hard-square-v1"), 'Accepted Profile wrapper must import the Reversi hard-square runtime');
assert.ok(layout.includes('initProfileReversiParity();'), 'Accepted Profile wrapper must initialize Reversi parity');
assert.ok(layout.includes('initProfileReversiHardSquare();'), 'Accepted Profile wrapper must initialize the Reversi hard-square runtime');
assert.ok(layout.indexOf('initProfileReversiParity();') < layout.indexOf('initProfileReversiHardSquare();'), 'Reversi parity markup must exist before hard-square repair initializes');
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
assert.ok(layout.includes('profile-reversi-store-exact-v2.css?v=1&mvp19_7=direct-card-full-square-v1'), 'Active Profile wrapper must load the final direct Reversi card owner');
assert.ok(layout.includes('ensureProfileReversiStoreExactStyles();'), 'Direct Reversi card owner must be installed by the active wrapper');
assert.ok(layout.indexOf('ensureProfileCheckersStoreExactStyles();') < layout.indexOf('ensureProfileReversiStoreExactStyles();'), 'Reversi exact owner must be loaded after shared/checkers repair layers');

assert.ok(hardSquare.includes('getBoundingClientRect().width'), 'Hard-square runtime must measure the live rendered Reversi card width');
assert.ok(hardSquare.includes("preview.dataset.mgwProfileReversiHardSquare = '1'"), 'Hard-square runtime must mark repaired Reversi previews');
assert.ok(hardSquare.includes("data-game-type=\"reversi\""), 'Hard-square runtime must target Reversi previews only');
assert.ok(hardSquare.includes("setImportant(preview, 'height', px)"), 'Hard-square runtime must force preview height to the measured width');
assert.ok(hardSquare.includes("setImportant(preview, 'min-height', px)"), 'Hard-square runtime must defeat the legacy 76/69px minimum height');
assert.ok(hardSquare.includes("setImportant(preview, 'aspect-ratio', '1 / 1')"), 'Hard-square runtime must force a square card canvas');
assert.ok(hardSquare.includes("element.style.setProperty(property, value, 'important')"), 'Hard-square runtime must use inline important dimensions like accepted Checkers');
assert.ok(hardSquare.includes("querySelector(':scope > .mgw-reversi-preview')"), 'Hard-square runtime must size the Store Reversi artwork primitive');
assert.ok(hardSquare.includes("querySelector(':scope > .mgw-rv-board')"), 'Hard-square runtime must preserve the complete Reversi board');
assert.ok(hardSquare.includes("document.addEventListener('mgw:open-profile'"), 'Hard-square runtime must repair after Profile opens');
assert.ok(hardSquare.includes("document.addEventListener('mgw:cosmetic-inventory-changed'"), 'Hard-square runtime must repair after inventory changes');

assert.ok(manifest.includes('mgw-profile-chess-layout-v2.js?v=17'), 'Active Profile owner must use a fresh cache identity for the Reversi hard-square runtime');
assert.ok(manifest.includes('mvp19_7=reversi-profile-parity-v1'), 'Active profile URL must retain Reversi Profile parity');
assert.ok(manifest.includes('profile_tabs=delayed-capture-v2'), 'Active profile URL must publish the delayed-capture tab owner');
assert.ok(manifest.includes('reversi_tab_mark=separated-v1'), 'Active profile URL must retain the separated Reversi tab mark');
assert.ok(manifest.includes('reversi_cards=direct-exact-v1'), 'Active profile URL must retain the direct exact card owner');
assert.ok(manifest.includes('reversi_card_runtime=hard-square-v1'), 'Active profile URL must publish the Reversi hard-square runtime');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1130, 'Telegram launch must remain at or beyond the accepted fresh hard-square Profile chain');

assert.ok(css.includes('data-profile-game-tab="reversi"'), 'Reversi Profile tab needs dedicated mark styling');
assert.ok(exactCss.includes('#screen-profile .profile-v2-game-card > .store-v2-game-preview[data-game-type="reversi"]'), 'Final Reversi owner must target the card directly like accepted Checkers');
assert.ok(!exactCss.includes('data-profile-game-panel="reversi"'), 'Final Reversi card geometry must not depend on transient panel metadata');
assert.ok(exactCss.includes('aspect-ratio:1 / 1!important'), 'Direct Reversi card previews must keep a strict square media canvas');
assert.ok(exactCss.includes('height:auto!important'), 'Direct Reversi card owner must override the legacy fixed preview height');
assert.ok(exactCss.includes('min-height:0!important'), 'Direct Reversi card owner must neutralize the legacy 76/69px minimum height');
assert.ok(exactCss.includes('> .mgw-reversi-preview'), 'Direct Reversi card owner must size the Store artwork primitive itself');
assert.ok(exactCss.includes('.mgw-rv-board'), 'Direct Reversi card owner must preserve the complete square board');
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

console.log(`MVP-19.7 Reversi Profile parity contract passed (${itemIds.length} catalogue items, hard-square runtime active).`);
