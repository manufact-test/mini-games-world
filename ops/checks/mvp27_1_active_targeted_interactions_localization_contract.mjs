import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const TARGET='app/assets/js/production-v110-targeted-interactions.js';
const target=read(TARGET);
const clean=read('app/assets/js/production-clean-entry-v110.js');
const manifest=read('app/runtime/client/version-manifest.php');
const locale=JSON.parse(read('app/locales/ru.json'));
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.equal(countCyrillicLines(target),0,'Active v110 targeted interactions must remain free of hardcoded Cyrillic');
assert.ok(target.includes("import { t } from '@mgw/i18n';"),'Canonical i18n owner import missing');
assert.ok(target.includes("t('search.lock_default')"),'Session-lock fallback must use canonical search locale key');
assert.ok(target.includes("t('weekly_match.details_aria')"),'Weekly details aria must use canonical locale key');
assert.ok(target.includes("t('weekly_match.details')"),'Weekly details label must use canonical locale key');
assert.ok(!target.includes('У вас уже идёт активная игра на другом устройстве.'));
assert.ok(!target.includes('Подробнее о еженедельных бесплатных коинах'));
assert.ok(!target.includes('>Подробнее<'));

assert.equal(locale.search?.lock_default,'У вас уже идёт активная игра на другом устройстве.');
assert.equal(locale.weekly_match?.details,'Подробнее');
assert.equal(locale.weekly_match?.details_aria,'Подробнее о еженедельных бесплатных коинах');
assert.ok(Number(locale?._meta?.version)>=57,'RU catalog must advance to targeted-interactions revision 57 or newer');

assert.ok(clean.includes("from './production-v110-targeted-interactions.js?v=1102'"),
  'Factual v110 clean-entry must retain targeted-interactions owner');
assert.ok(manifest.includes("./assets/js/production-v110-targeted-interactions.js?v=1106&zone=unified&ttt=single-owner&mvp27_1=localized-copy-v1"),
  'Manifest must cache-bust localized targeted-interactions owner');

assert.ok(!audit.includes("'"+TARGET+"'"),'Active targeted interactions must remain inside player localization scan');
assert.ok(Number(baseline.scanned_files)<=678);
assert.ok(Number(baseline.cyrillic_lines_total)<=1668);
assert.ok(Number(baseline.by_scope?.client)<=15);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 active targeted interactions localization: OK — factual v110 lock/weekly details copy resolves through canonical RU catalog with unchanged visible copy.');
