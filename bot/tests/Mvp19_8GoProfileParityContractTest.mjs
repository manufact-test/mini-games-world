import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-go-parity.js');
const hardSquarePath = path.join(root, 'app/assets/js/profile/mgw-profile-go-hard-square-v1.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-go-store-parity-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/go/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const migrationPath = path.join(root, 'bot/database/migrations/20260914_0034_add_go_store_cosmetics.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const hardSquare = fs.readFileSync(hardSquarePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
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
assert.ok(profile.includes('store-cosmetics-v1.css'), 'Profile must reuse accepted Go Store artwork CSS');
assert.ok(profile.includes('mgw-go-grid'), 'Profile must render the accepted Go board grid');
assert.ok(profile.includes('mgw-go-stone'), 'Profile must render the accepted Go stones');
assert.ok(profile.includes('mgw-go-territory'), 'Profile must render the accepted territory effect');

assert.ok(layout.includes("mgw-profile-go-parity.js?v=1&mvp19_8=go-profile-parity-v1"), 'Active Profile owner must import Go parity');
assert.ok(layout.includes("mgw-profile-go-hard-square-v1.js?v=1&mvp19_8=go-profile-hard-square-v1"), 'Active Profile owner must import Go hard-square runtime');
assert.ok(layout.includes('initProfileGoParity();'), 'Active Profile owner must initialize Go parity');
assert.ok(layout.includes('initProfileGoHardSquare();'), 'Active Profile owner must initialize Go hard-square runtime');
assert.ok(layout.indexOf('initProfileGoParity();') < layout.indexOf('initProfileGoHardSquare();'), 'Go markup must exist before hard-square repair');
assert.ok(layout.includes('prepareProfileGameTabInputMode();'), 'Existing scrollable Profile tab owner must remain active');
assert.ok(layout.includes("screen.dataset.mgwGameTabsScrollerMode = 'delayed-capture-v2'"), 'Existing delayed-capture tab owner must remain active');

assert.ok(css.includes('.profile-v2-game-tabs'), 'Go Profile layer must preserve the horizontal game rail');
assert.ok(css.includes('overflow-x:auto!important'), 'Profile game rail must remain horizontally scrollable');
assert.ok(css.includes('flex:0 0 auto!important'), 'Profile game tabs must not shrink/crop when the rail overflows');
assert.ok(css.includes('data-profile-game-tab="go"'), 'Go tab must own a dedicated mark');
assert.ok(css.includes('width:11px!important'), 'Go tab stones must use the normalized mark size');
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
assert.ok(storeCss.includes('.mgw-go-preview.effect-group-capture'), 'Accepted Store group-capture effect must remain available to Profile');
assert.ok(storeCss.includes('.mgw-go-preview.effect-territory-finish'), 'Accepted Store territory effect must remain available to Profile');

assert.ok(manifest.includes('mgw-profile-chess-layout-v2.js?v=18'), 'Active Profile owner must use a fresh Go Profile cache identity');
assert.ok(manifest.includes('mvp19_8=go-profile-parity-v1'), 'Active Profile URL must publish Go Profile parity');
assert.ok(manifest.includes('go_card_runtime=hard-square-v1'), 'Active Profile URL must publish Go hard-square runtime');

assert.ok(!profile.includes('gameAction('), 'Profile parity must not own Go gameplay actions');
assert.ok(!profile.includes('liberties'), 'Profile parity must not implement Go rules');

console.log(`MVP-19.8 Go Profile parity contract passed (${itemIds.length} catalogue items).`);
