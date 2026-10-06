import fs from 'node:fs';

const CYR=/[\u0400-\u04FF]/;
const locale=JSON.parse(fs.readFileSync('app/locales/ru.json','utf8'));
const baseline=JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
function assert(ok,msg){if(!ok) throw new Error(msg);}
function get(key){return key.split('.').reduce((v,p)=>v?.[p],locale);}
function cyrLines(path){return fs.readFileSync(path,'utf8').split(/\r?\n/).filter(l=>CYR.test(l)).length;}

assert(locale?._meta?.locale==='ru','RU locale identity changed.');
assert(locale?._meta?.version===73,'History localization must publish RU version 73.');
assert(baseline?.staging_base==='6f03100b326d84fe158ecae78d9133d53428153f','History ratchet must bind to exact post-#2083 staging.');
assert(baseline?.by_scope?.backend===848,'Backend localization debt must ratchet 885 -> 848.');
assert(baseline?.by_scope?.client===0 && baseline?.by_scope?.['client-entry']===0,'Client debt must remain zero.');

const exact={
  operations:{
    prize_order:'Заказ приза',
    prize_store_provider:'Магазин призов · {provider}',
    provider_fallback:'приз',
    ordinary_match:'Обычный матч',
    win_timeout:'Победа по таймауту',
    win_opponent_left:'Победа: соперник вышел',
    win:'Выигрыш',
    refund_opponent_not_connected:'Возврат: соперник не подключился'
  },
  match:{
    opponent_fallback:'Соперник',
    active:'Игра активна',
    not_started:'Матч не начался',
    draw:'Ничья',
    victory_timeout:'Победа по таймауту',
    victory_opponent_left:'Победа: соперник вышел',
    victory:'Победа',
    technical_defeat:'Техническое поражение',
    defeat:'Поражение'
  },
  description:{opponent_not_connected:'соперник не подключился',draw:'ничья',versus:'против {opponent}'},
  games:{four_in_a_row_short:'4 в ряд',checkers_short:'Шашки'},
  balance:{
    game_entry:'Участие в матче',
    game_win:'Выигрыш',
    game_refund:'Возврат при ничьей',
    shop_order:'Заказ приза',
    shop_refund:'Возврат за приз',
    system_migration:'Системная миграция',
    welcome_bonus:'Стартовые коины',
    weekly_bonus:'Еженедельное начисление',
    admin_gold_topup:'Начисление',
    default:'Операция баланса'
  },
  amount:{coins:'{amount} коинов'},
  rooms:{gold:'Gold-комната',match:'Match-комната'}
};
function walk(v,p='server.history'){
  for(const [k,x] of Object.entries(v)){
    const key=p+'.'+k;
    if(typeof x==='string') assert(get(key)===x,'Visible RU History copy changed for '+key);
    else walk(x,key);
  }
}
walk(exact);
for(const [key,value] of Object.entries({
  'games.tictactoe.name':'Крестики-нолики',
  'games.battleship.name':'Морской бой',
  'games.reversi.name':'Реверси',
  'games.chess.name':'Шахматы',
  'games.go.name':'Го',
  'games.domino.name':'Домино'
})) assert(get(key)===value,'Reused canonical game copy changed for '+key);

assert(cyrLines('bot/services/HistoryService.php')===0,'HistoryService must have no direct Cyrillic player copy.');
const source=fs.readFileSync('bot/services/HistoryService.php','utf8');
assert(source.includes("PRESENTATION_VERSION = 'mvp17-5-history-economy-live-owner-v3'"),'History presentation identity changed.');
assert(source.includes("$repository->read($userId, $limit)"),'History DB read ownership changed.');
assert(source.includes('mergeCurrentMatchPresentation'),'Current match merge ownership changed.');
assert(source.includes("ServerLocalization::copy('server.history.operations.refund_opponent_not_connected'"),'Preparation-timeout refund title must be locale-owned.');
assert(source.includes("ServerLocalization::copy('server.history.amount.coins'"),'Coin amount label must be locale-owned.');
assert(source.includes("ServerLocalization::copy('games.tictactoe.name'"),'Canonical Tic-Tac-Toe game name must be reused.');

console.log('MVP27_1_BACKEND_HISTORY_PLAYER_RUNTIME_LOCALIZATION=PASS');
console.log('backend_debt=848');
console.log('moved_player_facing_cyrillic_lines=37');
