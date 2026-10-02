import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const store = fs.readFileSync('app/assets/js/screens/store-screen.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

assert(!/[\u0400-\u04FF]/.test(store),
  'Active Store owner must contain zero hardcoded Cyrillic after MVP-27.1 Store localization.');
assert(!store.includes('ru-RU'),
  'Active Store owner must not own a direct RU Intl locale.');
assert(store.includes("from '@mgw/i18n'"),
  'Active Store owner must use the canonical client localization owner.');
assert(store.includes("formatNumber as formatLocalizedNumber"),
  'Store number/currency presentation must use canonical locale-aware formatting.');

assert(store.includes('function localizedOfferName(offer') && store.includes('store.products.'),
  'Dynamic Store product names must resolve by stable item_id localization keys.');
assert(store.includes('function localizedCatalogTitle(gameType') && store.includes('store.games.catalog_titles.'),
  'Dynamic Store game titles must resolve by stable game_type localization keys.');
assert(store.includes('function localizedBundleTitle(gameType') && store.includes('bundle_title'),
  'Dynamic Store bundle titles must resolve by stable game_type localization keys.');

for (const itemId of [
  'profile-name-color-sky','profile-name-color-gold','profile-name-color-aurora',
  'game-ttt-field-classic','game-ttt-field-dark','game-ttt-field-glass','game-ttt-field-neon',
  'game-ttt-marks-classic','game-ttt-marks-3d','game-ttt-marks-metal','game-ttt-marks-neon',
  'game-ttt-effect-sign','game-ttt-effect-winning-line','game-ttt-effect-strike',
  'game-chess-board-wood','game-chess-board-tournament-dark','game-chess-board-marble','game-chess-board-neon',
  'game-chess-pieces-wood','game-chess-pieces-marble','game-chess-pieces-metal','game-chess-pieces-neon',
  'game-chess-effect-move','game-chess-effect-capture','game-chess-effect-check',
  'game-checkers-board-wood','game-checkers-board-dark','game-checkers-board-marble','game-checkers-board-neon',
  'game-checkers-pieces-wood','game-checkers-pieces-marble','game-checkers-pieces-metal','game-checkers-pieces-neon',
  'game-checkers-effect-move','game-checkers-effect-capture','game-checkers-effect-promotion',
  'game-domino-table-felt','game-domino-table-midnight','game-domino-table-walnut','game-domino-table-neon',
  'game-domino-tiles-ivory','game-domino-tiles-ebony','game-domino-tiles-marble','game-domino-tiles-neon',
  'game-domino-effect-precision-drop','game-domino-effect-stock-pulse','game-domino-effect-chain-finale',
]) {
  assert(typeof ru.store?.products?.[itemId] === 'string' && ru.store.products[itemId].length > 0,
    `RU Store product localization key missing: ${itemId}`);
}

for (const key of [
  'tabs.coins','tabs.profile','tabs.games','tabs.bundles','tabs.aria',
  'coins.unavailable','profile.avatars_title','profile.name_color_title',
  'actions.buy','actions.select','actions.remove',
  'games.empty','games.cosmetics_title','games.generic_item',
  'bundles.choose_game','bundles.no_auto_equip','bundles.confirm_note',
  'purchase.confirm_title','purchase.to_pay','purchase.remaining',
  'errors.unavailable','errors.purchase'
]) {
  const value = key.split('.').reduce((node, part) => node?.[part], ru.store);
  assert(typeof value === 'string', `RU Store localization key missing: store.${key}`);
}

for (const gameType of ['tictactoe','chess','checkers','domino']) {
  const presentation = ru.store?.games?.presentation?.[gameType];
  const descriptions = ru.store?.games?.descriptions?.[gameType];
  assert(presentation && typeof presentation === 'object',
    `RU Store presentation namespace missing: ${gameType}`);
  assert(descriptions && typeof descriptions === 'object',
    `RU Store description namespace missing: ${gameType}`);
}

for (const gameType of ['tictactoe','chess','checkers','reversi','go','domino','four_in_a_row','battleship']) {
  const presentation = ru.store?.bundles?.presentation?.[gameType];
  assert(typeof presentation?.game_title === 'string' && presentation.game_title.length > 0,
    `RU Store bundle game title missing: ${gameType}`);
  assert(typeof presentation?.description === 'string' && presentation.description.length > 0,
    `RU Store bundle description missing: ${gameType}`);
}

for (const invariant of [
  "const BUNDLE_REFERENCE_GAMES = Object.freeze(['tictactoe','chess','checkers','reversi','go','domino','four_in_a_row','battleship'])",
  '.filter(tab => tab.available !== false);',
  'gameBundlesFromSnapshot().filter(bundle => BUNDLE_REFERENCE_GAMES.includes(bundleGameType(bundle)))',
  'renderBundleConfirmVisual(offer)',
  'renderBundleConfirmPricing(offer)',
  'store-v2-confirm-bundle-detail',
  'api.cosmeticStorePurchase',
  'api.cosmeticStoreEquip',
  'api.cosmeticStoreUnequip',
]) {
  assert(store.includes(invariant), `Accepted Store invariant missing: ${invariant}`);
}

assert(manifest.includes("./assets/js/screens/store-screen.js?v=72&intent_base=1"),
  'Canonical manifest must publish the localized Store source revision.');
assert(manifest.includes('mvp27_1=store-localized-v2'),
  'Canonical manifest must publish Store localization cache identity.');

console.log('MVP27_1_STORE_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_STORE_HARDCODED_CYRILLIC=0');
