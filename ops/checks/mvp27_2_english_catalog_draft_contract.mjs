import fs from 'node:fs';

const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('app/locales/en.json', 'utf8'));
const manifest = JSON.parse(fs.readFileSync('app/locales/manifest.json', 'utf8'));

function assert(condition, message){
  if (!condition) throw new Error(message);
}
function readPath(source, path){
  return path.split('.').reduce((value, part) => (
    value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, part)
      ? value[part]
      : undefined
  ), source);
}
function flatten(source, prefix = '', rows = []){
  if (source && typeof source === 'object' && !Array.isArray(source)) {
    for (const [key, value] of Object.entries(source)) {
      flatten(value, prefix ? `${prefix}.${key}` : key, rows);
    }
    return rows;
  }
  rows.push([prefix, source]);
  return rows;
}
function placeholders(value){
  return [...String(value).matchAll(/\{([A-Za-z0-9_]+)\}/g)]
    .map(match => match[1])
    .sort();
}
function leafKeys(source, prefix){
  const value = readPath(source, prefix);
  return flatten(value, prefix).map(([key]) => key).sort();
}

assert(en?._meta?.locale === 'en', 'English draft locale metadata must be en.');
assert(en?._meta?.status === 'active', 'English catalog must be active after the MVP-27.2 completeness gate.');
assert(manifest.default_locale === 'ru' && manifest.fallback_locale === 'ru', 'RU must remain default/fallback after EN activation.');
assert(Array.isArray(manifest.supported_locales) && manifest.supported_locales.includes('ru') && manifest.supported_locales.includes('en'),
  'RU and EN must both be declared supported after MVP-27.2 activation.');
assert(manifest.catalogs?.ru === 'ru.json' && manifest.catalogs?.en === 'en.json',
  'RU/EN runtime catalog mappings must be registered.');
assert(manifest.formats?.en?.intl_locale === 'en-US', 'EN runtime format configuration must be registered.');
for (const [game, entry] of Object.entries(manifest.rules?.games || {})) {
  assert(Array.isArray(entry.languages) && entry.languages.includes('ru') && entry.languages.includes('en'),
    `RU/EN rules languages must be active for ${game}.`);
}

const cyrillic = /[А-Яа-яЁё]/u;
const compatibilityTokenKeys = new Set([
  'games.router.aliases.domino',
  'games.router.aliases.go',
  'games.router.aliases.chess',
  'games.router.aliases.reversi',
  'games.router.aliases.checkers',
  'games.router.aliases.battleship',
  'games.router.aliases.four_in_a_row',
]);
const englishLeaves = flatten(en).filter(([key]) => !key.startsWith('_meta.'));
for (const [key, value] of englishLeaves) {
  assert(typeof value === 'string', `English key must be a string: ${key}`);
  const ruValue = readPath(ru, key);
  assert(typeof ruValue === 'string', `English draft key has no canonical RU owner: ${key}`);
  if (ruValue === '') {
    assert(value === '', `Canonical empty RU value must remain empty in EN: ${key}`);
  } else {
    assert(value.trim() !== '', `English key must be non-empty when RU owner is non-empty: ${key}`);
  }
  if (compatibilityTokenKeys.has(key)) {
    assert(value === ruValue, `Compatibility parser token must remain locale-invariant: ${key}`);
  } else {
    assert(!cyrillic.test(value), `Cyrillic leaked into player-facing English draft: ${key}`);
  }
  assert(JSON.stringify(placeholders(value)) === JSON.stringify(placeholders(ruValue)),
    `Placeholder mismatch for ${key}: RU=${placeholders(ruValue).join(',')} EN=${placeholders(value).join(',')}`);
}

