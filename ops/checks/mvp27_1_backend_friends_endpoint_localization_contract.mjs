import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/friends.php');
const api=read('app/assets/js/api/client.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active Friends endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),'Friends endpoint must use the canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),'Friends endpoint must load the canonical production locale directory.');
assert.ok(catalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');

assert.ok(api.includes('const FRIENDS_URL = '),'Canonical API client must still define the Friends endpoint.');
assert.ok(api.includes('/bot/friends.php'),'Canonical API client must still point Friends at bot/friends.php.');
assert.ok(api.includes('friends: (payload = {}) => requestUrl(FRIENDS_URL, payload)'),'Canonical API client must still route Friends actions through the active endpoint.');

const expected={
  self_relation:'Нельзя выполнить это действие со своим профилем.',
  self_report:'Нельзя отправить жалобу на свой профиль.',
  invalid_reason:'Выберите причину жалобы.',
  invalid_match:'Связанный матч недоступен для этой жалобы.',
  user_unavailable:'Игрок MGW не найден.',
  incoming_request_exists:'У вас уже есть входящая заявка от этого игрока.',
  request_not_incoming:'Входящая заявка уже недоступна.',
  request_not_outgoing:'Исходящая заявка уже недоступна.',
  action_unavailable:'Это действие сейчас недоступно.',
  invalid_request:'Некорректный запрос.',
  profile_unavailable:'Профиль MGW недоступен для этой сессии.',
  unavailable:'Друзья MGW временно недоступны.',
  rate_limited:'Слишком много действий. Попробуйте немного позже.',
  failed:'Не удалось выполнить действие с друзьями MGW.',
};
for(const [name,value] of Object.entries(expected)){
  const key='server.friends.'+name;
  assert.ok(endpoint.includes("'"+key+"'"),'Friends endpoint must resolve '+key+' through localization.');
  assert.equal(locale.server?.friends?.[name],value,'RU Friends copy changed for '+name+'.');
}

assert.ok(Number(locale?._meta?.version)>=62,'RU catalog must be backend Friends revision 62 or newer.');
assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'Production manifest must remain truthful RU-only during MVP-27.1.');

assert.ok(Number(baseline.scanned_files)>=678,'Successor localization may expand scanned runtime coverage but must not drop predecessor coverage.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1621,'Later backend localization slices may only reduce total debt from the accepted Friends endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1621,'Later backend localization slices may only reduce backend debt from the accepted Friends endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend Friends endpoint localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1637 -> 1621.');
