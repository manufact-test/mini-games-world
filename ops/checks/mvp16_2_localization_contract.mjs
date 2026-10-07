import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const manifest = JSON.parse(fs.readFileSync('app/locales/manifest.json', 'utf8'));
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('app/locales/en.json', 'utf8'));

if (manifest.schema_version !== 1) throw new Error('Unexpected localization schema.');
if (manifest.default_locale !== 'ru' || manifest.fallback_locale !== 'ru') throw new Error('RU must own the localization default/fallback.');
if (!Array.isArray(manifest.supported_locales)
    || !manifest.supported_locales.includes('ru')
    || !manifest.supported_locales.includes('en')) {
  throw new Error('RU and EN must both remain supported after MVP-27.2 activation.');
}
if (manifest.catalogs?.ru !== 'ru.json' || manifest.catalogs?.en !== 'en.json') {
  throw new Error('RU/EN catalog mappings are incomplete.');
}

const expectedGames = ['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'];
for (const game of expectedGames) {
  const rules = manifest.rules?.games?.[game];
  if (!rules || !Number.isInteger(rules.version) || rules.version < 1
      || !rules.languages?.includes('ru') || !rules.languages?.includes('en')) {
    throw new Error(`Rules locale metadata missing RU/EN support for ${game}.`);
  }
  const ruTitle = rules.title_key.split('.').reduce((value, key) => value?.[key], ru);
  const enTitle = rules.title_key.split('.').reduce((value, key) => value?.[key], en);
  if (typeof ruTitle !== 'string' || !ruTitle || typeof enTitle !== 'string' || !enTitle) {
    throw new Error(`Rules title key missing for ${game}.`);
  }
}

const source = fs.readFileSync('app/assets/js/localization/i18n.js', 'utf8');
const tempModule = path.join(os.tmpdir(), `mgw-i18n-${process.pid}.mjs`);
fs.writeFileSync(tempModule, source, 'utf8');
const { createI18n } = await import(pathToFileURL(tempModule).href + `?v=${Date.now()}`);
fs.unlinkSync(tempModule);

const catalogs = { ru, en };
const ruI18n = createI18n({ manifest, catalogs }, 'ru');
if (ruI18n.t('nav.home') !== 'Главная') throw new Error('RU client translation key mismatch.');
if (ruI18n.plural('units.coin', 1) !== '1 коин') throw new Error('RU one plural mismatch.');
if (ruI18n.plural('units.coin', 2) !== '2 коина') throw new Error('RU few plural mismatch.');
if (ruI18n.plural('units.coin', 5) !== '5 коинов') throw new Error('RU many plural mismatch.');
if (!ruI18n.formatNumber(12345).includes('12')) throw new Error('RU number formatter unavailable.');
if (!ruI18n.formatDate(new Date('2026-08-16T12:00:00Z')).includes('2026')) throw new Error('RU date formatter unavailable.');
if (ruI18n.rules('battleship').title !== 'Морской бой') throw new Error('RU rules metadata localization mismatch.');

const enI18n = createI18n({ manifest, catalogs }, 'en-US');
if (enI18n.locale !== 'en') throw new Error('Regional EN locale must resolve to active en.');
if (enI18n.t('nav.home') !== 'Home') throw new Error('EN client translation key mismatch.');
if (enI18n.plural('units.coin', 1) !== '1 coin') throw new Error('EN singular plural mismatch.');
if (enI18n.plural('units.coin', 2) !== '2 coins') throw new Error('EN other plural mismatch.');
if (!enI18n.formatNumber(12345).includes('12')) throw new Error('EN number formatter unavailable.');
if (!enI18n.formatDate(new Date('2026-08-16T12:00:00Z')).includes('2026')) throw new Error('EN date formatter unavailable.');
if (enI18n.rules('battleship').title !== 'Battleship') throw new Error('EN rules metadata localization mismatch.');

const clientManifestSource = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
if (!clientManifestSource.includes("'@mgw/i18n'")) throw new Error('Stable i18n alias is missing.');
if (!/'version'\s*=>\s*'keys-v1'/.test(clientManifestSource)) throw new Error('Localization manifest version owner is missing.');

console.log('MVP16_2_LOCALIZATION_CONTRACT=PASS');
