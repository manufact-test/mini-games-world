import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const store = readFileSync('app/assets/js/screens/store-screen.js', 'utf8');
const css = readFileSync('app/assets/css/screens/store-bundle-prototype-v1.css', 'utf8');
const manifest = readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const launch = readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const tttMigration = readFileSync('bot/database/migrations/20260824_0015_create_tictactoe_game_cosmetics_pilot.php', 'utf8');
const checkersMigration = readFileSync('bot/database/migrations/20260912_0032_complete_checkers_store_cosmetics.php', 'utf8');
const checkersWrapper = readFileSync('app/assets/js/screens/store-screen-checkers-wrapper.js', 'utf8');
const checkersSourceWrapper = readFileSync('app/assets/js/screens/store-screen-checkers-board-source-wrapper.js', 'utf8');
const allBundlesMigration = readFileSync('bot/database/migrations/20260919_0041_add_remaining_game_premium_bundles.php', 'utf8');
const storeService = readFileSync('bot/catalog/CosmeticStoreService.php', 'utf8');
const reversiStore = readFileSync('app/assets/js/screens/store-screen-reversi-store-v1.js', 'utf8');
const goStore = readFileSync('app/assets/js/screens/store-screen-go-store-v1.js', 'utf8');
const fourStore = readFileSync('app/assets/js/screens/store-screen-four-in-a-row-store-v1.js', 'utf8');
const battleshipStore = readFileSync('app/assets/js/screens/store-screen-battleship-store-v1.js', 'utf8');

