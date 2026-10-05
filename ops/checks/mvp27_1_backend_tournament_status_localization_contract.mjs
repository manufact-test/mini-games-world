import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/tournament-status.php');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const canonicalCatalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active tournament-status endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),
  'Tournament status must use the canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),
  'Tournament status must load the canonical production locale directory.');
assert.ok(canonicalCatalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');

for(const key of [
  'server.tournament_status.invalid_request',
  'server.tournament_status.account_unavailable',
  'server.tournament_status.unavailable',
  'arena.errors.load',
]){
  assert.ok(endpoint.includes("'" + key + "'"),'Tournament status must resolve '+key+' through localization.');
}

assert.equal(locale.server?.tournament_status?.invalid_request,'Некорректный запрос.');
assert.equal(locale.server?.tournament_status?.account_unavailable,'Не удалось подтвердить игровой аккаунт.');
assert.equal(locale.server?.tournament_status?.unavailable,'Турниры временно недоступны.');
assert.equal(locale.arena?.errors?.load,'Не удалось загрузить турнир.');
assert.ok(Number(locale?._meta?.version)>=59,'RU catalog must be backend tournament-status revision 59 or newer.');

assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'Production manifest must remain truthful RU-only during MVP-27.1.');

assert.equal(Number(baseline.scanned_files),678);
assert.ok(Number(baseline.cyrillic_lines_total)<=1649,'Later backend localization slices may only reduce total debt from the accepted tournament-status ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1649,'Later backend localization slices may only reduce backend debt from the accepted tournament-status ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend tournament status localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1653 -> 1649.');
