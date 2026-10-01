import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const home = fs.readFileSync('app/assets/js/screens/home-screen.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const requiredKeys = [
  ['home.stats.online_players','Игроков онлайн'],
  ['home.stats.active_matches','Активных матчей'],
  ['home.menu.title','Меню'],
  ['home.menu.feedback','Обратная связь'],
  ['home.menu.idea','Предложить идею'],
  ['home.menu.report','Пожаловаться'],
  ['home.menu.tickets','Мои обращения'],
  ['home.menu.balance_history','История баланса'],
  ['home.menu.match_history','История матчей'],
  ['home.history.balance_title','История баланса'],
  ['home.history.match_title','История матчей'],
  ['home.history.operation','Операция'],
  ['home.history.operations_empty','Операций пока нет.'],
  ['home.history.operations_title','Операции баланса'],
  ['home.history.match','Матч'],
  ['home.history.opponent','Соперник'],
  ['home.history.matches_empty','Истории матчей пока нет.'],
  ['home.history.latest_games','Последние игры'],
];

function readPath(source,key){
  return key.split('.').reduce((value,part)=>value?.[part],source);
}
for (const [key,value] of requiredKeys) {
  assert(readPath(ru,key) === value, `RU Home localization key mismatch: ${key}`);
  assert(home.includes(`t('${key}'`), `Home owner does not consume localization key: ${key}`);
}

for (const reason of ['nickname','avatar','spam','cheating','stalling','other']) {
  assert(typeof ru.home?.report?.reasons?.[reason] === 'string', `Report reason key missing: ${reason}`);
  assert(home.includes(`t('home.report.reasons.${reason}')`), `Report reason is not localized: ${reason}`);
}

assert(!home.includes('<h2>Меню</h2>'), 'Home menu title must not remain hardcoded.');
assert(!home.includes("item.title||'Операция'"), 'History operation fallback must not remain hardcoded.');
assert(!home.includes('<h3>Операции баланса</h3>'), 'Balance history section title must not remain hardcoded.');
assert(!home.includes("item.result||'Матч'"), 'Match result fallback must not remain hardcoded.');
assert(!home.includes("item.opponent||'Соперник'"), 'Opponent fallback must not remain hardcoded.');
assert(!home.includes('<span>Соперник: ${escapeHtml(opponent)}</span>'), 'Opponent line must not remain hardcoded.');
assert(!home.includes('normalized} коинов'), 'Match delta coin suffix must not remain hardcoded.');

assert(manifest.includes('home-screen.js?v=90&mvp25_3=human-rules-guide-v1'));
assert(manifest.includes('mvp27_1=home-shell-history-i18n-v1'),
  'Active Home owner must carry the MVP-27.1 localization cache identity.');

for (const acceptedOwner of [
  'function openRulesSheet()',
  'amount(entry)',
  'amount(winnerReward)',
  'amount(commission)',
  'amount(drawRefund)',
  "menuItemMarkup('matchHistoryBtn'",
  "document.getElementById('rulesBtn')",
  "document.getElementById('balanceHistoryBtn')",
  "document.getElementById('matchHistoryBtn')",
]) {
  assert(home.includes(acceptedOwner), `Accepted Home owner missing after localization migration: ${acceptedOwner}`);
}

console.log('MVP27_1_HOME_SHELL_LOCALIZATION_CONTRACT=PASS');
