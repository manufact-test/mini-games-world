import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const manifest = JSON.parse(fs.readFileSync('app/locales/manifest.json', 'utf8'));
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('app/locales/en.json', 'utf8'));
const source = fs.readFileSync('app/assets/js/localization/i18n.js', 'utf8');

assert(manifest.default_locale === 'ru', 'RU must remain the current default locale.');
assert(manifest.fallback_locale === 'ru', 'RU must remain the current fallback locale.');
assert(Array.isArray(manifest.supported_locales)
  && manifest.supported_locales.includes('ru')
  && manifest.supported_locales.includes('en'),
  'Localization architecture must preserve active RU/EN support after MVP-27.2.');
assert(manifest.catalogs?.ru === 'ru.json', 'RU catalog mapping is missing.');
assert(manifest.catalogs?.en === 'en.json', 'EN catalog mapping is missing.');

const tempModule = path.join(os.tmpdir(), `mgw-mvp27-i18n-${process.pid}.mjs`);
fs.writeFileSync(tempModule, source, 'utf8');
const mod = await import(pathToFileURL(tempModule).href + `?v=${Date.now()}`);
fs.unlinkSync(tempModule);

const { createI18n, resolvePreferredLocale } = mod;
assert(typeof createI18n === 'function', 'Client i18n factory missing.');
assert(typeof resolvePreferredLocale === 'function', 'Locale precedence owner missing.');

const catalogs = { ru, en };
const english = createI18n({ manifest, catalogs }, 'en-US');
assert(english.locale === 'en', 'Regional English locale must normalize to active en.');
assert(english.t('nav.home') === 'Home', 'Active EN translation lookup failed.');
assert(english.plural('units.coin', 1) === '1 coin', 'Active EN singular plural form failed.');
assert(english.plural('units.coin', 2) === '2 coins', 'Active EN other plural form failed.');
assert(english.formatNumber(12345).includes('12'), 'Active EN number formatting failed.');
assert(typeof english.formatDate(new Date('2026-10-01T12:00:00Z')) === 'string',
  'Active EN date formatting failed.');
assert(english.rules('tictactoe').title === 'Tic-Tac-Toe',
  'Rules metadata must resolve the active EN title.');

const russian = createI18n({ manifest, catalogs }, 'ru-RU');
assert(russian.locale === 'ru', 'Regional Russian locale must normalize to ru.');
assert(russian.t('nav.home') === 'Главная', 'RU translation lookup regressed.');

assert(resolvePreferredLocale({
  explicitLocale:'en',
  accountLocale:'ru',
  platformLocale:'ru-RU',
  fallbackLocale:'ru',
}) === 'en', 'Explicit locale must win.');
assert(resolvePreferredLocale({
  accountLocale:'en-GB',
  platformLocale:'ru-RU',
  fallbackLocale:'ru',
}) === 'en', 'Account locale must win when explicit locale is absent.');
assert(resolvePreferredLocale({
  platformLocale:'en-US',
  fallbackLocale:'ru',
}) === 'en', 'Platform locale must win when account locale is absent.');
assert(resolvePreferredLocale({ fallbackLocale:'ru' }) === 'ru', 'Fallback locale resolution failed.');

for (const owner of [
  'setExplicitLocale',
  'applyAccountLocalePreference',
  'Intl.PluralRules',
  'Intl.NumberFormat',
  'Intl.DateTimeFormat',
]) {
  assert(source.includes(owner), `Required localization owner missing: ${owner}`);
}

console.log('MVP27_1_LOCALIZATION_ARCHITECTURE_CONTRACT=PASS');
console.log('runtime: RU default/fallback preserved; active RU/EN architecture: PASS');
