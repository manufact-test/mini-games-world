import fs from 'node:fs';

function assert(condition, message){
  if (!condition) throw new Error(message);
}

const activeProfilePaths = [
  'app/assets/js/screens/profile-screen-v110.js',
  'app/assets/js/profile/mgw-profile-model.js',
  'app/assets/js/profile/mgw-profile-chess-parity.js',
  'app/assets/js/profile/mgw-profile-checkers-parity.js',
  'app/assets/js/profile/mgw-profile-chess-layout-v2.js',
  'app/assets/js/profile/mgw-profile-checkers-hard-square-v1.js',
  'app/assets/js/profile/mgw-profile-reversi-parity.js',
  'app/assets/js/profile/mgw-profile-reversi-hard-square-v1.js',
  'app/assets/js/profile/mgw-profile-go-parity.js',
  'app/assets/js/profile/mgw-profile-go-hard-square-v1.js',
  'app/assets/js/profile/mgw-profile-domino-parity.js',
  'app/assets/js/profile/mgw-profile-domino-hard-ratio-v1.js',
  'app/assets/js/profile/mgw-profile-four-in-a-row-parity.js',
  'app/assets/js/profile/mgw-profile-battleship-parity.js',
];

const sources = Object.fromEntries(activeProfilePaths.map(path => [path, fs.readFileSync(path, 'utf8')]));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));

for (const [path, source] of Object.entries(sources)) {
  assert(!/[\u0400-\u04FF]/.test(source), `Active Profile chain must contain zero hardcoded Cyrillic: ${path}`);
  assert(!source.includes('ru-RU'), `Active Profile chain must contain zero direct ru-RU formatting: ${path}`);
}

const profile = sources['app/assets/js/screens/profile-screen-v110.js'];
const model = sources['app/assets/js/profile/mgw-profile-model.js'];
const chess = sources['app/assets/js/profile/mgw-profile-chess-parity.js'];
const checkers = sources['app/assets/js/profile/mgw-profile-checkers-parity.js'];
const layout = sources['app/assets/js/profile/mgw-profile-chess-layout-v2.js'];
const reversi = sources['app/assets/js/profile/mgw-profile-reversi-parity.js'];
const go = sources['app/assets/js/profile/mgw-profile-go-parity.js'];
const domino = sources['app/assets/js/profile/mgw-profile-domino-parity.js'];
const four = sources['app/assets/js/profile/mgw-profile-four-in-a-row-parity.js'];
const battleship = sources['app/assets/js/profile/mgw-profile-battleship-parity.js'];

assert(profile.includes("from '@mgw/i18n'"), 'Base Profile owner must use canonical i18n.');
assert(model.includes("applyAccountLocalePreference, t") && model.includes("t('profile.player')"),
  'Canonical Profile model must localize the player fallback.');
for (const [name, source] of Object.entries({ chess, checkers, reversi, go, domino, four, battleship })) {
  assert(source.includes("from '@mgw/i18n'"), `Profile parity owner must use canonical i18n: ${name}`);
}
assert(chess.includes("nameKey:'profile.collection.games.chess.products.game-chess-board-wood'")
    && chess.includes("nameKey:'store.products.game-chess-effect-check'"),
  'Chess Profile parity must resolve accepted names through stable localization keys.');
for (const source of [checkers, reversi, go, domino, four, battleship]) {
  assert(source.includes('store.products.') && source.includes('metadata.display_name'),
    'Profile parity item display must prefer stable product localization while retaining legacy fallback compatibility.');
}

for (const token of [
  "mgw-profile-checkers-parity.js?v=4",
  "mgw-profile-reversi-parity.js?v=3",
  "mgw-profile-go-parity.js?v=3",
  "mgw-profile-domino-parity.js?v=2",
  "mgw-profile-four-in-a-row-parity.js?v=9",
  "mgw-profile-battleship-parity.js?v=15",
]) {
  assert(layout.includes(token) && layout.includes('mvp27_1=localized-v1'),
    `Active Profile layout must cache-bust localized parity owner: ${token}`);
}
assert(checkers.includes("mgw-profile-chess-parity.js?v=2") && checkers.includes('mvp27_1=localized-v1'),
  'Checkers Profile owner must cache-bust localized Chess parity.');

const layoutAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1108'\s*=>\s*'\.\/assets\/js\/profile\/mgw-profile-chess-layout-v2\.js\?v=(\d+)([^']*)'/);
assert(layoutAlias && Number(layoutAlias[1]) >= 42 && layoutAlias[2].includes('mvp27_1=profile-chain-localized-v1'),
  'Canonical manifest must publish localized full Profile composition on the accepted or newer layout owner.');
const directProfileAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1109'\s*=>\s*'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=(\d+)([^']*)'/);
const wrappedProfileAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1109'\s*=>\s*'\.\/assets\/js\/profile\/mgw-profile-chess-layout-v2\.js\?v=(\d+)([^']*)'/);
const factualProfileOk = (
  directProfileAlias
  && Number(directProfileAlias[1]) >= 1142
  && directProfileAlias[2].includes('mvp27_1=profile-localized-v1')
) || (
  wrappedProfileAlias
  && Number(wrappedProfileAlias[1]) >= 44
  && wrappedProfileAlias[2].includes('mvp27_1=profile-chain-localized-v1')
);
assert(Boolean(factualProfileOk),
  'Canonical manifest must publish the localized factual Profile owner directly or through the accepted full parity wrapper.');
const cosmeticsProfileAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1126&profile_base=accepted-game-cosmetics'\s*=>\s*'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=(\d+)([^']*)'/);
assert(cosmeticsProfileAlias && Number(cosmeticsProfileAlias[1]) >= 1140
    && cosmeticsProfileAlias[2].includes('mvp27_1=profile-localized-v1')
    && cosmeticsProfileAlias[2].includes('mvp27_1_profile_title=scalar-v1'),
  'Accepted Profile cosmetics base alias must converge on the localized Profile owner and retain the scalar collection-title cache identity.');
assert(manifest.includes("'./assets/js/profile/mgw-profile-model.js?v=1' => './assets/js/profile/mgw-profile-model.js?v=6")
    && manifest.includes('mvp27_1=localized-v1'),
  'Canonical Profile model alias must cache-bust localization.');

assert(Number(ru._meta?.version || 0) >= 31, 'RU locale revision must retain Profile catalog v31 or a newer successor.');
for (const key of ['moderation','collection','tournament','history_economy']) {
  assert(ru.profile?.[key], `Profile locale namespace missing: ${key}`);
}
assert(ru.profile?.collection?.games_title === 'Игры',
  'Profile collection title must use a scalar key separate from the nested games catalog.');
assert(profile.includes("t('profile.collection.games_title')")
    && !profile.includes("t('profile.collection.games')"),
  'Profile collection title must not collide with the nested games catalog key.');
for (const game of ['chess','checkers','reversi','go','domino','four_in_a_row','battleship']) {
  assert(ru.profile?.collection?.games?.[game], `Profile collection localization missing: ${game}`);
}
assert(ru.profile.collection.games.chess.products['game-chess-board-wood'] === 'Деревянная доска',
  'Accepted Chess Profile-specific wood copy must remain preserved.');
assert(ru.profile.collection.games.four_in_a_row.title === '4 в ряд',
  'Accepted Four in a Row Profile title must remain preserved.');

console.log('MVP27_1_PROFILE_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_PROFILE_CHAIN_HARDCODED_CYRILLIC=0');
