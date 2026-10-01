import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const home = fs.readFileSync('app/assets/js/screens/home-screen.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const start = home.indexOf('function openRulesSheet(){');
const end = home.indexOf('\nasync function openBalanceHistorySheet(){', start);
assert(start >= 0 && end > start, 'Home rules guide owner boundary missing.');
const owner = home.slice(start, end);

assert(!/[\u0400-\u04FF]/.test(owner), 'Home rules guide owner must not contain hardcoded Cyrillic after MVP-27.1 migration.');

for (const needle of [
  'APP_CONFIG.matchEconomy',
  'amount(entry)',
  'amount(winnerReward)',
  'amount(commission)',
  'amount(drawRefund)',
  'amount(pot)',
  'class="rules-guide-section"',
  'class="rules-guide-section rules-guide-economy"',
  'class="rules-guide-numbers"',
  "t('home.rules_guide.title')",
  "t('rules.understood')",
  "t('common.close')",
]) {
  assert(owner.includes(needle), `Accepted rules-guide owner missing: ${needle}`);
}

assert(home.includes('function localizedStrongHtml(key, values = {})'), 'Safe rich localization helper missing.');
assert(home.includes('let html = escapeHtml(t(key, params));'), 'Rich localization must escape translated text before markup insertion.');
assert(home.includes('replacements.push([token,'), 'Rich localization placeholder replacement owner missing.');
assert(home.includes('<strong>${escapeHtml(value)}</strong>'), 'Rich localization must escape every strong placeholder value.');
assert(!home.includes('innerHTML=t('), 'Raw translated HTML must not be assigned directly.');

const required = {
  title:'Как работают обычные матчи',
  start_title:'1. Как начать игру',
  cost_title:'2. Сколько стоит матч',
  result_title:'3. Победа, поражение и ничья',
  leave_title:'4. Не выходите из незавершённого матча',
  game_rules_title:'5. Правила конкретной игры',
  history_title:'6. Где проверить результат',
  bonus_title:'7. Бонусы и турниры',
  problems_title:'8. Если что-то пошло не так',
  coin_amount:'{count} коинов',
};
for (const [key,value] of Object.entries(required)) {
  assert(ru.home?.rules_guide?.[key] === value, `RU rules-guide key mismatch: ${key}`);
}
for (const key of [
  'start_text_1','start_text_2','start_text_3','cost_text','cost_example',
  'win_text','loss_text','draw_text','leave_text','game_rules_text',
  'history_text','bonus_text','tournaments_text','problems_text'
]) {
  assert(typeof ru.home?.rules_guide?.[key] === 'string' && ru.home.rules_guide[key].length > 0,
    `RU rules-guide body key missing: ${key}`);
}

assert(manifest.includes('mvp27_1=home-shell-history-i18n-v1&mvp27_1_rules=localized-rich-v1'),
  'Active Home owner must publish the localized rules-guide cache identity.');

console.log('MVP27_1_HOME_RULES_LOCALIZATION_CONTRACT=PASS');
