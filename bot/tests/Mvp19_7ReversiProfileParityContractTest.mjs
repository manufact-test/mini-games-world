import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-reversi-parity.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-reversi-store-parity-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const liveRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');

const profile = fs.readFileSync(profilePath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const liveRenderer = fs.readFileSync(liveRendererPath, 'utf8');

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
assert.ok(profile.includes("mgw-profile-chess-layout-v2.js"), 'Reversi Profile wrapper must preserve the accepted Chess/Checkers/Profile parent');

assert.ok(css.includes('data-profile-game-tab="reversi"'), 'Reversi Profile tab needs dedicated mark styling');
assert.ok(css.includes('aspect-ratio:1!important'), 'Reversi Profile cards/sheets must keep square preview geometry');
assert.ok(css.includes('data-game-type="reversi"'), 'Reversi Profile CSS must scope to Reversi previews');
assert.ok(storeCss.includes('.mgw-reversi-preview.theme-marble'), 'Accepted Store marble artwork must remain available to Profile');
assert.ok(storeCss.includes('.mgw-reversi-preview.pieces-classic'), 'Accepted Store Classic piece artwork must remain available to Profile');
assert.ok(storeCss.includes('.mgw-reversi-preview.effect-mass-flip'), 'Accepted Store effects must remain available to Profile');

assert.ok(manifest.includes('mgw-profile-reversi-parity.js'), 'Active profile import must route through Reversi parity wrapper');
assert.ok(manifest.includes('mvp19_7=reversi-profile-parity-v1'), 'Active profile URL must be cache-busted for Reversi Profile parity');

assert.ok(!profile.includes('gameAction('), 'Profile parity must not own gameplay actions');
assert.ok(!profile.includes('last_flipped_cells'), 'Profile parity must not own live Reversi flip state');
assert.ok(liveRenderer.includes('last_flipped_cells'), 'Live Reversi renderer stays the gameplay owner');

console.log(`MVP-19.7 Reversi Profile parity contract passed (${itemIds.length} catalogue items).`);
