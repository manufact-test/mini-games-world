import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/notifications.php');
const client=read('app/assets/js/api/client.js');
const config=read('app/assets/js/config.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active Notifications endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),'Notifications endpoint must use canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),'Notifications endpoint must load canonical production locale directory.');
assert.ok(catalog.includes('final class LocalizationCatalog'),'Canonical server localization owner changed unexpectedly.');
assert.ok(catalog.includes('public function translate(string $key, array $params = []'),'Server catalog interpolation contract must remain available.');

assert.ok(config.includes('/bot/notifications.php'),'Canonical APP_CONFIG must still point notificationsBase at bot/notifications.php.');
assert.ok(client.includes('notifications: (markRead = false) => requestUrl(APP_CONFIG.notificationsBase, { markRead })'),'Canonical API client must still route Notification Center hydration through notificationsBase.');

const expected={
  player_fallback:'Игрок',
  game_fallback:'Игра',
  game_lower_fallback:'игру',
  invite_cancelled_title:'Приглашение отменено',
  opponent_cancelled_title:'Соперник отменил участие',
  inviter_cancelled_message:'{name} отменил приглашение сыграть в «{game}».',
  invitee_cancelled_message:'{name} отменил участие в матче «{game}».',
  invite_accepted_title:'Приглашение принято',
  invite_accepted_message:'Ждём запуска матча от пригласившего игрока.',
  invite_declined_title:'Приглашение отклонено',
  invite_declined_message:'Вы отклонили приглашение от {name} сыграть в «{game}».',
  invalid_request:'Некорректный запрос.',
  user_not_found:'Пользователь не найден.',
  single_mutation_only:'Одновременно можно изменить только одно состояние уведомлений.',
};
for(const [name,value] of Object.entries(expected)){
  const key='server.notifications.'+name;
  assert.ok(endpoint.includes("'"+key+"'"),'Notifications endpoint must resolve '+key+' through localization.');
  assert.equal(locale.server?.notifications?.[name],value,'RU Notifications copy changed for '+name+'.');
}

assert.ok(Number(locale?._meta?.version)>=64,'RU catalog must be backend Notifications revision 64 or newer.');
assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru','en'],'Production manifest must expose the active RU/EN runtime after MVP-27.2 activation.');

assert.ok(Number.isInteger(Number(baseline.scanned_files)) && Number(baseline.scanned_files) > 0,'Successor classification may reduce scan coverage only through an explicit ownership proof; the player-facing audit itself must remain populated.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1583,'Later backend localization slices may only reduce total debt from the accepted Notifications endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1583,'Later backend localization slices may only reduce backend debt from the accepted Notifications endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend Notifications endpoint localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1599 -> 1583.');
