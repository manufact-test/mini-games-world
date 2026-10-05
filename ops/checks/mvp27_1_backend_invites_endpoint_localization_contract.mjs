import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const endpoint=read('bot/invites.php');
const client=read('app/assets/js/games/game-invites-v110.js');
const locale=JSON.parse(read('app/locales/ru.json'));
const manifest=JSON.parse(read('app/locales/manifest.json'));
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const catalog=read('app/runtime/localization/LocalizationCatalog.php');

assert.equal(countCyrillicLines(endpoint),0,'Active Invites endpoint must not own hardcoded Cyrillic player copy.');
assert.ok(endpoint.includes("require_once dirname(__DIR__) . '/app/runtime/localization/LocalizationCatalog.php';"),'Invites endpoint must use canonical server LocalizationCatalog owner.');
assert.ok(endpoint.includes("new LocalizationCatalog(dirname(__DIR__) . '/app/locales')"),'Invites endpoint must load canonical production locale directory.');
assert.ok(catalog.includes('public function translate(string $key, array $params = []'),'Server catalog interpolation contract must remain available.');
assert.ok(client.includes('/bot/invites.php'),'Factual v110 game-invites owner must still call bot/invites.php.');

const expected={
  domino_variant:'Классика 0–6',
  player_fallback:'Игрок',
  game_fallback:'Игра',
  game_lower_fallback:'игру',
  share_text:'🎮 Приглашение в Mini Games World\n\n{name} приглашает вас сыграть!\n\n🎲 Игра: {game}\n📐 Вариант: {board}\n🪙 Ставка: {bet} коинов\n\nОткройте приглашение и примите вызов 👇',
  prepared_title:'Приглашение в Mini Games World',
  open_button:'🎮 Открыть приглашение',
  rematch_message:'🎮 Вам предлагают реванш\n\n{name} ждёт повторную партию в «{game}».\n\n{board} · {bet} коинов',
  direct_message:'🎮 Вас пригласили сыграть\n\n{name} приглашает вас в «{game}».\n\n{board} · {bet} коинов',
  invalid_request:'Некорректный запрос.',
  user_not_found:'Пользователь не найден.',
  social_unavailable:'Социальные приглашения временно недоступны.',
  player_unavailable:'Игрок больше недоступен.',
  unknown_action:'Неизвестное действие приглашения.',
  telegram_prepare_failed:'Не удалось подготовить Telegram-приглашение.',
};
for(const [name,value] of Object.entries(expected)){
  const key='server.invites.'+name;
  assert.ok(endpoint.includes("'"+key+"'"),'Invites endpoint must resolve '+key+' through localization.');
  assert.equal(locale.server?.invites?.[name],value,'RU Invites copy changed for '+name+'.');
}

assert.ok(Number(locale?._meta?.version)>=65,'RU catalog must be backend Invites revision 65 or newer.');
assert.equal(manifest.default_locale,'ru');
assert.equal(manifest.fallback_locale,'ru');
assert.deepEqual(manifest.supported_locales,['ru'],'Production manifest must remain truthful RU-only during MVP-27.1.');
assert.ok(Number(baseline.scanned_files)>=678,'Successor localization may expand scanned runtime coverage but must not drop predecessor coverage.');
assert.ok(Number(baseline.cyrillic_lines_total)<=1557,'Later backend localization slices may only reduce total debt from the accepted Invites endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.client),0);
assert.ok(Number(baseline.by_scope?.backend)<=1557,'Later backend localization slices may only reduce backend debt from the accepted Invites endpoint ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 backend Invites endpoint localization: OK — active endpoint uses canonical server localization with unchanged Russian player copy; backend debt 1583 -> 1557.');
