import fs from 'node:fs';

function assert(condition, message){
  if (!condition) throw new Error(message);
}

const read = path => fs.readFileSync(path, 'utf8');
const i18n = read('app/assets/js/localization/i18n.js');
const client = read('app/assets/js/api/client.js');
const home = read('app/assets/js/screens/home-screen.js');
const notifications = read('app/assets/js/screens/notifications-screen-v110r13.js');
const search = read('app/assets/js/screens/search-screen-v102.js');
const game = read('app/assets/js/screens/game-screen-v102.js');
const profile = read('app/assets/js/screens/profile-screen-v110.js');
const runtimeDom = read('app/assets/js/localization/runtime-dom.js');
const history = read('bot/history/RuntimeHistoryRepository.php');
const manifest = read('app/runtime/client/version-manifest.php');
const ru = JSON.parse(read('app/locales/ru.json'));
const en = JSON.parse(read('app/locales/en.json'));

assert(i18n.includes("new CustomEvent('mgw:locale-changed'"), 'Locale owner must publish an in-place locale-change event.');
assert(!home.includes('globalThis.location?.reload()') && !home.includes('location.reload()'),
  'Language switch must not reload the page.');
assert(home.includes('historyCachePromise=api.history()'),
  'Home match history must use the result-aware history owner instead of historyFast().');
assert(client.includes("'X-MGW-Locale':String(getI18n().locale || 'ru')"),
  'Canonical API transport must send the active in-memory locale.');
assert(notifications.includes("'X-MGW-Locale':String(getI18n().locale || 'ru')"),
  'Notification transport must send the active in-memory locale.');
assert(notifications.includes("document.addEventListener('mgw:locale-changed'"),
  'Notification Center must refresh in place after a locale change.');
assert(search.includes("document.addEventListener('mgw:locale-changed'"),
  'Active matchmaking must relocalize in place.');
assert(game.includes("document.addEventListener('mgw:locale-changed'"),
  'Active game screen must relocalize in place.');
assert(profile.includes("document.addEventListener('mgw:locale-changed'"),
  'Profile must rerender in place after a locale change.');
assert(runtimeDom.includes("document.addEventListener('mgw:locale-changed', localizeRuntimeDom)"),
  'Static runtime DOM must have a locale-change owner.');

assert(history.includes('FROM mgw_matches m'), 'Live history must read canonical mgw_matches.');
assert(history.includes('INNER JOIN mgw_match_players me'), 'Live history must bind canonical matches to the current player.');
assert(history.includes("->format(DATE_ATOM)"), 'Live history timestamps must carry an explicit UTC offset.');
assert(history.includes('private function runtimeReadSnapshot'), 'Live history must have a distinct canonical read projection.');

for (const locale of [ru, en]) {
  for (const key of ['home_hero_title','home_hero_subtitle','home_activity_title','home_games_title','play','search_title','search_waiting','search_you','search_opponent','search_leave']) {
    assert(typeof locale?.shell?.[key] === 'string' && locale.shell[key].trim() !== '', `Missing shell.${key}`);
  }
  for (const productId of ['starter-default-01','store-avatar-09','profile-badge-spark','profile-background-04']) {
    assert(typeof locale?.store?.products?.[productId] === 'string' && locale.store.products[productId].trim() !== '',
      `Missing localized profile product ${productId}`);
  }
}

for (const marker of [
  'explicit-locale-inplace-v2',
  'active-i18n-locale-v2',
  'inplace-language-history-v2',
  'inplace-notification-locale-v2',
  'runtime-dom-inplace-v2',
  'inplace-search-locale-v2',
  'inplace-game-locale-v2',
]) {
  assert(manifest.includes(marker), `Version manifest is missing cache-bust marker: ${marker}`);
}

console.log('MVP-27.2 manual acceptance recovery contract: PASS');
