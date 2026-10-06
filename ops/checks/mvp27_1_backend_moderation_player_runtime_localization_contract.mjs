import fs from 'node:fs';

const CYR = /[\u0400-\u04FF]/;
const locale = JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
const baseline = JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
function assert(ok,msg){ if(!ok) throw new Error(msg); }
function get(key){ return key.split('.').reduce((v,p)=>v?.[p],locale); }
function cyrLines(path){ return fs.readFileSync(path,'utf8').split(/\r?\n/).filter(l=>CYR.test(l)).length; }

assert(locale?._meta?.locale === 'ru','RU locale identity changed.');
assert(Number.isInteger(locale?._meta?.version) && locale._meta.version >= 71,'Moderation successor must not regress RU locale below version 71.');
assert(/^[a-f0-9]{40}$/.test(String(baseline?.staging_base ?? '')),'Localization ratchet must stay bound to an exact staging SHA.');
assert(Number.isFinite(Number(baseline?.by_scope?.backend)) && Number(baseline.by_scope.backend) <= 937,'Moderation successor must not increase backend localization debt above 937.');
assert(baseline?.by_scope?.client === 0 && baseline?.by_scope?.['client-entry'] === 0,'Client debt must remain zero.');

const exact = {
  report_reasons: {
    nickname: 'Недопустимый никнейм',
    avatar: 'Недопустимый аватар',
    spam: 'Спам',
    cheating: 'Нечестная игра',
    stalling: 'Затягивание игры',
    other: 'Другое',
    abuse: 'Оскорбления или травля',
    offensive_profile: 'Недопустимый профиль'
  },
  restriction_scopes: {
    profile: 'Изменение профиля',
    social: 'Друзья и социальные действия',
    gameplay: 'Новые игры и матчи',
    all: 'Все игровые действия'
  },
  errors: {
    appeal_forbidden: 'Это решение недоступно для апелляции.',
    appeal_unavailable: 'Это решение уже нельзя обжаловать.',
    appeal_message_required: 'Опишите причину апелляции.',
    appeal_exists: 'По этому решению уже есть открытая апелляция.',
    action_required: 'Решение модерации не определено.',
    action_not_found: 'Решение модерации не найдено.',
    user_unavailable: 'Игрок MGW не найден.',
    account_banned: 'Аккаунт заблокирован после ручной проверки.',
    restricted_until: 'Действует ограничение до {until} UTC.',
    restricted: 'Для аккаунта действует ограничение.'
  },
  endpoint: {
    invalid_request: 'Некорректный запрос.',
    profile_unavailable: 'Профиль MGW недоступен для этой сессии.',
    unavailable: 'Модерация временно недоступна.',
    invalid_action: 'Некорректное действие модерации.',
    load_failed: 'Не удалось загрузить данные модерации.'
  },
  player_fallback: 'Игрок'
};
function walk(v,p='server.moderation'){
  for(const [k,x] of Object.entries(v)){
    const key=p+'.'+k;
    if(typeof x==='string') assert(get(key)===x,'Visible RU copy changed for '+key);
    else walk(x,key);
  }
}
walk(exact);

const expectedCyr = {
  'bot/moderation/ModerationService.php':28,
  'bot/social/PlayerReportService.php':9,
  'bot/moderation.php':0
};
for(const [path,n] of Object.entries(expectedCyr)) assert(cyrLines(path)===n,path+' has unexpected residual Cyrillic count.');

const moderation=fs.readFileSync('bot/moderation/ModerationService.php','utf8');
assert(moderation.includes("ServerLocalization::copy('server.moderation.errors.restricted_until'"),'Restriction interpolation must be locale-owned.');
assert(moderation.includes("throw new ModerationException('appeal_forbidden', ServerLocalization::copy("),'Player appeal failure must be locale-owned.');
assert(moderation.includes("'Укажите основание предупреждения.'"),'Admin warning validation must remain outside player slice.');
assert(moderation.includes("'Выберите срок ограничения.'"),'Admin restriction decision validation must remain outside player slice.');
assert(moderation.includes("'Апелляция уже обработана.'"),'Admin appeal-review validation must remain outside player slice.');

const reports=fs.readFileSync('bot/social/PlayerReportService.php','utf8');
assert(reports.includes("ServerLocalization::copy('server.moderation.player_fallback'"),'Player report fallback must be locale-owned.');
assert(reports.includes("throw new PlayerReportException('self_report', 'Нельзя отправить жалобу на свой профиль.');"),'Reason-coded internal report exception may remain because the player endpoint maps it through server.friends.*.');

const friends=fs.readFileSync('bot/friends.php','utf8');
assert(friends.includes("'self_report' => mgw_friends_copy('server.friends.self_report'"),'Player report exception must remain reason-mapped at the HTTP boundary.');
assert(friends.includes("'invalid_reason' => mgw_friends_copy('server.friends.invalid_reason'"),'Invalid report reason must remain reason-mapped at the HTTP boundary.');
assert(friends.includes("'invalid_match' => mgw_friends_copy('server.friends.invalid_match'"),'Invalid related match must remain reason-mapped at the HTTP boundary.');

console.log('MVP27_1_BACKEND_MODERATION_PLAYER_RUNTIME_LOCALIZATION=PASS');
console.log('backend_debt='+baseline.by_scope.backend);
console.log('moved_player_facing_cyrillic_lines=39');
