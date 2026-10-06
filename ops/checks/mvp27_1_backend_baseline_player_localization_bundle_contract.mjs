import fs from 'node:fs';
import path from 'node:path';

const CYR=/[\u0400-\u04FF]/;
const ROOT='.';
const baseline=JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
const locale=JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
const audit=fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_audit.mjs','utf8');

function assert(ok,msg){ if(!ok) throw new Error(msg); }
function cyrLines(file){
  return fs.readFileSync(file,'utf8').split(/\r?\n/).filter(line=>CYR.test(line)).length;
}
function get(obj,key){
  return key.split('.').reduce((value,part)=>value?.[part],obj);
}
function walk(dir,out=[]){
  if(!fs.existsSync(dir)) return out;
  for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
    const full=path.join(dir,entry.name);
    const normalized=full.split(path.sep).join('/');
    if(entry.isDirectory()){
      if(normalized==='bot/baseline' || normalized==='bot/tests') continue;
      walk(full,out);
    } else if(entry.isFile() && path.extname(entry.name)==='.php'){
      out.push(normalized);
    }
  }
  return out;
}

assert(baseline.staging_base==='34baadda9695b12961b53ee33f31ef7a528beba3','Bundle ratchet must bind to exact accepted post-#2087 staging.');
assert(baseline.scanned_files===636,'Expected 636 scanned player-runtime files after baseline harness classification.');
assert(baseline.cyrillic_lines_total===385,'Expected total player-facing localization debt 385.');
assert(baseline.by_scope?.backend===385,'Expected backend localization debt 385.');
assert(baseline.by_scope?.client===0 && baseline.by_scope?.['client-entry']===0,'Client and client-entry debt must remain zero.');
assert(Number(locale?._meta?.version)>=74,'RU catalog must include backend bundle keys.');
assert(audit.includes("'bot/baseline/'"),'Audit must explicitly classify bot/baseline as deterministic proof-only harness.');

const expectedBaselineFiles=[
  'JsonAccountPassiveBaselineScenario.php',
  'JsonBaselineLatencyBootstrap.php',
  'JsonBaselineLatencyRunner.php',
  'JsonBaselineScenarioCatalog.php',
  'JsonBehaviorBaselineFixture.php',
  'JsonBehaviorBaselineNormalizer.php',
  'JsonBehaviorBaselineResult.php',
  'JsonEconomyHistoryTrait.php',
  'JsonEconomySupportingBaselineScenario.php',
  'JsonGamesBaselineScenario.php',
  'JsonGamesClassicTrait.php',
  'JsonGamesSettlementTrait.php',
  'JsonGamesStrategyTrait.php',
  'JsonInviteMatchmakingBaselineScenario.php',
  'JsonInviteMatchmakingInviteTrait.php',
  'JsonInviteMatchmakingProjectionTrait.php',
  'JsonInviteMatchmakingQueueTrait.php',
  'JsonShopPaymentsTrait.php',
  'JsonWeeklyBonusTrait.php',
].sort();

const actualBaselineFiles=fs.readdirSync('bot/baseline')
  .filter(name=>name.endsWith('.php'))
  .sort();
assert(JSON.stringify(actualBaselineFiles)===JSON.stringify(expectedBaselineFiles),'bot/baseline ownership set changed; refresh classification proof.');

const classifiedCyr={
  'bot/baseline/JsonShopPaymentsTrait.php':35,
  'bot/baseline/JsonEconomyHistoryTrait.php':28,
  'bot/baseline/JsonGamesStrategyTrait.php':21,
  'bot/baseline/JsonInviteMatchmakingProjectionTrait.php':21,
  'bot/baseline/JsonGamesClassicTrait.php':13,
  'bot/baseline/JsonGamesBaselineScenario.php':12,
  'bot/baseline/JsonInviteMatchmakingInviteTrait.php':10,
  'bot/baseline/JsonWeeklyBonusTrait.php':4,
  'bot/baseline/JsonGamesSettlementTrait.php':2,
};
let classifiedTotal=0;
for(const [file,expected] of Object.entries(classifiedCyr)){
  const actual=cyrLines(file);
  assert(actual===expected,`Baseline Cyrillic evidence drift for ${file}: expected ${expected}, got ${actual}`);
  classifiedTotal+=actual;
}
assert(classifiedTotal===146,'Expected exactly 146 classified baseline Cyrillic lines.');

const productionPhp=[...walk('app'),...walk('bot')];
for(const file of productionPhp){
  const source=fs.readFileSync(file,'utf8');
  for(const baselineFile of expectedBaselineFiles){
    const stem=baselineFile.replace(/\.php$/,'');
    assert(!source.includes(stem),`Production PHP source ${file} references baseline-only owner ${stem}`);
  }
}

