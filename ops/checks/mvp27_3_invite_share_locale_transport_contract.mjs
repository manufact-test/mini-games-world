// Execute the real invite postJson function using a mocked Telegram HTTP transport.
// Historically invite-watch had X-MGW-Locale while prepared share did not.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../app/assets/js/games/game-invites-v110.js', import.meta.url), 'utf8');
const begin = source.indexOf('async function postJson(url, payload, options = {}){');
const finish = source.indexOf('\nfunction cloneInvite(', begin);
assert.ok(begin >= 0 && finish > begin, 'Find active invite request owner');

let activeLocale = 'en';
const requests = [];
const mock = vm.createContext({
  getI18n: () => ({ locale:activeLocale }),
  getInitData: () => 'signed-telegram-stub',
  getSessionId: () => 'session-stub',
  inviteText: () => 'offline error',
  fetch: async (url, options) => {
    requests.push({ url, options });
    return { ok:true, status:200, json:async () => ({ ok:true }) };
  },
});
const postJson = vm.runInContext(source.slice(begin, finish) + '\npostJson;', mock);
const cases = [
  { locale:'en', action:'create_link_draft', extra:{ prepareMessage:true }, prefetch:true },
  { locale:'ru', action:'create_link_draft', extra:{ prepareMessage:true }, prefetch:true },
  { locale:'en', action:'create_direct', extra:{ recipientId:'123' }, prefetch:false },
  { locale:'ru', action:'rematch', extra:{ token:'test-token' }, prefetch:false },
  { locale:'en', action:'create_link_draft', extra:{ prepareMessage:true }, prefetch:false },
];
for (const scenario of cases) {
  activeLocale = scenario.locale;
  const result = await postJson('/bot/invites.php',
    { action:scenario.action, ...scenario.extra },
    { prefetch:scenario.prefetch });
  assert.equal(result.ok, true);
  const request = requests.at(-1);
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.headers['Content-Type'], 'application/json');
  assert.equal(request.options.headers['X-MGW-Locale'], scenario.locale,
    `Missing requested ${scenario.locale} locale for ${scenario.action}`);
  const body = JSON.parse(request.options.body);
  assert.equal(body.action, scenario.action);
  assert.equal(body.initData, 'signed-telegram-stub');
  assert.equal(body.sessionId, 'session-stub');
  assert.equal(request.options.cache, 'no-store');
  assert.equal(Boolean(request.options.mgwPrefetch), scenario.prefetch);
}
assert.equal(requests.length, cases.length);
console.log(`MVP-27.3 prepared share transport: ${cases.length} EN/RU scenarios passed`);
