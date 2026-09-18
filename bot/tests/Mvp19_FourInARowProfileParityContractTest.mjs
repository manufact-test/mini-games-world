import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const profilePath = path.join(root, 'app/assets/js/profile/mgw-profile-four-in-a-row-parity.js');
const layoutPath = path.join(root, 'app/assets/js/profile/mgw-profile-chess-layout-v2.js');
const storePath = path.join(root, 'app/assets/js/screens/store-screen-four-in-a-row-store-v1.js');
const cssPath = path.join(root, 'app/assets/css/screens/profile-four-in-a-row-store-parity-v1.css');
const storeCssPath = path.join(root, 'app/assets/css/games/four-in-a-row/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const profileApiPath = path.join(root, 'bot/profile-v2.php');

const profile = fs.readFileSync(profilePath, 'utf8');
const layout = fs.readFileSync(layoutPath, 'utf8');
const store = fs.readFileSync(storePath, 'utf8');
const css = fs.readFileSync(cssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const profileApi = fs.readFileSync(profileApiPath, 'utf8');

const itemIds = [
  'game-four-field-blue',
  'game-four-field-dark',
  'game-four-field-metal',
  'game-four-field-neon',
  'game-four-discs-classic',
  'game-four-discs-3d',
  'game-four-discs-metal',
  'game-four-discs-neon',
  'game-four-effect-drop',
  'game-four-effect-four',
  'game-four-effect-victory-wave',
];

for (const id of itemIds) assert.ok(profile.includes(`'${id}'`), `Four Profile parity must know ${id}`);
for (const layer of ['theme','elements','effect']) assert.ok(profile.includes(layer), `Four Profile must support ${layer}`);
for (const title of ['Поля','Фишки','Эффекты']) assert.ok(profile.includes(title), `Four Profile must expose ${title}`);

assert.ok(profile.includes("item.owned === true"), 'Profile must render owned Four cosmetics only');
assert.ok(profile.includes('state.profileInventory'), 'Profile must use canonical profile inventory');
assert.ok(profile.includes('inventory.equipped'), 'Profile must derive selected state from canonical equipped slots');
assert.ok(profile.includes("fourGameType(item) === 'four_in_a_row'"), 'Profile must isolate Four in a Row inventory');
assert.ok(profile.includes('data-profile-game-cosmetic'), 'Four cards must preserve canonical Profile actions');
assert.ok(profile.includes('#mgwGameCosmeticEquip'), 'Four parity must repair after canonical equip/unequip');
assert.ok(profile.includes('api.profileV2'), 'Four parity must converge from authoritative Profile refresh');
assert.ok(profile.includes('mgw:cosmetic-inventory-changed'), 'Four parity must react to Store ownership/equip refresh');
assert.ok(profile.includes('data-game-type="four_in_a_row"'), 'Four Profile previews must identify game type explicitly');
assert.ok(profile.includes('fourInARowPreviewMarkup(layer, variant)'), 'Profile must reuse the accepted Store preview primitive');
assert.ok(profile.includes("drop:'Лазерное наведение'") && profile.includes("four:'Энергетический импульс'") && profile.includes("'victory-wave':'Победный овердрайв'"), 'Profile cards and equip sheet must use player-facing effect names');
assert.ok(store.includes('upgradePurchaseCopy(root)') && store.includes('.store-v2-confirm-copy strong'), 'Store purchase sheet must use the same shared preview/name presentation as Profile');
assert.ok(profile.includes("store-screen-four-in-a-row-store-v1.js?v=11&four_store=live-previews-v3&geometry=7x6&fx=victory-test-exact-v3&effect2=random-chain-v4&victory=overdrive-v3&export=profile-preview-v4&copy=compact-v3"), 'Profile must request the shared accepted animated Four preview primitive and player-facing copy');
assert.ok(profile.includes("four_profile=live-previews-v3"), 'Four Profile must publish the corrected animated-preview presentation identity');
assert.ok(profile.includes("store-cosmetics-v1.css?v=7&four_store=live-previews-v3&geometry=7x6&fx=victory-test-exact-v3"), 'Profile must load the accepted animated Four Store/Profile preview CSS');
assert.ok(!profile.includes('gameAction('), 'Four Profile must never own gameplay actions');
assert.ok(!profile.includes('last_move'), 'Four Profile must not implement live move triggers');

assert.ok(store.includes('export function fourInARowPreviewMarkup(layer, variant)'), 'Store must export the accepted Four preview primitive for Profile reuse');
assert.ok(store.includes('mgw-four-preview-drop-reticle') && store.includes('mgw-four-preview-pulse-svg') && store.includes('mgw-four-preview-victory-prism'), 'Store/Profile must share the three accepted animated effect preview scenes');
assert.ok(storeCss.includes('@keyframes mgwFourPreviewDropDisc') && storeCss.includes('@keyframes mgwFourPreviewBoltA') && storeCss.includes('@keyframes mgwFourPreviewVictoryPrism'), 'Four Store/Profile effects must now animate the accepted live concepts');
assert.ok(store.includes('Array.from({ length:42 }') && storeCss.includes('grid-template-rows:repeat(6,minmax(0,1fr))') && storeCss.includes('aspect-ratio:7 / 6'), 'Shared Four effect previews must preserve the real 7x6 board proportions');
assert.ok(store.includes('[22,23,24,25]') && store.includes('M1.5 3.5 L2.5 3.5 L3.5 3.5 L4.5 3.5'), 'Shared Victory preview must use the exact centered winning four from the accepted test button');
assert.ok(storeCss.includes('left:42.857%') && storeCss.includes('.effect-victory-wave .mgw-four-fx-board-shell') && storeCss.includes('overflow:hidden'), 'Shared Victory preview must keep the exact test center and clip the finale inside the board');
assert.ok(css.includes('width:86%!important') && css.includes('width:82%!important'), 'Profile card and detail sheet must scale the same proportional effect board without stretching it');

assert.ok(layout.includes("mgw-profile-four-in-a-row-parity.js?v=8&four_profile=live-previews-v3&four_module=export-v11&geometry=7x6&fx=victory-test-exact-v3&effect2=random-chain-v4&victory=overdrive-v3&copy=compact-v3"), 'Active Profile wrapper must import the accepted animated Four preview module');
assert.ok(layout.includes('initProfileFourInARowParity();'), 'Active Profile wrapper must initialize Four parity');
assert.ok(layout.indexOf('initProfileDominoHardRatio();') < layout.indexOf('initProfileFourInARowParity();'), 'Four parity must be added after accepted existing game owners without replacing them');

const profileOwnerMatch = manifest.match(/mgw-profile-chess-layout-v2\.js\?v=(\d+)/);
assert.ok(profileOwnerMatch && Number(profileOwnerMatch[1]) >= 20, 'Active Profile owner must publish the Four parity cache revision');
assert.ok(manifest.includes('four_profile=live-previews-v2') && manifest.includes('four_module=export-v11') && manifest.includes('four_effect2=random-chain-v4') && manifest.includes('four_victory=overdrive-v3') && manifest.includes('copy=compact-v3'), 'Active Profile URL must publish accepted animated preview and player-copy identities');

assert.ok(profileApi.includes('(new ProductInventoryService($database))->snapshot($mgwId)'), 'Profile API must expose the same canonical inventory used by Store purchases');

assert.ok(css.includes('data-profile-game-tab="four_in_a_row"'), 'Four tab must have a dedicated mark');
assert.ok(css.includes('data-profile-game-panel="four_in_a_row"'), 'Four cards must be scoped to the Four panel');
assert.ok(css.includes('data-game-type="four_in_a_row"'), 'Four card/sheet styling must target explicit game previews');
assert.ok(css.includes('width:92%!important'), 'Four Profile cards must match accepted compact Store disc sizing');
assert.ok(css.includes('width:78%!important'), 'Four Profile detail sheet must match accepted purchase-sheet disc sizing');
assert.ok(css.includes('aspect-ratio:1 / 1!important'), 'Four Profile cards and sheet must keep a stable full preview canvas');
assert.ok(css.includes('padding-bottom:16px!important'), 'Four Profile panel must keep visible bottom breathing room');
assert.ok(css.includes('margin:2px 0 4px!important'), 'Four Profile preview must keep space around the image canvas');
assert.ok(css.includes('.profile-v2-game-card-name{') && css.includes('display:none!important'), 'Four Profile must not duplicate the card title above and below the preview');

console.log(`Four in a Row Profile parity contract passed (${itemIds.length} catalogue items).`);
