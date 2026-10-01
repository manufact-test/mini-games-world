import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const manifest = JSON.parse(fs.readFileSync('app/locales/manifest.json', 'utf8'));
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const source = fs.readFileSync('app/assets/js/localization/i18n.js', 'utf8');

assert(manifest.default_locale === 'ru', 'RU must remain the current production default during MVP-27.1.');
assert(manifest.fallback_locale === 'ru', 'RU must remain the current production fallback during MVP-27.1.');
assert(JSON.stringify(manifest.supported_locales) === JSON.stringify(['ru']),
  'MVP-27.1 architecture audit must not claim production EN before MVP-27.2 translation exists.');
assert(manifest.catalogs?.ru === 'ru.json', 'Production RU catalog mapping is missing.');

const tempModule = path.join(os.tmpdir(), `mgw-mvp27-i18n-${process.pid}.mjs`);
fs.writeFileSync(tempModule, source, 'utf8');
const mod = await import(pathToFileURL(tempModule).href + `?v=${Date.now()}`);
fs.unlinkSync(tempModule);

const { createI18n, resolvePreferredLocale } = mod;
assert(typeof createI18n === 'function', 'Client i18n factory missing.');
assert(typeof resolvePreferredLocale === 'function', 'Locale precedence owner missing.');

const syntheticManifest = {
  ...manifest,
  default_locale: 'ru',
  fallback_locale: 'ru',
  supported_locales: ['ru', 'en'],
  catalogs: { ru:'ru.json', en:'en.json' },
  formats: {
    ...manifest.formats,
    en: {
      intl_locale:'en-US',
      number:{ maximumFractionDigits:0 },
      date:{
        short:{ year:'numeric', month:'2-digit', day:'2-digit' },
        long:{ year:'numeric', month:'long', day:'numeric' },
      },
      datetime:{
        short:{ year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' },
        long:{ year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit' },
      },
    },
  },
  rules:{
    ...manifest.rules,
    games:{
      ...manifest.rules.games,
      tictactoe:{ ...manifest.rules.games.tictactoe, languages:['ru','en'] },
    },
  },
};

const en = {
  common:{ back:'Back' },
  nav:{ home:'Home' },
  games:{ tictactoe:{ name:'Tic-Tac-Toe' } },
  units:{ coin:{ one:'{count} coin', other:'{count} coins' } },
};

const synthetic = createI18n({ manifest:syntheticManifest, catalogs:{ ru, en } }, 'en-US');
assert(synthetic.locale === 'en', 'Regional English locale must normalize to en.');
assert(synthetic.t('nav.home') === 'Home', 'Synthetic EN translation lookup failed.');
assert(synthetic.plural('units.coin', 1) === '1 coin', 'Synthetic EN singular plural form failed.');
assert(synthetic.plural('units.coin', 2) === '2 coins', 'Synthetic EN other plural form failed.');
assert(synthetic.formatNumber(12345).includes('12'), 'Synthetic EN number formatting failed.');
assert(typeof synthetic.formatDate(new Date('2026-10-01T12:00:00Z')) === 'string',
  'Synthetic EN date formatting failed.');
assert(synthetic.rules('tictactoe').title === 'Tic-Tac-Toe',
  'Rules metadata must be able to resolve an EN title once the catalog exists.');

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
console.log('production: RU-only remains truthful; synthetic EN architecture: PASS');
