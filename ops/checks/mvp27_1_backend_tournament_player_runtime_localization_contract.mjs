import fs from 'node:fs';

const CYR = /[\u0400-\u04FF]/;
const locale = JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
const baseline = JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
function assert(ok,msg){ if(!ok) throw new Error(msg); }
function get(key){ return key.split('.').reduce((v,p)=>v?.[p],locale); }
function cyrLines(path){ return fs.readFileSync(path,'utf8').split(/\r?\n/).filter(l=>CYR.test(l)).length; }

assert(locale?._meta?.locale === 'ru','RU locale identity changed.');
assert(Number.isInteger(locale?._meta?.version) && locale._meta.version >= 70,'Tournament successor must not regress RU locale below version 70.');
assert(/^[a-f0-9]{40}$/.test(String(baseline?.staging_base ?? '')),'Localization ratchet must stay bound to an exact staging SHA.');
assert(Number.isFinite(Number(baseline?.by_scope?.backend)) && Number(baseline.by_scope.backend) <= 976,'Tournament successor must not increase backend localization debt above 976.');
assert(baseline?.by_scope?.client === 0 && baseline?.by_scope?.['client-entry'] === 0,'Client debt must remain zero.');

const exact = {
  "registration": {
    "closed_full": "Регистрация на официальный турнир уже закрыта. Состав набран.",
    "closed": "Регистрация на официальный турнир сейчас закрыта.",
    "consent_race": "Согласие с правилами изменилось одновременно. Повторите попытку.",
    "full": "Все места в турнире уже заняты.",
    "publication_missing": "Активная регистрация для публикации не найдена.",
    "publication_race": "Публикация регистрации изменилась одновременно. Повторите попытку.",
    "leave_unavailable": "Из текущего состояния турнира выйти через регистрацию нельзя.",
    "not_found": "Активная регистрация не найдена.",
    "roster_fixed": "После заполнения турнира регистрация зафиксирована и выйти через этот этап уже нельзя.",
    "state_race": "Состояние регистрации изменилось одновременно.",
    "tournament_missing": "Официальный турнир пока не создан."
  },
  "rules": {
    "title": "Правила официального турнира",
    "registration_title": "Регистрация и взнос",
    "registration_game_capacity": "Турнир проходит по игре «{game}». Количество участников: {capacity}.",
    "registration_fee": "Взнос — 50 000 коинов. При регистрации сумма резервируется, а не списывается.",
    "registration_cancel": "До заполнения турнира участник может отменить регистрацию: место освобождается, резерв 50 000 полностью снимается.",
    "registration_close": "Когда все места заняты, регистрация закрывается автоматически, состав фиксируется и ожидает назначения даты.",
    "date_title": "Дата и участие",
    "date_schedule": "После набора состава назначается дата и время турнира.",
    "date_reminders": "Участникам предусмотрены напоминания за день, за час и за 15 минут до начала.",
    "date_hall": "Турнирный зал открывается за 15 минут до старта. Сетка формируется случайно точно в момент начала.",
    "date_absent": "Отсутствующий участник остаётся в сетке и получает техническое поражение по турнирным правилам.",
    "ready_title": "Готовность и старт матча",
    "ready_window": "Перед первым матчем даётся 2 минуты на подтверждение «Я готов».",
    "ready_countdown": "После готовности обоих игроков поле блокируется до общего 10-секундного визуального, звукового и вибрационного отсчёта.",
    "ready_timer": "Игровой таймер начинается только после окончания этого отсчёта.",
    "rounds_title": "Раунды и ничьи",
    "rounds_next": "Следующий раунд начинается после завершения всех матчей текущего раунда.",
    "rounds_break": "Между раундами предусмотрен перерыв 3 минуты.",
    "rounds_draw": "При ничьей повторный матч начинается через 1 минуту, стороны меняются, повторный взнос не резервируется.",
    "rounds_final_third": "Турнир включает финал и отдельный матч за третье место.",
    "technical_title": "Отключения и технические исходы",
    "technical_single_disconnect": "Если один игрок отключился, у него есть 60 секунд, чтобы вернуться в игру.",
    "technical_both_disconnect": "Если отключились оба игрока, им даётся до 3 минут, чтобы вернуться в игру.",
    "technical_outcome": "Если игрок выходит сам, оба игрока не появляются или возникает техническая ошибка, результат определяется по правилам турнира.",
    "technical_cancel_refund": "Если турнир отменён или остановлен из-за технической проблемы, взнос участникам возвращается полностью, а результаты аннулируются.",
    "rewards_title": "Награды",
    "rewards_first": "1 место: 200 000 коинов; Golden Ticket; корона чемпиона на 30 дней; постоянный значок победителя; эксклюзивный чемпионский набор оформления игр; Зал славы; золотой кубок.",
    "rewards_second": "2 место: 80 000 коинов; серебряная рамка на 30 дней; постоянная отметка финалиста; серебряный кубок.",
    "rewards_third": "3 место: 50 000 коинов; бронзовая отметка на 30 дней; постоянная отметка за третье место; бронзовый кубок.",
    "rewards_others": "Для остальных участников денежная награда не предусмотрена.",
    "rewards_golden_ticket": "Golden Ticket нельзя продать или передать другому игроку. Он даёт право участия в будущем Большом турнире и действует до его проведения. Дата и число участников Большого турнира будут определены позже.",
    "change_title": "Изменение правил",
    "change_notice": "После открытия регистрации условия этого турнира не меняются незаметно для участников. Если правила потребуется существенно изменить, текущий турнир будет отменён и создан новый.",
    "not_prepared": "Правила турнира ещё не подготовлены.",
    "snapshot_damaged": "Снимок правил турнира повреждён.",
    "version_mismatch": "Версия правил турнира не совпадает со снимком.",
    "consent_required": "Перед регистрацией подтвердите согласие с правилами турнира.",
    "updated": "Правила турнира обновились. Откройте их заново и подтвердите актуальную версию."
  },
  "hall": {
    "opens_15": "Турнирный зал откроется за 15 минут до старта.",
    "enter_first": "Сначала войдите в Турнирный зал.",
    "registered_only": "Турнирный зал доступен только зарегистрированным участникам.",
    "after_schedule": "Турнирный зал откроется после назначения даты турнира.",
    "date_not_set": "Дата старта турнира не назначена.",
    "invalid_action": "Некорректное действие Турнирный зал.",
    "account_unavailable": "Не удалось подтвердить участника турнира.",
    "unavailable": "Турнирный зал временно недоступен.",
    "load_error": "Не удалось загрузить Турнирный зал."
  },
  "ready": {
    "not_required": "Для этой пары подтверждение готовности не требуется.",
    "manual_finished": "Ручное подтверждение готовности для этой стадии уже завершено.",
    "opens_at_start": "Подтверждение готовности откроется в момент старта турнира.",
    "not_in_pair": "Игрок не входит в эту турнирную пару.",
    "registered_only": "Готовность доступна только зарегистрированным участникам турнира.",
    "tournament_not_ready": "Турнир ещё не готов к старту матчей."
  },
  "notifications": {
    "scheduled_title": "Дата турнира назначена",
    "scheduled_text": "«{title}»: дата и время турнира назначены. Откройте раздел турниров — там показаны ваше местное время и точный обратный отсчёт.",
    "day_title": "Турнир начнётся через день",
    "day_text": "«{title}» начнётся через 24 часа. Проверьте дату и обратный отсчёт в разделе турниров.",
    "hour_title": "Турнир начнётся через час",
    "hour_text": "«{title}» начнётся через 1 час. Подготовьтесь к участию.",
    "minutes15_title": "Турнир начнётся через 15 минут",
    "minutes15_text": "«{title}» начнётся через 15 минут."
  },
  "cancellation": {
    "admin_reason": "Отменено администратором."
  }
};
function walk(v,p='server.tournament_runtime'){
  for(const [k,x] of Object.entries(v)){
    const key=p+'.'+k;
    if(typeof x==='string') assert(get(key)===x,'Visible RU copy changed for '+key);
    else walk(x,key);
  }
}
walk(exact);
for(const [key,value] of Object.entries({
  'arena.official_title':'Официальный турнир',
  'arena.hall.player_fallback':'Игрок',
  'arena.ready.expired':'Двухминутное окно готовности завершено.',
  'games.tictactoe.name':'Крестики-нолики',
  'games.four_in_a_row.name':'Четыре в ряд',
  'games.battleship.name':'Морской бой',
  'games.checkers.name':'Русские шашки',
  'games.reversi.name':'Реверси',
  'games.chess.name':'Шахматы',
  'games.go.name':'Го',
  'games.domino.name':'Домино',
  'games.router.game_fallback':'Игра',
  'server.tournament_status.invalid_request':'Некорректный запрос.'
})) assert(get(key)===value,'Reused canonical copy changed for '+key);

