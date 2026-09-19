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

assert.match(store, /const BUNDLE_REFERENCE_GAMES = Object\.freeze\(\['tictactoe','checkers'\]\);/);
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
assert.match(store, /data-store-v2-game]:not\(\[data-store-v2-bundle-game\]\)/);
assert.match(store, /const memberIds = new Set\(Array\.isArray\(bundle\?\.item_ids\)/);
assert.match(store, /function renderBundleMemberStorePreview\(gameType, layer, variant, name, owned, sheet = false\)/);
assert.match(store, /data-store-v2-native-preview-viewport/);
assert.match(store, /data-store-v2-native-preview-source/);
assert.match(store, /function fitBundleNativePreviews\(root\)/);
assert.match(store, /Math\.min\(1\.16, viewportWidth \/ sourceWidth, viewportHeight \/ sourceHeight\)/);
assert.match(store, /store-v2-bundle-native-store-product/);
assert.match(store, /data-store-game-product="checkers"/);
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
assert.match(store, /data-mgw-checkers-frozen-snapshot/);
assert.match(store, /getBoundingClientRect\(\)/);
assert.match(store, /clone\.style\.width =/);
assert.match(store, /clone\.style\.transform = `scale/);
assert.match(store, /target\.style\.height =/);
assert.match(store, /sheetElement\.scrollTop = 0/);
assert.match(store, /confirmElement\.scrollTop = 0/);
assert.match(store, /renderBundleConfirmPricing\(offer\)/);
assert.match(store, /store-v2-confirm-bundle/);
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
assert.match(css, /#sheet:has\(> \.store-v2-confirm\.store-v2-confirm-bundle\)\{/);
assert.match(css, /height:86dvh/);
assert.match(css, /#sheet > \.store-v2-confirm\.store-v2-confirm-bundle\{/);
assert.match(css, /flex:1 1 0/);
assert.match(css, /overflow-y:auto!important/);
assert.match(css, /border-radius:11px/);
assert.match(css, /border-radius:8px/);
assert.match(store, /store-bundle-prototype-v1\.css\?v=9&mvp19_13=checkers-sheet-exact-snapshot-v6/);
assert.match(store, /data-store-bundle-member-game=/);
assert.match(store, /data-store-bundle-member-layer=/);
assert.match(css, /data-store-bundle-member-game="checkers"/);
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
assert.match(checkersWrapper, /inBundleSheet/);
assert.match(checkersWrapper, /inactiveBundlePanel/);
assert.match(checkersWrapper, /checkersEffectObserver\.unobserve\(preview\)/);
assert.match(checkersWrapper, /data-mgw-checkers-frozen-snapshot/);

assert.match(manifest, /store-screen-checkers-board-source-wrapper\.js\?v=35[^']*bundle_fit=v4[^']*parent=store-screen-checkers-wrapper\.js\?v=5/);
assert.match(manifest, /store-screen\.js\?v=64[^']*mvp19_13=checkers-sheet-exact-snapshot-v6/);
assert.match(launch, /bundles=checkers-sheet-exact-snapshot-v6/);

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

console.log('MVP-19.13 TTT + Checkers bundle reference contract: OK');