const completeScopes = [
  'common',
  'entry',
  'nav',
  'topbar',
  'notifications',
  'shell',
  'runtime_status',
  'typography',
  'units',
  'user_copy',
  'server.support',
  'server.moderation',
  'server.friends',
  'server.account_data',
  'server.account_link',
  'server.profile_endpoint',
  'server.auth',
  'server.social_invite',
  'server.presence_endpoint',
  'server.search_speed',
  'server.identity_policy',
  'server.notifications',
  'server.history',
  'server.invites',
  'server.invite_chain',
  'server.game_runtime',
  'server.cosmetic_store',
  'server.game_reactions_endpoint',
  'server.tournament_runtime',
  'server.tournament_status',
  'server.api',
  'server.rating_archive_endpoint',
  'server.leaderboard_endpoint',
  'server.weekly_match',
  'server.account_chain',
  'settings',
  'account_data',
  'account_link',
  'weekly_match',
  'game_cards',
  'acceptance_runtime',
  'game_invites',
  'friends',
  'game_screen',
  'search',
  'setup',
  'session',
  'network',
  'home.stats',
  'home.menu',
  'home.history',
  'home.rules_guide',
  'home.report',
  'home',
  'home.support',
  'games.router',
  'games.tictactoe',
  'games.four_in_a_row',
  'rules.tictactoe',
  'rules.four_in_a_row',
  'games.reversi',
  'games.go',
  'rules.reversi',
  'rules.go',
  'games.checkers',
  'games.chess',
  'rules.checkers',
  'rules.chess',
  'games.domino',
  'games.battleship',
  'rules.domino',
  'rules.battleship',
  'games',
  'rules',
  'store.tabs',
  'store.balance',
  'store.units',
  'store.coins',
  'store.actions',
  'store.errors',
  'store.profile_selection',
  'store.profile',
  'store.purchase',
  'store.products',
  'store.bundles',
  'store.games',
  'store.wrappers',
  'store',
  'profile.providers',
  'profile.entry_effects',
  'profile.victory_effects',
  'profile.badges',
  'profile.frames',
  'profile.backgrounds',
  'profile.reactions',
  'profile.collection',
  'profile.moderation',
  'profile.tournament',
  'profile',
  'arena.scroll_left',
  'arena.scroll_right',
  'arena.official_title',
  'arena.errors',
  'arena.registration',
  'arena.hall',
  'arena.ready',
  'arena.bracket',
  'arena.terminal',
  'arena.progression',
  'arena.card',
  'arena.archive',
  'arena',
];

for (const scope of completeScopes) {
  const ruKeys = leafKeys(ru, scope);
  const enKeys = leafKeys(en, scope);
  assert(JSON.stringify(enKeys) === JSON.stringify(ruKeys),
    `English scope is not complete for ${scope}: RU=${ruKeys.length}, EN=${enKeys.length}`);
}

const deferredProfileSections = new Set([
  'entry_effects',
  'victory_effects',
  'badges',
  'frames',
  'backgrounds',
  'reactions',
  'moderation',
  'collection',
  'tournament',
]);
const ruProfileCoreKeys = Object.keys(ru.profile || {}).filter(key => !deferredProfileSections.has(key)).sort();
const enProfileCoreKeys = Object.keys(en.profile || {}).filter(key => !deferredProfileSections.has(key)).sort();
assert(JSON.stringify(enProfileCoreKeys) === JSON.stringify(ruProfileCoreKeys),
  `English Profile core key-set is incomplete: RU=${ruProfileCoreKeys.length}, EN=${enProfileCoreKeys.length}`);

for (const key of [
  'rules.open',
  'rules.version',
  'rules.understood',
  'profile.player',
  'profile.member_since',
  'profile.save_error',
]) {
  assert(typeof readPath(en, key) === 'string', `Required shared English key missing: ${key}`);
}

const deferredNonMiniAppServerScopes = [
  'server.feature_flags.admin',
  'server.shop_history',
  'server.payment',
  'server.webhook',
  'server.welcome',
  'server.shop_catalog',
  'server.prizes',
  'server.response',
  'server.telegram',
];

const englishKeySet = new Set(englishLeaves.map(([key]) => key));
const missingCanonicalEnglishKeys = flatten(ru)
  .filter(([key]) => !key.startsWith('_meta.') && !englishKeySet.has(key))
  .map(([key]) => key);

for (const key of missingCanonicalEnglishKeys) {
  assert(
    deferredNonMiniAppServerScopes.some(scope => key === scope || key.startsWith(`${scope}.`)),
    `Unclassified canonical RU key is missing from final Mini App English coverage: ${key}`
  );
}

assert(
  missingCanonicalEnglishKeys.every(key => key.startsWith('server.')),
  'Final Mini App English coverage may defer only explicitly classified server-owned copy.'
);

assert(englishLeaves.length >= 365, `Expected at least 365 English draft leaves after MVP-27.2.2a, got ${englishLeaves.length}.`);
console.log(`MVP-27.2 English catalog: PASS (${englishLeaves.length} leaves; all Mini App player-facing owners complete; deferred non-Mini-App server copy explicitly classified; RU default/fallback preserved; EN runtime active).`);
