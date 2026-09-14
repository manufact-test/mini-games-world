import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-go-parity.js');
const hardSquarePath = path.join(root, 'app/assets/js/profile/mgw-profile-go-hard-square-v1.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-go-store-parity-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/go/store-cosmetics-v1.css');
const storeWrapperPath = path.join(root, 'app/assets/js/screens/store-screen-go-store-v1.js');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const migrationPath = path.join(root, 'bot/database/migrations/20260914_0034_add_go_store_cosmetics.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const hardSquare = fs.readFileSync(hardSquarePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const storeWrapper = fs.readFileSync(storeWrapperPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const migration = fs.readFileSync(migrationPath, 'utf8');

const itemIds = [
  'game-go-board-wood',
  'game-go-board-dark',
  'game-go-board-stone',
  'game-go-board-neon',
  'game-go-stones-classic',
  'game-go-stones-marble',
  'game-go-stones-glass',
  'game-go-stones-neon',
  'game-go-effect-placement',
  'game-go-effect-group-capture',
  'game-go-effect-territory-finish',
];

for (const id of itemIds) {
  assert.ok(profile.includes(`'${id}'`), `Profile parity must know ${id}`);
  assert.ok(migration.includes(`'${id}'`), `Catalog migration must still own ${id}`);
}
for (const group of ['Доски','Камни','Эффекты']) assert.ok(profile.includes(group), `Profile must expose ${group}`);
for (const variant of ['wood','dark','stone','neon','classic','marble','glass','placement','group-capture','territory-finish']) {
  assert.ok(profile.includes(variant), `Profile preview must support ${variant}`);
}

assert.ok(profile.includes("item.owned === true"), 'Profile must render owned Go cosmetics only');
assert.ok(profile.includes("state.profileInventory"), 'Profile must use authoritative profile inventory');
assert.ok(profile.includes("inventory.equipped"), 'Profile must derive selected state from authoritative equipped slots');
assert.ok(profile.includes("data-profile-game-cosmetic"), 'Profile cards must preserve canonical cosmetic actions');
assert.ok(profile.includes("mgwGameCosmeticEquip"), 'Profile must repair after canonical equip/unequip action');
assert.ok(profile.includes("api.profileV2"), 'Profile must converge after authoritative profile refresh');
assert.ok(profile.includes('data-game-type="go"'), 'Profile previews must identify Go explicitly');
assert.ok(profile.includes('store-cosmetics-v1.css?v=2&mvp19_8=effects-premium-v2'), 'Profile must consume fresh Go premium effect CSS');
assert.ok(profile.includes('profile-go-store-parity-v1.css?v=2&mvp19_8=go-profile-corrective-v2'), 'Profile must consume the corrective tab/card CSS');
assert.ok(profile.includes('mgw-go-grid'), 'Profile must render the accepted Go board grid');
assert.ok(profile.includes('mgw-go-stone'), 'Profile must render the accepted Go stones');
assert.ok(profile.includes('mgw-go-territory'), 'Profile must render the accepted territory effect');

assert.ok(profile.includes('function ensureGoTab(screen)'), 'Go corrective must create the missing Profile tab itself');
assert.ok(profile.includes("goTab.dataset.profileGameTab = 'go'"), 'Injected tab must use the canonical Go tab identity');
assert.ok(profile.includes("goTab.innerHTML = '<span class=\"profile-v2-game-tab-mark\" aria-hidden=\"true\">●○</span><span>Го</span>'"), 'Injected Go tab must include its mark and label');
assert.ok(profile.includes('activateGoTab(screen, gameTab);'), 'Go click must activate even when the cached base collection did not know Go yet');
assert.ok(profile.includes("panel.dataset.profileGamePanel = 'go'"), 'Corrective must hand the panel to Go before rendering');
assert.ok(profile.includes('globalThis.setTimeout(repair, 260)'), 'Go tab must receive a late repair after deferred Profile refresh');

assert.ok(layout.includes("mgw-profile-go-parity.js?v=2&mvp19_8=go-profile-corrective-v2"), 'Active Profile owner must import the fresh Go corrective module');
assert.ok(layout.includes("mgw-profile-go-hard-square-v1.js?v=1&mvp19_8=go-profile-hard-square-v1"), 'Active Profile owner must import Go hard-square runtime');
assert.ok(layout.includes('initProfileGoParity();'), 'Active Profile owner must initialize Go parity');
assert.ok(layout.includes('initProfileGoHardSquare();'), 'Active Profile owner must initialize Go hard-square runtime');
assert.ok(layout.indexOf('initProfileGoParity();') < layout.indexOf('initProfileGoHardSquare();'), 'Go markup must exist before hard-square repair');
assert.ok(layout.includes('prepareProfileGameTabInputMode();'), 'Existing scrollable Profile tab owner must remain active');
assert.ok(layout.includes("screen.dataset.mgwGameTabsScrollerMode = 'delayed-capture-v2'"), 'Existing delayed-capture tab owner must remain active');

assert.ok(css.includes('.profile-v2-game-tabs'), 'Go Profile layer must preserve the horizontal game rail');
assert.ok(css.includes('overflow-x:auto!important'), 'Profile game rail must remain horizontally scrollable');
assert.ok(css.includes('flex:0 0 auto!important'), 'Profile game tabs must not shrink/crop when the rail overflows');
assert.ok(css.includes('display:inline-grid!important'), 'Shared Profile marks must remain centered grid lanes');
assert.ok(css.includes('width:24px!important'), 'Shared Profile mark lane must return to the accepted 24px width');
assert.ok(css.includes('height:20px!important'), 'Shared Profile mark lane must return to the accepted 20px height');
assert.ok(css.includes('data-profile-game-tab="checkers"'), 'Corrective must explicitly protect the Checkers mark');
assert.ok(css.includes('data-profile-game-tab="chess"'), 'Corrective must explicitly protect the Chess mark');
assert.ok(css.includes('font-size:19px!important'), 'Chess knight must be normalized instead of enlarged by the Go layer');
assert.ok(css.includes('data-profile-game-tab="go"'), 'Go tab must own a dedicated mark');
assert.ok(css.includes('width:10px!important'), 'Go stones must fit the same fixed mark lane');
assert.ok(css.includes('data-game-type="go"'), 'Go card styling must be scoped to Go previews');
assert.ok(css.includes('aspect-ratio:1 / 1!important'), 'Go Profile cards must keep square media');
assert.ok(css.includes('.mgw-go-board'), 'Go Profile styling must size the accepted board primitive');

assert.ok(hardSquare.includes('getBoundingClientRect().width'), 'Hard-square runtime must measure live Go card width');
assert.ok(hardSquare.includes("preview.dataset.mgwProfileGoHardSquare = '1'"), 'Hard-square runtime must mark repaired previews');
assert.ok(hardSquare.includes('data-game-type="go"'), 'Hard-square runtime must target Go only');
assert.ok(hardSquare.includes("setImportant(preview, 'height', px)"), 'Hard-square runtime must force height to measured width');
assert.ok(hardSquare.includes("setImportant(preview, 'min-height', px)"), 'Hard-square runtime must defeat legacy compact card height');
assert.ok(hardSquare.includes("querySelector(':scope > .mgw-go-preview')"), 'Hard-square runtime must size Store artwork itself');
assert.ok(hardSquare.includes("querySelector(':scope > .mgw-go-board')"), 'Hard-square runtime must preserve complete Go board');
assert.ok(hardSquare.includes("element.style.setProperty(property, value, 'important')"), 'Hard-square dimensions must beat generic Profile crop');

assert.ok(storeCss.includes('.mgw-go-preview.theme-stone'), 'Accepted Store stone board must remain available to Profile');
assert.ok(storeCss.includes('.mgw-go-preview.stones-glass'), 'Accepted Store glass stones must remain available to Profile');
assert.ok(storeCss.includes('.mgw-go-preview.effect-group-capture'), 'Go Store group-capture effect must remain available to Profile');
assert.ok(storeCss.includes('.mgw-go-preview.effect-territory-finish'), 'Go Store territory effect must remain available to Profile');
assert.ok(storeCss.includes('mgw-go-v2-stonefall'), 'Placement preview must use the new stonefall animation');
assert.ok(storeCss.includes('mgw-go-v2-capture-implode'), 'Capture preview must use the new implode animation');
assert.ok(storeCss.includes('mgw-go-v2-territory-bloom'), 'Territory preview must use the new radial bloom animation');
assert.ok(storeCss.includes('mgw-go-v2-capture-particles'), 'Capture preview must include the new particle burst');
assert.ok(!storeCss.includes('animation:mgw-go-placement-stone'), 'Old generic Go placement animation must not remain active');
assert.ok(!storeCss.includes('animation:mgw-go-capture-stone'), 'Old lift-style capture animation must not remain active');
assert.ok(!storeCss.includes('animation:mgw-go-territory-sweep'), 'Old linear territory sweep must not remain active');
assert.ok(storeWrapper.includes('store-cosmetics-v1.css?v=2&mvp19_8=effects-premium-v2'), 'Store presentation must request the fresh premium effect CSS identity');
assert.ok(storeWrapper.includes('const signature = `${layer}:${variant}:v2`'), 'Store previews must repaint under a fresh v2 signature');

assert.ok(manifest.includes('mgw-profile-chess-layout-v2.js?v=19'), 'Active Profile owner must use the fresh corrective cache identity');
assert.ok(manifest.includes('mvp19_8=go-profile-corrective-v2'), 'Active Profile URL must publish Go Profile corrective v2');
assert.ok(manifest.includes('game_tab_icons=normalized-v3'), 'Active Profile URL must publish normalized game-tab marks');
assert.ok(manifest.includes('go_card_runtime=hard-square-v1'), 'Active Profile URL must retain Go hard-square runtime');
assert.ok(manifest.includes('store-screen-checkers-board-source-wrapper.js?v=7'), 'Active Store outer wrapper must have a fresh cache identity');
assert.ok(manifest.includes('go_effects=premium-v2'), 'Active Store URL must publish premium Go effect previews');

assert.ok(!profile.includes('gameAction('), 'Profile parity must not own Go gameplay actions');
assert.ok(!profile.includes('liberties'), 'Profile parity must not implement Go rules');

console.log(`MVP-19.8 Go Profile corrective v2 contract passed (${itemIds.length} catalogue items).`);