assert.match(store, /const BUNDLE_REFERENCE_GAMES = Object\.freeze\(\['tictactoe','chess','checkers','reversi','go','domino','four_in_a_row','battleship'\]\);/);
assert.match(store, /gameBundlesFromSnapshot\(\)\.filter\(bundle => BUNDLE_REFERENCE_GAMES\.includes\(bundleGameType\(bundle\)\)\)/);
assert.match(store, /let activeBundleGame = 'tictactoe';/);
assert.match(store, /data-store-v2-bundle-game=/);
assert.equal(store.includes('data-store-v2-bundle-game="${escapeAttr(gameType)}"\n              data-store-v2-game='), false);
assert.equal(store.includes("bundle?.already_owned ? 'Собран'"), false);
assert.match(store, /store-v2-bundle-game-picker-track/);
assert.match(store, /data-store-v2-bundle-panel=/);
assert.match(store, /bundles\.map\(bundle => \{/);
assert.match(store, /bundlePanel\.classList\.toggle\('active', active\)/);
assert.match(store, /bundlePanel\.setAttribute\('aria-hidden', active \? 'false' : 'true'\)/);
assert.match(store, /function activateBundleGame\(gameType\)/);
assert.match(store, /function bindBundleGamePickerScroll\(root\)/);
assert.match(store, /store-v2-bundle-game-picker-track/);
assert.match(store, /mgwBundlePickerSuppressClickUntil/);
assert.match(store, /if \(!drag\.captured\) \{[\s\S]*setPointerCapture/);
assert.match(store, /picker\.classList\.toggle\('can-scroll-right', canScrollRight\)/);
assert.match(store, /show-scroll-hint/);
assert.match(store, /track\.scrollLeft = drag\.startScrollLeft - dx/);
assert.match(store, /function centerBundlePickerOption\(panel, gameType\)/);
assert.match(store, /data-store-v2-game]:not\(\[data-store-v2-bundle-game\]\)/);
assert.match(store, /const memberIds = new Set\(Array\.isArray\(bundle\?\.item_ids\)/);
assert.match(store, /function renderBundleMemberStorePreview\(gameType, layer, variant, name, owned, sheet = false\)/);
assert.match(store, /data-store-v2-native-preview-viewport/);
assert.match(store, /data-store-v2-native-preview-source/);
assert.match(store, /function fitBundleNativePreviews\(root\)/);
assert.match(store, /Math\.min\(1\.16, viewportWidth \/ sourceWidth, viewportHeight \/ sourceHeight\)/);
assert.match(store, /store-v2-bundle-native-store-product/);
assert.match(store, /data-store-game-product="\$\{escapeAttr\(gameType\)\}"/);
assert.match(store, /if \(gameType === 'tictactoe'\) return preview/);
assert.match(store, /gameCosmeticPreview\(gameType, layer, variant, name\)/);
assert.match(store, /bundle\?\.missing_item_ids/);
assert.match(store, /bundle\?\.owned_count/);
assert.match(store, /bundle\?\.missing_count/);
assert.match(store, /bundle\?\.regular_missing_price_coins/);
assert.match(store, /renderBundleConfirmVisual\(offer\)/);
assert.match(store, /data-store-v2-bundle-confirm-clone/);
assert.match(store, /function hydrateCheckersBundleConfirmFromVisibleCard\(\)/);
assert.match(store, /cloneNode\(true\)/);
assert.match(store, /store-v2-bundle-confirm-cloned-members/);
assert.match(store, /mgwCheckersFrozenSnapshot/);
assert.match(store, /getBoundingClientRect\(\)/);
assert.match(store, /clone\.style\.width =/);
assert.match(store, /clone\.style\.transform = `scale/);
assert.match(store, /target\.style\.height =/);
assert.match(store, /sheetElement\.scrollTop = 0/);
assert.match(store, /confirmElement\.scrollTop = 0/);
assert.match(store, /renderBundleConfirmPricing\(offer\)/);
assert.match(store, /store-v2-confirm-bundle-detail/);
assert.equal(store.includes("'store-v2-confirm-bundle'"), false);
assert.match(store, /const itemCount = Array\.isArray\(bundle\?\.item_ids\)/);
assert.match(store, /Покупка добавляет предметы в коллекцию, но ничего не выбирает автоматически/);
assert.match(store, /\$\{allOwned \? '' : \`/);
assert.equal(store.includes('store-v2-bundle-reference-owned">Комплект полностью собран'), false);
assert.equal(store.includes("allOwned ? 'Комплект собран' : 'Посмотреть и купить'"), false);
assert.match(store, /Оплачиваются только недостающие предметы/);

const prototypeSection = store.slice(store.indexOf('function bundleGameType'), store.indexOf('function emptyState'));
for (const itemId of [
  'game-ttt-field-neon',
  'game-ttt-marks-neon',
  'game-ttt-effect-sign',
  'game-ttt-effect-winning-line',
  'game-ttt-effect-strike',
]) {
  assert.equal(prototypeSection.includes(itemId), false, `bundle UI must not hardcode member id ${itemId}`);
}

assert.match(css, /\.store-v2-bundle-game-picker\{/);
assert.match(css, /\.store-v2-bundle-game-option\.active\{/);
assert.match(css, /\.store-v2-bundle-game-picker\.can-scroll-right::after/);
assert.match(css, /@keyframes mgw-bundle-picker-hint/);
assert.match(css, /cursor:pointer/);
assert.match(css, /width:max-content/);
assert.match(css, /min-width:max-content/);
assert.match(css, /font-size:10px!important/);
assert.match(css, /touch-action:pan-x/);
assert.match(css, /touch-action:pan-x pan-y/);
assert.match(css, /\.store-v2-bundle-reference\{/);
assert.match(css, /\.store-v2-bundle-reference-members\{/);
assert.match(css, /\.store-v2-bundle-confirm-reference\{/);
assert.match(css, /@media \(max-width:360px\)/);
assert.match(css, /@media \(prefers-reduced-motion:reduce\)/);
assert.match(css, /#screen-store > \.content\{/);
assert.match(css, /overflow-y:auto!important/);
assert.match(css, /touch-action:pan-y/);
assert.match(css, /store-v2-content\[data-store-v2-panel="bundles"\]\{/);
assert.match(css, /padding-bottom:28px/);
assert.match(css, /#sheet:has\(> \.store-v2-confirm\.store-v2-confirm-bundle-detail\)\{/);
assert.match(css, /height:86dvh/);
assert.match(css, /#sheet > \.store-v2-confirm\.store-v2-confirm-bundle-detail\{/);
assert.match(css, /flex:1 1 0/);
assert.match(css, /overflow-y:auto!important/);
assert.match(css, /border-radius:11px/);
assert.match(css, /border-radius:8px/);
assert.match(store, /store-bundle-prototype-v1\.css\?v=12&mvp19_13=bundle-selector-click-hint-v10/);
assert.match(store, /data-store-bundle-member-game=/);
assert.match(store, /data-store-bundle-member-layer=/);
assert.match(css, /store-v2-bundle-reference-member:not\(\[data-store-bundle-member-game="tictactoe"\]\)/);
assert.match(css, /store-v2-bundle-native-store-viewport/);
assert.match(css, /store-v2-game-product\.store-v2-bundle-native-store-product/);
assert.match(css, /--mgw-bundle-native-scale/);
assert.match(css, /height:128px/);
assert.match(css, /height:88px/);
assert.match(css, /height:108px/);
assert.match(css, /height:78px/);
assert.equal(css.includes('[data-store-bundle-member-game="checkers"] .store-v2-game-preview{'), false);
assert.equal(css.includes('[data-store-bundle-member-layer="theme"] .store-v2-mini-checkers-board'), false);
assert.equal(css.includes('[data-store-bundle-member-layer="effect"] .store-v2-mini-checkers-effect'), false);
assert.match(checkersWrapper, /\[data-store-v2-bundle-game\]/);
assert.match(checkersWrapper, /data-store-v2-game="checkers"\]:not\(\[data-store-v2-bundle-game\]\)/);
assert.match(checkersSourceWrapper, /store-screen-checkers-wrapper\.js\?v=5[^']*bundle_sheet_static=v1/);
assert.match(checkersWrapper, /store-v2-confirm-bundle-detail/);
assert.match(checkersWrapper, /inBundleSheet/);
assert.match(checkersWrapper, /inactiveBundlePanel/);
assert.match(checkersWrapper, /checkersEffectObserver\.unobserve\(preview\)/);
assert.match(checkersWrapper, /data-mgw-checkers-frozen-snapshot/);

assert.match(manifest, /store-screen-checkers-board-source-wrapper\.js\?v=36[^']*bundle_fit=v4[^']*parent=store-screen-checkers-wrapper\.js\?v=5[^']*mvp19_13=all-eight-bundles-v8/);
assert.match(manifest, /store-screen\.js\?v=68[^']*mvp19_13=bundle-selector-click-hint-v10/);
assert.match(launch, /bundles=bundle-selector-click-hint-v10/);

for (const itemId of [
  'game-ttt-field-neon',
  'game-ttt-marks-neon',
  'game-ttt-effect-sign',
  'game-ttt-effect-winning-line',
  'game-ttt-effect-strike',
]) {
  assert.ok(tttMigration.includes(`'${itemId}'`), `canonical TTT bundle member missing: ${itemId}`);
}
assert.match(tttMigration, /'price_coins' => 34000/);


assert.match(store, /gameTitle:'Шашки'/);
assert.match(store, /labels:\{ theme:'Доска', elements:'Шашки', effect:'Эффект' \}/);
assert.match(store, /Неоновая доска, неоновые шашки и все три эффекта в одном комплекте\./);

for (const itemId of [
  'game-checkers-board-neon',
  'game-checkers-pieces-neon',
  'game-checkers-effect-move',
  'game-checkers-effect-capture',
  'game-checkers-effect-promotion',
]) {
  assert.ok(checkersMigration.includes(`'${itemId}'`), `canonical Checkers bundle member missing: ${itemId}`);
  assert.equal(prototypeSection.includes(itemId), false, `bundle UI must not hardcode Checkers member id ${itemId}`);
}
assert.match(checkersMigration, /'price_coins' => 34000/);

const expectedBundles = {
  chess:['game-chess-board-neon','game-chess-pieces-neon','game-chess-effect-move','game-chess-effect-capture','game-chess-effect-check'],
  reversi:['game-reversi-field-neon','game-reversi-pieces-neon','game-reversi-effect-placement','game-reversi-effect-line','game-reversi-effect-mass-flip'],
  go:['game-go-board-neon','game-go-stones-neon','game-go-effect-placement','game-go-effect-group-capture','game-go-effect-territory-finish'],
  domino:['game-domino-table-neon','game-domino-tiles-neon','game-domino-effect-precision-drop','game-domino-effect-stock-pulse','game-domino-effect-chain-finale'],
  four_in_a_row:['game-four-field-neon','game-four-discs-neon','game-four-effect-drop','game-four-effect-four','game-four-effect-victory-wave'],
  battleship:['game-battleship-map-neon','game-battleship-fleet-neon','game-battleship-effect-shot','game-battleship-effect-hit','game-battleship-effect-destroy'],
};
for (const [gameType, members] of Object.entries(expectedBundles)) {
  for (const itemId of members) assert.ok(allBundlesMigration.includes(`'${itemId}'`), `${gameType} bundle member missing: ${itemId}`);
}
assert.equal((allBundlesMigration.match(/price_coins=34000/g) || []).length >= 1, true);
for (const id of ['chess-premium-bundle','reversi-premium-bundle','go-premium-bundle','domino-premium-bundle','four-in-a-row-premium-bundle','battleship-premium-bundle']) {
  assert.ok(allBundlesMigration.includes(`'${id}'`), `bundle offer missing: ${id}`);
  assert.ok(storeService.includes(`'${id}'`), `Store snapshot bundle missing: ${id}`);
}
assert.match(storeService, /'tictactoe' => \[self::TICTACTOE_BUNDLE_OFFER_ID/);
assert.match(storeService, /'chess' => \[self::CHESS_BUNDLE_OFFER_ID/);
assert.match(storeService, /'checkers' => \[self::CHECKERS_BUNDLE_OFFER_ID/);
assert.match(storeService, /'reversi' => \[self::REVERSI_BUNDLE_OFFER_ID/);
assert.match(storeService, /'go' => \[self::GO_BUNDLE_OFFER_ID/);
assert.match(storeService, /'domino' => \[self::DOMINO_BUNDLE_OFFER_ID/);
assert.match(storeService, /'four_in_a_row' => \[self::FOUR_IN_A_ROW_BUNDLE_OFFER_ID/);
assert.match(storeService, /'battleship' => \[self::BATTLESHIP_BUNDLE_OFFER_ID/);

for (const owner of [reversiStore, goStore, fourStore, battleshipStore]) {
  assert.match(owner, /document\.querySelector\('\[data-store-v2-panel="bundles"\]'\)/);
  assert.match(owner, /\[data-store-v2-bundle-game\]/);
}
assert.match(css, /store-v2-bundle-reference-panel:not\(\.active\)[\s\S]*animation-play-state:paused!important/);

console.log('MVP-19.13 all eight game bundles contract: OK');
