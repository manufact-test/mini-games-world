import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-battleship-parity.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const storePath = path.join(root, 'app/assets/js/screens/store-screen-battleship-store-v1.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-battleship-store-parity-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/battleship/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');
const profileApiPath = path.join(root, 'bot/profile-v2.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const store = fs.readFileSync(storePath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const launch = fs.readFileSync(launchPath, 'utf8');
const profileApi = fs.readFileSync(profileApiPath, 'utf8');

const itemIds = [
  'game-battleship-map-sea',
  'game-battleship-map-dark-military',
  'game-battleship-map-storm',
  'game-battleship-map-neon',
  'game-battleship-fleet-classic',
  'game-battleship-fleet-modern',
  'game-battleship-fleet-armored',
  'game-battleship-fleet-neon',
  'game-battleship-effect-shot',
  'game-battleship-effect-hit',
  'game-battleship-effect-destroy',
];

for (const id of itemIds) assert.ok(profile.includes(`'${id}'`), `Battleship Profile must know ${id}`);
for (const layer of ['theme','elements','effect']) assert.ok(profile.includes(layer), `Battleship Profile must support ${layer}`);
for (const title of ['Карты','Флот','Эффекты']) assert.ok(profile.includes(title), `Battleship Profile must expose ${title}`);

assert.ok(profile.includes("item.owned === true"), 'Profile must render owned Battleship cosmetics only');
assert.ok(profile.includes("battleshipGameType(item) === 'battleship'"), 'Profile must isolate Battleship inventory');
assert.ok(profile.includes('state.profileInventory'), 'Profile must read canonical inventory');
assert.ok(profile.includes('inventory.equipped'), 'Profile must read canonical equipped slots');
assert.ok(profile.includes('data-profile-game-cosmetic'), 'Battleship cards must preserve canonical Profile actions');
assert.ok(profile.includes('#mgwGameCosmeticEquip'), 'Battleship parity must refresh after canonical equip/unequip');
assert.ok(profile.includes('api.profileV2'), 'Battleship parity must converge from authoritative Profile refresh');
assert.ok(profile.includes('mgw:cosmetic-inventory-changed'), 'Battleship parity must react to Store ownership/equip changes');

assert.ok(profile.includes("data-game-type=\"battleship\""), 'Profile previews must identify Battleship explicitly');
assert.ok(profile.includes('battleshipPreviewMarkup(layer, variant)'), 'Profile must reuse the Store Battleship preview primitive exactly');
assert.ok(profile.includes("store-screen-battleship-store-v1.js?v=4&mvp19_12=store-preview-parity-v4&header=steel-ship&neon_frame=outer-safe&neon_fleet=flat-v3&preview_geometry=equal-cells-v1&effects=unchanged-v2"), 'Profile must import the exact current Store preview owner');
assert.ok(store.includes('export function battleshipPreviewMarkup(layer, variant)'), 'Store must export Battleship preview markup for Profile reuse');
assert.ok(store.includes('Array.from({ length:100 }'), 'Store/Profile preview primitive must preserve real 10x10 geometry');
assert.ok(storeCss.includes('grid-auto-rows:auto') && storeCss.includes('aspect-ratio:1 / 1') && storeCss.includes('transform:translateY(-50%)'), 'Store/Profile previews must keep every 10x10 preview cell circular and equal-height without a squashed row');
assert.ok(storeCss.includes('#5f46d8') && storeCss.includes('#3a2a86') && storeCss.includes('rgba(255,73,224,.58)') && storeCss.includes('#41f6ff'), 'Store/Profile Neon Fleet must match the accepted LIVE flat-v3 material');
assert.ok(storeCss.includes('.mgw-battleship-preview.map-neon .mgw-bs-preview-sweep') && storeCss.includes('inset:2%'), 'Profile reuse must include accepted Neon safe-frame fix');
assert.ok(storeCss.includes('.effect-shot .mgw-bs-fx-reticle') && storeCss.includes('.effect-hit .mgw-bs-fx-burst') && storeCss.includes('.effect-destroy .mgw-bs-fx-smoke'), 'Current Store/Profile effect concepts must remain shared until LIVE effects replace them');

assert.ok(profile.includes('mgw-battleship-profile-tab-mark') && profile.includes('<svg viewBox="0 0 30 20"'), 'Battleship Profile tab must use a compact ship mark');
assert.ok(profile.includes("title.style.removeProperty('display')"), 'Battleship detail sheet must keep the item title in the top header like Four in a Row');
assert.ok(profile.includes("strong.textContent = 'Морской бой'"), 'Battleship detail sheet must show the game name below the preview like Four in a Row');
assert.ok(profile.includes("group.textContent = GROUP_TITLES[battleshipLayer(item)]"), 'Battleship detail sheet must show the group below the game name');
assert.ok(profile.includes('upgradeBattleshipSheet(itemId);') && profile.includes('queueMicrotask(() => upgradeBattleshipSheet(itemId))'), 'Battleship sheet copy repair must run immediately and after the shared sheet opens');
assert.ok(css.includes('data-profile-game-tab="battleship"'), 'Battleship tab styling must be scoped');
assert.ok(css.includes('data-profile-game-panel="battleship"'), 'Battleship card styling must be scoped');
assert.ok(css.includes('data-game-type="battleship"'), 'Battleship preview styling must target explicit game previews');
assert.ok(css.includes('aspect-ratio:1 / 1!important'), 'Profile cards and detail sheet must keep square board geometry');
assert.ok(css.includes('width:min(100%,236px)!important'), 'Detail sheet must keep bounded preview size');
assert.ok(css.includes('padding-bottom:16px!important'), 'Battleship Profile panel must retain bottom breathing room');
assert.ok(css.includes('--mgw-profile-card-subtitle:"Карта"') && css.includes('--mgw-profile-card-subtitle:"Флот"') && css.includes('--mgw-profile-card-subtitle:"Эффект"'), 'Battleship cards must use Four-style singular category subtitles');
assert.ok(css.includes('.profile-v2-game-card .profile-v2-game-card-name') && css.includes('display:none!important'), 'Battleship cards must delegate visible title placement to the same shared owner as Four in a Row');

assert.ok(layout.includes("mgw-profile-battleship-parity.js?v=4&mvp19_12=profile-four-parity-v3&store=preview-parity-v4&geometry=square&header=steel-ship&neon_fleet=flat-v3&preview_geometry=equal-cells-v1&effects=unchanged-v2&copy=four-pattern"), 'Active Profile owner must import Battleship Four-parity v3 with current preview parity');
assert.ok(layout.includes('initProfileBattleshipParity();'), 'Active Profile owner must initialize Battleship parity');
assert.ok(layout.indexOf('initProfileFourInARowParity();') < layout.indexOf('initProfileBattleshipParity();'), 'Battleship owner must be added after accepted Four owner without replacing it');

const profileOwnerMatch = manifest.match(/mgw-profile-chess-layout-v2\.js\?v=(\d+)/);
assert.ok(profileOwnerMatch && Number(profileOwnerMatch[1]) >= 31, 'Active Profile owner must publish Battleship Four-parity cache revision');
assert.ok(manifest.includes('battleship_profile=four-parity-v3') && manifest.includes('battleship_store=preview-parity-v4') && manifest.includes('battleship_header=steel-ship-v1') && manifest.includes('battleship_neon_frame=outer-safe-v1') && manifest.includes('battleship_neon_fleet=flat-v3') && manifest.includes('battleship_preview_geometry=equal-cells-v1'), 'Manifest must publish current Store/Profile preview parity identity');
assert.ok(launch.includes('battleship_profile=four-parity-v3'), 'Telegram launch must publish Battleship Profile Four-parity identity');

assert.ok(profileApi.includes('(new ProductInventoryService($database))->snapshot($mgwId)'), 'Profile API must expose canonical inventory');
assert.ok(!profile.includes('gameAction(') && !profile.includes('last_move'), 'Battleship Profile must not own live gameplay actions or hidden-state logic');

console.log(`Battleship Profile parity contract passed (${itemIds.length} catalogue items).`);
