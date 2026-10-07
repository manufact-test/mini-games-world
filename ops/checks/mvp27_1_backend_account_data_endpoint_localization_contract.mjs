import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/account-data.php');
const api=read('app/assets/js/api/client.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active account-data endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),
  'Account-data endpoint must use the canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),
  'Account-data endpoint must load the canonical production locale directory.');
assert.ok(catalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');

assert.ok(api.includes("const ACCOUNT_DATA_URL = `${window.location.origin}/bot/account-data.php`;"),
  'Canonical API client must still point Account Data at the active endpoint.');
for(const token of [
  "accountDataSnapshot: () => requestUrl(ACCOUNT_DATA_URL",
  "accountDataScheduleDelete: () => requestUrl(ACCOUNT_DATA_URL",
  "accountDataCancelDelete: () => requestUrl(ACCOUNT_DATA_URL",
  "accountDataCreateExport: () => requestUrl(ACCOUNT_DATA_URL",
  "accountDataAuthorizeDownload: requestId => requestUrl(ACCOUNT_DATA_URL",
  "response = await fetch(ACCOUNT_DATA_URL",
]) assert.ok(api.includes(token),'Active Account Data API ownership token missing: '+token);

for(const key of [
  'server.account_data.unavailable',
  'server.account_data.method_not_allowed',
  'server.account_data.invalid_request',
  'server.account_data.profile_unavailable',
  'server.account_data.invalid_action',
  'server.account_data.failed',
]) assert.ok(endpoint.includes("'" + key + "'"),'Account-data endpoint must resolve '+key+' through localization.');

assert.equal(locale.server?.account_data?.unavailable,'Управление данными аккаунта временно недоступно.');
assert.equal(locale.server?.account_data?.method_not_allowed,'Метод запроса не поддерживается.');
assert.equal(locale.server?.account_data?.invalid_request,'Некорректный запрос.');
assert.equal(locale.server?.account_data?.profile_unavailable,'Профиль MGW недоступен для этой сессии.');
assert.equal(locale.server?.account_data?.invalid_action,'Некорректное действие управления данными аккаунта.');
assert.equal(locale.server?.account_data?.failed,'Не удалось обработать данные аккаунта.');
assert.ok(Number(locale?._meta?.version)>=61,'RU catalog must be backend account-data revision 61 or newer.');

assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru','en'],'Production manifest must expose the active RU/EN runtime after MVP-27.2 activation.');

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1637,'Later backend localization slices may only reduce total debt from the accepted account-data endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1637,'Later backend localization slices may only reduce backend debt from the accepted account-data endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend account-data endpoint localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1643 -> 1637.');
