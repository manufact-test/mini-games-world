import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/account-link.php');
const api=read('app/assets/js/api/client.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active account-link endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),
  'Account-link endpoint must use the canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),
  'Account-link endpoint must load the canonical production locale directory.');
assert.ok(catalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');

assert.ok(api.includes("const ACCOUNT_LINK_URL = `${window.location.origin}/bot/account-link.php`;"),
  'Canonical API client must still point Account Link at the active endpoint.');
for(const token of [
  'accountLinkCreate: () => requestUrl(ACCOUNT_LINK_URL',
  'accountLinkStatus: challengeId => requestUrl(ACCOUNT_LINK_URL',
  'accountLinkFinalize: challengeId => requestUrl(ACCOUNT_LINK_URL',
]) assert.ok(api.includes(token),'Active Account Link API ownership token missing: '+token);

for(const key of [
  'server.account_link.method_not_allowed',
  'server.account_link.invalid_request',
  'server.account_link.android_required',
  'server.account_link.unavailable',
  'server.account_link.invalid_action',
  'server.account_link.failed',
]) assert.ok(endpoint.includes("'" + key + "'"),'Account-link endpoint must resolve '+key+' through localization.');

assert.equal(locale.server?.account_link?.method_not_allowed,'Метод запроса не поддерживается.');
assert.equal(locale.server?.account_link?.invalid_request,'Некорректный запрос.');
assert.equal(locale.server?.account_link?.android_required,'Привязку аккаунта нужно начать из Android-приложения.');
assert.equal(locale.server?.account_link?.unavailable,'Привязка аккаунта временно недоступна.');
assert.equal(locale.server?.account_link?.invalid_action,'Некорректное действие привязки аккаунта.');
assert.equal(locale.server?.account_link?.failed,'Не удалось обработать привязку аккаунта. Попробуйте ещё раз.');
assert.ok(Number(locale?._meta?.version)>=60,'RU catalog must be backend account-link revision 60 or newer.');

assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'Production manifest must remain truthful RU-only during MVP-27.1.');

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1643,'Later backend localization slices may only reduce total debt from the accepted account-link endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1643,'Later backend localization slices may only reduce backend debt from the accepted account-link endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend account-link endpoint localization: OK — active Android endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1649 -> 1643.');
