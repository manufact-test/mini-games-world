import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const manifest = JSON.parse(fs.readFileSync('app/locales/manifest.json', 'utf8'));
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));

if (manifest.schema_version !== 1) throw new Error('Unexpected localization schema.');
if (manifest.default_locale !== 'ru' || manifest.fallback_locale !== 'ru') throw new Error('RU must own the MVP-16.2 fallback.');
if (JSON.stringify(manifest.supported_locales) !== JSON.stringify(['ru'])) throw new Error('MVP-16.2 must not claim a complete EN translation.');

const expectedGames = ['tictactoe', 'four_in_a_row', 'battleship', 'checkers', 'reversi', 'chess', 'go', 'domino'];
for (const game of expectedGames) {
  const rules = manifest.rules?.games?.[game];
  if (!rules || rules.version !== 1 || !rules.languages?.includes('ru')) throw new Error(`Rules locale metadata missing for ${game}.`);
  const title = rules.title_key.split('.').reduce((value, key) => value?.[key], ru);
  if (typeof title !== 'string' || !title) throw new Error(`Rules title key missing for ${game}.`);
}

const source = fs.readFileSync('app/assets/js/localization/i18n.js', 'utf8');
const tempModule = path.join(os.tmpdir(), `mgw-i18n-${process.pid}.mjs`);
fs.writeFileSync(tempModule, source, 'utf8');
const { createI18n } = await import(pathToFileURL(tempModule).href + `?v=${Date.now()}`);
fs.unlinkSync(tempModule);

const i18n = createI18n({ manifest, catalogs:{ ru } }, 'ru');
if (i18n.t('nav.home') !== 'Главная') throw new Error('Client translation key mismatch.');
if (i18n.plural('units.coin', 1) !== '1 коин') throw new Error('RU one plural mismatch.');
if (i18n.plural('units.coin', 2) !== '2 коина') throw new Error('RU few plural mismatch.');
if (i18n.plural('units.coin', 5) !== '5 коинов') throw new Error('RU many plural mismatch.');
if (!i18n.formatNumber(12345).includes('12')) throw new Error('RU number formatter unavailable.');
if (!i18n.formatDate(new Date('2026-08-16T12:00:00Z')).includes('2026')) throw new Error('RU date formatter unavailable.');
if (i18n.rules('battleship').title !== 'Морской бой') throw new Error('Rules metadata localization mismatch.');

const clientManifestSource = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
if (!clientManifestSource.includes("'@mgw/i18n'")) throw new Error('Stable i18n alias is missing.');
if (!clientManifestSource.includes("'version' => 'keys-v1'")) throw new Error('Localization manifest version owner is missing.');

console.log('MVP16_2_LOCALIZATION_CONTRACT=PASS');
