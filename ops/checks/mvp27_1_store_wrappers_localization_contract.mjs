import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const paths = [
  'app/assets/js/screens/store-screen-intent-wrapper.js',
  'app/assets/js/screens/store-screen-checkers-wrapper.js',
  'app/assets/js/screens/store-screen-reversi-store-v1.js',
  'app/assets/js/screens/store-screen-go-store-v1.js',
  'app/assets/js/screens/store-screen-domino-store-v1.js',
  'app/assets/js/screens/store-screen-four-in-a-row-store-v1.js',
  'app/assets/js/screens/store-screen-battleship-store-v1.js',
];

const sources = Object.fromEntries(paths.map(path => [path, fs.readFileSync(path, 'utf8')]));
const activeStoreChainPaths = [
  'app/assets/js/screens/store-screen.js',
  ...paths,
  'app/assets/js/screens/store-screen-checkers-board-source-wrapper.js',
  'app/assets/js/screens/store-screen-domino-card-fill-v5.js',
  'app/assets/js/screens/store-screen-domino-effects-v9.js',
  'app/assets/js/screens/store-paid-default-dedup-v1.js',
  'app/assets/js/screens/store-game-selector-swipe-v1.js',
];
const activeStoreChain = Object.fromEntries(activeStoreChainPaths.map(path => [path, fs.readFileSync(path, 'utf8')]));
const boardOwner = activeStoreChain['app/assets/js/screens/store-screen-checkers-board-source-wrapper.js'];
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));

for (const [path, source] of Object.entries(sources)) {
  assert(!/[\u0400-\u04FF]/.test(source), `Active Store wrapper must contain zero hardcoded Cyrillic: ${path}`);
  assert(!source.includes('ru-RU'), `Active Store wrapper must not own direct ru-RU formatting: ${path}`);
  assert(source.includes('@mgw/i18n'), `Active Store wrapper must use canonical i18n: ${path}`);
}

for (const [path, source] of Object.entries(activeStoreChain)) {
  assert(!/[\u0400-\u04FF]/.test(source), `Active Store chain must contain zero hardcoded Cyrillic: ${path}`);
  assert(!source.includes('ru-RU'), `Active Store chain must contain zero direct ru-RU formatting: ${path}`);
}

assert(sources['app/assets/js/screens/store-screen-checkers-wrapper.js'].includes('formatNumber as formatLocalizedNumber'),
  'Checkers Store wrapper must use canonical localized number formatting.');
assert(sources['app/assets/js/screens/store-screen-intent-wrapper.js'].includes("getAttribute('data-cosmetic-layer')"),
  'Chess/TTT intent wrapper must identify groups by stable cosmetic layer, not localized visible text.');

for (const [needle, label] of [
  ["store-screen-checkers-wrapper.js?v=6", 'Checkers wrapper'],
  ["store-screen-reversi-store-v1.js?v=6", 'Reversi wrapper'],
  ["store-screen-go-store-v1.js?v=6", 'Go wrapper'],
  ["store-screen-domino-store-v1.js?v=17", 'Domino wrapper'],
  ["store-screen-four-in-a-row-store-v1.js?v=13", 'Four in a Row wrapper'],
  ["store-screen-battleship-store-v1.js?v=16", 'Battleship wrapper'],
]) {
  assert(boardOwner.includes(needle) && boardOwner.includes('mvp27_1=localized-v1'),
    `Active Store board-source owner must cache-bust localized ${label}.`);
}
assert(sources['app/assets/js/screens/store-screen-checkers-wrapper.js'].includes('store-screen-intent-wrapper.js?v=20')
  && sources['app/assets/js/screens/store-screen-checkers-wrapper.js'].includes('mvp27_1=localized-v1'),
  'Checkers wrapper must cache-bust the localized Chess intent wrapper.');

assert(manifest.includes("./assets/js/screens/store-screen-checkers-board-source-wrapper.js?v=37"),
  'Canonical client manifest must publish the localized active Store wrapper-chain owner.');
assert(manifest.includes('mvp27_1=store-wrappers-localized-v1'),
  'Canonical manifest must expose Store wrapper localization identity.');

assert(Number(ru._meta?.version || 0) >= 28, 'RU locale revision must retain Store wrapper catalog v28 or a newer successor.');
for (const gameType of ['tictactoe','chess','checkers','reversi','go','domino','four_in_a_row','battleship']) {
  assert(typeof ru.store?.games?.catalog_titles?.[gameType] === 'string',
    `Store game catalog title must be localized by stable game_type: ${gameType}`);
}

for (const namespace of ['intent','checkers','reversi','go','domino','four_in_a_row','battleship']) {
  assert(ru.store?.wrappers?.[namespace] && typeof ru.store.wrappers[namespace] === 'object',
    `Store wrapper locale namespace missing: ${namespace}`);
}

for (const itemId of [
  'game-reversi-field-green','game-reversi-field-dark','game-reversi-field-marble','game-reversi-field-neon',
  'game-reversi-pieces-classic','game-reversi-pieces-marble','game-reversi-pieces-metal','game-reversi-pieces-neon',
  'game-reversi-effect-placement','game-reversi-effect-line','game-reversi-effect-mass-flip',
  'game-go-board-wood','game-go-board-dark','game-go-board-stone','game-go-board-neon',
  'game-go-stones-classic','game-go-stones-marble','game-go-stones-glass','game-go-stones-neon',
  'game-go-effect-placement','game-go-effect-group-capture','game-go-effect-territory-finish',
  'game-four-field-blue','game-four-field-dark','game-four-field-metal','game-four-field-neon',
  'game-four-discs-classic','game-four-discs-3d','game-four-discs-metal','game-four-discs-neon',
  'game-four-effect-drop','game-four-effect-four','game-four-effect-victory-wave',
  'game-battleship-map-sea','game-battleship-map-dark-military','game-battleship-map-storm','game-battleship-map-neon',
  'game-battleship-fleet-classic','game-battleship-fleet-modern','game-battleship-fleet-armored','game-battleship-fleet-neon',
  'game-battleship-effect-shot','game-battleship-effect-hit','game-battleship-effect-destroy',
]) {
  assert(typeof ru.store?.products?.[itemId] === 'string' && ru.store.products[itemId].length > 0,
    `Stable Store product localization key missing: ${itemId}`);
}

console.log('MVP27_1_STORE_WRAPPERS_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_STORE_WRAPPERS_HARDCODED_CYRILLIC=0');
console.log('MVP27_1_ACTIVE_STORE_CHAIN_HARDCODED_CYRILLIC=0');