const localizedTargets=[
  'bot/services/FeatureFlagService.php',
  'bot/services/SessionService.php',
  'bot/services/AuthService.php',
  'bot/social/PlayerReportService.php',
  'bot/tournaments/TournamentRegistrationService.php',
  'bot/support/SupportTicketService.php',
  'bot/services/ShopOrderHistoryService.php',
];
for(const file of localizedTargets){
  assert(cyrLines(file)===0,`Active localized backend owner must contain zero Cyrillic lines: ${file}`);
  assert(fs.readFileSync(file,'utf8').includes('ServerLocalization::copy'),`Localized backend owner must use canonical ServerLocalization: ${file}`);
}

const expectedCopy={
  'runtime_status.maintenance_default_full':'Идут технические работы. Mini Games World скоро вернётся.',
  'runtime_status.new_matches_read_only_full':'Новые матчи временно недоступны. Уже начатые партии можно завершить.',
  'runtime_status.finance_read_only_full':'Финансовые операции временно переведены в режим только для чтения.',
  'session.device_session_unknown':'Не удалось определить сессию устройства. Закройте приложение и откройте заново из Telegram.',
  'session.active_game_other_device':'У вас уже идёт активная игра на другом устройстве. Продолжайте игру там.',
  'session.search_other_device':'Вы уже ищете матч на другом устройстве. Завершите поиск там или подождите несколько минут.',
  'session.lock_default':'Игра уже открыта на другом устройстве.',
  'server.auth.telegram_panel_required':'Откройте панель через Telegram.',
  'server.auth.test_player':'Тестовый игрок',
  'server.auth.telegram_app_required':'Откройте приложение через Telegram.',
  'server.moderation.errors.self_report':'Нельзя отправить жалобу на свой профиль.',
  'server.moderation.errors.invalid_reason':'Выберите причину жалобы.',
  'server.moderation.errors.invalid_match':'Связанный матч недоступен для этой жалобы.',
  'server.moderation.errors.invalid_status':'Некорректный статус жалобы.',
  'server.moderation.errors.report_not_found':'Жалоба не найдена.',
  'server.tournament_runtime.scheduling.single_official_only':'Одновременно может существовать только один официальный турнир.',
  'server.tournament_runtime.scheduling.start_required':'Укажите дату и время начала турнира.',
  'server.tournament_runtime.scheduling.start_invalid':'Некорректная дата или время начала турнира.',
  'server.tournament_runtime.scheduling.start_future':'Дата начала турнира должна быть в будущем.',
  'server.tournament_runtime.scheduling.already_scheduled':'Дата турнира уже назначена. Перенос или задержка не входят в MVP-21.3.',
  'server.tournament_runtime.scheduling.roster_required':'Назначить дату можно только после полного набора состава.',
  'server.tournament_runtime.scheduling.capacity_required':'Дата турнира назначается только для полностью набранного состава.',
  'server.tournament_runtime.scheduling.consent_required':'Не у всех участников зафиксировано согласие с правилами турнира.',
  'server.tournament_runtime.scheduling.schedule_race':'Дата турнира изменилась одновременно с запросом.',
  'server.support.errors.invalid_queue_mode':'Некорректный режим очереди.',
  'server.support.errors.invalid_queue_filter':'Некорректный фильтр очереди.',
  'server.support.errors.invalid_status_admin':'Некорректный статус обращения.',
  'server.support.errors.invalid_priority_admin':'Некорректный приоритет обращения.',
  'server.support.errors.reopen_required':'Сначала откройте обращение заново.',
  'server.shop_history.reject_reason_missing':'Причина не указана.',
  'server.shop_history.prize_fallback':'Приз',
  'server.shop_history.statuses.pending':'Ожидает обработки',
  'server.shop_history.statuses.processing':'В обработке',
  'server.shop_history.statuses.done':'Выполнена',
  'server.shop_history.statuses.rejected':'Отклонена',
  'server.shop_history.statuses.cancelled':'Отменена',
  'server.shop_history.statuses.unknown':'Статус уточняется',
};
for(const [key,value] of Object.entries(expectedCopy)){
  assert(get(locale,key)===value,`RU catalog copy drift: ${key}`);
}

console.log('MVP27_1_BACKEND_BASELINE_PLAYER_LOCALIZATION_BUNDLE=PASS');
console.log('classified_baseline_files=19');
console.log('classified_baseline_cyrillic_lines=146');
console.log('localized_active_backend_files=7');
console.log('localized_active_backend_cyrillic_lines=58');
console.log('backend_player_debt=385');
