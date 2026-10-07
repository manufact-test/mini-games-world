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

assert(en?._meta?.locale === 'en', 'English draft locale metadata must be en.');
assert(en?._meta?.status === 'draft', 'English catalog must remain draft during bounded MVP-27.2 slices.');
assert(manifest.default_locale === 'ru', 'RU must remain the default locale.');
assert(manifest.fallback_locale === 'ru', 'RU must remain the fallback locale.');
assert(Array.isArray(manifest.supported_locales) && manifest.supported_locales.length === 1 && manifest.supported_locales[0] === 'ru',
  'Incomplete English catalog must not be declared supported.');
assert(!Object.prototype.hasOwnProperty.call(manifest.catalogs || {}, 'en'),
  'Incomplete English catalog must not be wired into the runtime manifest.');

const leaves = flatten(en).filter(([key]) => !key.startsWith('_meta.'));
assert(leaves.length >= 159, `Expected at least the 159 MVP-27.2.1 English leaves, got ${leaves.length}.`);

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
for (const [key, value] of leaves) {
  assert(typeof value === 'string' && value.trim() !== '', `English key must be a non-empty string: ${key}`);

  const ruValue = readPath(ru, key);
  if (compatibilityTokenKeys.has(key)) {
    assert(value === ruValue, `Compatibility parser token must remain locale-invariant: ${key}`);
  } else {
    assert(!cyrillic.test(value), `Cyrillic leaked into player-facing English draft: ${key}`);
  }
  assert(typeof ruValue === 'string', `English draft key has no canonical RU owner: ${key}`);
  assert(JSON.stringify(placeholders(value)) === JSON.stringify(placeholders(ruValue)),
    `Placeholder mismatch for ${key}: RU=${placeholders(ruValue).join(',')} EN=${placeholders(value).join(',')}`);
}

for (const key of [
  'common.back',
  'entry.tagline',
  'nav.home',
  'topbar.notifications',
  'shell.boot.reopen_telegram',
  'runtime_status.banner.partial_message',
  'settings.language_note',
  'rules.open',
  'home.stats.online_players',
  'home.menu.match_history',
  'home.history.opponent_line',
  'home.rules_guide.start_text_3',
  'weekly_match.new_games_note',
  'game_cards.meta.tictactoe.title',
  'game_cards.meta.domino.description',
  'profile.member_since',
]) {
  assert(typeof readPath(en, key) === 'string', `Required MVP-27.2.1 English key missing: ${key}`);
}

console.log(`MVP-27.2.1 English Shell/Home baseline: PASS (${leaves.length} current localized leaves, original slice preserved, RU runtime still authoritative).`);
