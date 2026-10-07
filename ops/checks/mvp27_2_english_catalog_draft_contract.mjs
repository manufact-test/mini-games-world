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
assert(en?._meta?.status === 'draft', 'English catalog must remain draft until MVP-27.2 completeness gate.');
assert(manifest.default_locale === 'ru' && manifest.fallback_locale === 'ru', 'RU must remain default/fallback while EN is incomplete.');
assert(Array.isArray(manifest.supported_locales) && manifest.supported_locales.length === 1 && manifest.supported_locales[0] === 'ru',
  'Incomplete English catalog must not be declared supported.');
assert(!Object.prototype.hasOwnProperty.call(manifest.catalogs || {}, 'en'),
  'Incomplete English catalog must not be wired into the runtime manifest.');

const cyrillic = /[А-Яа-яЁё]/u;
const englishLeaves = flatten(en).filter(([key]) => !key.startsWith('_meta.'));
for (const [key, value] of englishLeaves) {
  assert(typeof value === 'string' && value.trim() !== '', `English key must be a non-empty string: ${key}`);
  assert(!cyrillic.test(value), `Cyrillic leaked into English draft: ${key}`);
  const ruValue = readPath(ru, key);
  assert(typeof ruValue === 'string', `English draft key has no canonical RU owner: ${key}`);
  assert(JSON.stringify(placeholders(value)) === JSON.stringify(placeholders(ruValue)),
    `Placeholder mismatch for ${key}: RU=${placeholders(ruValue).join(',')} EN=${placeholders(value).join(',')}`);
}

const completeScopes = [
  'common',
  'entry',
  'nav',
  'topbar',
  'shell',
  'runtime_status',
  'settings',
  'weekly_match',
  'game_cards',
  'acceptance_runtime',
  'game_invites',
  'game_screen',
  'search',
  'setup',
  'session',
  'network',
  'home.stats',
  'home.menu',
  'home.history',
  'home.rules_guide',
];

for (const scope of completeScopes) {
  const ruKeys = leafKeys(ru, scope);
  const enKeys = leafKeys(en, scope);
  assert(JSON.stringify(enKeys) === JSON.stringify(ruKeys),
    `English scope is not complete for ${scope}: RU=${ruKeys.length}, EN=${enKeys.length}`);
}

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

assert(englishLeaves.length === 365, `Expected 365 English draft leaves after MVP-27.2.2a, got ${englishLeaves.length}.`);
console.log(`MVP-27.2 English draft catalog: PASS (${englishLeaves.length} leaves; common game flow complete; RU runtime still authoritative).`);
