import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const home = fs.readFileSync('app/assets/js/screens/home-screen.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const start = home.indexOf('function openPlayerReportSheet(){');
const end = home.indexOf('\nfunction isSupportTicketsCacheStale(){', start);
assert(start >= 0 && end > start, 'Player-report owner boundary missing.');
const owner = home.slice(start, end);

assert(!/[\u0400-\u04FF]/.test(owner), 'Player-report owner must not contain hardcoded Cyrillic.');
assert(home.includes('const PLAYER_REPORT_REASON_CODES = Object.freeze(['), 'Stable report reason codes owner missing.');
assert(home.includes('function playerReportReasons(){'), 'Dynamic localized report reasons owner missing.');
assert(!home.includes('const PLAYER_REPORT_REASONS = Object.freeze(['), 'Report reasons must not freeze translated labels at module load.');

for (const code of ['nickname','avatar','spam','cheating','stalling','other']) {
  assert(home.includes("'" + code + "'"), `Report reason code changed or missing: ${code}`);
  assert(ru.home?.report?.reasons?.[code], `RU report reason missing: ${code}`);
}
for (const apiOwner of [
  "action:'report_lookup'",
  "action:'report_history'",
  "action:'report'",
  "target_mgw_id:String(selectedPlayer?.mgw_id||'')",
  'reason:selectedReason',
  'details,',
]) {
  assert(owner.includes(apiOwner), `Accepted report API owner missing: ${apiOwner}`);
}

for (const key of [
  'title','subtitle','target_question','search_note','search_placeholder','search_action','search_hint',
  'reason_title','choose_one','comment','optional','comment_placeholder','send','history_title',
  'history_loading','player_fallback','change','selected','not_found','found','choose','enter_query',
  'nickname_min','searching','search_error','status_open','status_reviewing','status_closed',
  'history_empty','complaint_fallback','review_complete','history_error','sent_case','sent','send_error'
]) {
  assert(typeof ru.home?.report?.[key] === 'string' && ru.home.report[key].length > 0,
    `RU player-report key missing: ${key}`);
  assert(owner.includes(`home.report.${key}`), `Player-report owner does not consume key: ${key}`);
}

assert(manifest.includes('mvp27_1_report=localized-v1'),
  'Active Home owner must publish player-report localization cache identity.');

console.log('MVP27_1_HOME_REPORT_LOCALIZATION_CONTRACT=PASS');
