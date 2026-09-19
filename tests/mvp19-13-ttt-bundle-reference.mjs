import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const store = readFileSync('app/assets/js/screens/store-screen.js', 'utf8');
const css = readFileSync('app/assets/css/screens/store-bundle-prototype-v1.css', 'utf8');
const manifest = readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const launch = readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const migration = readFileSync('bot/database/migrations/20260824_0015_create_tictactoe_game_cosmetics_pilot.php', 'utf8');

assert.match(store, /const BUNDLE_PROTOTYPE_GAME = 'tictactoe';/);
assert.match(store, /gameBundlesFromSnapshot\(\)\.filter\(bundle => bundleGameType\(bundle\) === BUNDLE_PROTOTYPE_GAME\)/);
assert.match(store, /const memberIds = new Set\(Array\.isArray\(bundle\?\.item_ids\)/);
assert.match(store, /gameCosmeticPreview\(gameType, layer, variant, name\)/);
assert.match(store, /bundle\?\.missing_item_ids/);
assert.match(store, /bundle\?\.owned_count/);
assert.match(store, /bundle\?\.missing_count/);
assert.match(store, /bundle\?\.regular_missing_price_coins/);
assert.match(store, /renderBundleConfirmVisual\(offer\)/);
assert.match(store, /renderBundleConfirmPricing\(offer\)/);
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

assert.match(css, /\.store-v2-bundle-reference\{/);
assert.match(css, /\.store-v2-bundle-reference-members\{/);
assert.match(css, /\.store-v2-bundle-confirm-reference\{/);
assert.match(css, /@media \(max-width:360px\)/);
assert.match(css, /@media \(prefers-reduced-motion:reduce\)/);
assert.match(css, /#screen-store > \\.content\\{/);
assert.match(css, /overflow-y:auto!important/);
assert.match(css, /touch-action:pan-y/);
assert.match(css, /store-v2-content\\[data-store-v2-panel="bundles"\\]\\{/);
assert.match(css, /padding-bottom:28px/);
assert.match(store, /store-bundle-prototype-v1\\.css\\?v=2&mvp19_13=mobile-scroll-v1/);

assert.match(manifest, /store-screen\.js\?v=57[^']*mvp19_13=ttt-bundle-mobile-scroll-v1/);
assert.match(launch, /bundles=ttt-mobile-scroll-v1/);

for (const itemId of [
  'game-ttt-field-neon',
  'game-ttt-marks-neon',
  'game-ttt-effect-sign',
  'game-ttt-effect-winning-line',
  'game-ttt-effect-strike',
]) {
  assert.ok(migration.includes(`'${itemId}'`), `canonical TTT bundle member missing: ${itemId}`);
}
assert.match(migration, /'price_coins' => 34000/);

console.log('MVP-19.13 TTT bundle reference contract: OK');
