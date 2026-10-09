import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('app/assets/js/screens/notifications-screen-v110r13.js', 'utf8');
const manifest = readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const begin = source.indexOf('const LOCALE_OWNED_NOTIFICATION_TYPES = new Set([');
const end = source.indexOf('\nfunction mergeServerItems(serverItems){', begin);
assert.ok(begin >= 0 && end > begin, 'Canonical notification copy helper missing.');
const helper = vm.runInNewContext(source.slice(begin, end) + '\nfreshSystemPresentation;');
const old = {
  id:'weekly:1', type:'weekly_match_bonus',
  title:'Еженедельные коины начислены',
  message:'Завершено матчей: 13. Начислено +500 коинов.',
  text:'Сохранённое сообщение',
  read:true, tone:'success', event_id:'weekly:1',
};
const fromServer = {
  ...old, title:'Weekly coins credited',
  message:'Completed matches: 13. Credited +500 coins.',
  text:'Completed matches: 13. Credited +500 coins.',
  read:false, tone:'danger',
};
const updated = helper(old, fromServer);
assert.equal(updated.title, 'Weekly coins credited');
assert.equal(updated.message, 'Completed matches: 13. Credited +500 coins.');
assert.equal(updated.read, true, 'Do not rewrite read authority.');
assert.equal(updated.tone, 'success', 'Do not rewrite notification tone.');
for (const type of ['task_due', 'admin', 'invite_received']) {
  const custom = { ...old, type, title:'Текст автора', message:'Авторская запись' };
  assert.equal(helper(custom, fromServer), custom, type + ' is not auto-translated.');
}
assert.match(source, /invalidateNotificationReads\(\);[\s\S]*?const prior = refreshPromise;/);
assert.match(source, /latestByIdentity\.get\(notificationIdentity\(item\)\)/);
assert.match(source, /sheetState\.pinned\.set\(key, freshSystemPresentation/);
const reminder = {
  ...old, type:'system_message', source_type:'system',
  created_by:'system:admin-task-reminder', title:'⏰ Срок задачи наступил',
  message:'Наступил срок задачи «Тест».',
};
const fromReminderServer = {
  ...reminder, title:'⏰ Task deadline reached',
  message:'Task “Тест” is due.',
};
const localizedReminder = helper(reminder, fromReminderServer);
assert.equal(localizedReminder.title, '⏰ Task deadline reached');
assert.equal(localizedReminder.message, 'Task “Тест” is due.');
assert.equal(localizedReminder.read, true);
assert.equal(helper({ ...reminder, created_by:'system:other' }, fromReminderServer).title,
  '⏰ Срок задачи наступил', 'Other system events retain custom copy');
assert.match(manifest, /mvp27_6=notification-locale-copy-v2-task/);
console.log('MVP-27.6 notification RU/EN latest server copy, safe pinned state and custom message isolation PASS');