const expectedCyr = {
  'bot/tournaments/TournamentRegistrationService.php':9,
  'bot/tournaments/TournamentHallService.php':4,
  'bot/tournaments/TournamentMatchReadinessService.php':0,
  'bot/tournament-hall.php':0,
  'bot/tournaments/TournamentParticipantNotificationBridge.php':0,
  'bot/tournaments/TournamentRewardProjectionService.php':0,
  'bot/tournaments/TournamentPrizeReviewService.php':0,
  'bot/tournaments/TournamentRoundProgressionService.php':0,
  'bot/tournaments/TournamentSettlementService.php':0,
  'bot/tournaments/TournamentCancellationService.php':3
};
for(const [path,n] of Object.entries(expectedCyr)) assert(cyrLines(path)===n,path+' has unexpected residual Cyrillic count.');

const registration=fs.readFileSync('bot/tournaments/TournamentRegistrationService.php','utf8');
assert(registration.includes("ServerLocalization::copy('server.tournament_runtime.rules.registration_game_capacity'"),'Rules interpolation owner missing.');
assert(registration.includes("throw new InvalidArgumentException('Укажите дату и время начала турнира.');"),'Admin scheduling copy must remain outside player slice.');
const hall=fs.readFileSync('bot/tournaments/TournamentHallService.php','utf8');
assert(hall.includes('entry conflicts with the canonical registration.'),'Internal Hall invariant must remain outside player copy.');
const cancellation=fs.readFileSync('bot/tournaments/TournamentCancellationService.php','utf8');
assert(cancellation.includes("throw new InvalidArgumentException('Для аварийной остановки обязательно укажите причину.');"),'Admin cancellation validation must remain outside player slice.');

console.log('MVP27_1_BACKEND_TOURNAMENT_PLAYER_RUNTIME_LOCALIZATION=PASS');
console.log('backend_debt='+baseline.by_scope.backend);
console.log('moved_player_facing_cyrillic_lines=111');
