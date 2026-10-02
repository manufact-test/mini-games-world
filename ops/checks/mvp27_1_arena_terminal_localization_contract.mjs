import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const start = arena.indexOf('const TOURNAMENT_TERMINAL_REWARD_KEYS = Object.freeze({');
const end = arena.indexOf('\nfunction stopTournamentRenderedCountdownTicker(){', start);
assert(start >= 0 && end > start, 'Arena terminal/progression localization boundary missing.');
const region = arena.slice(start, end);

assert(!/[\u0400-\u04FF]/.test(region),
  'Arena terminal/progression owner must not contain hardcoded Cyrillic.');
assert(!region.includes('TOURNAMENT_TERMINAL_REWARD_LABELS'),
  'Terminal reward labels must not freeze translated copy at module load.');

for (const code of [
  'golden_ticket','champion_crown','winner_badge','champion_cosmetics',
  'hall_of_fame','cup_gold','silver_frame','finalist_result','cup_silver',
  'bronze_mark','third_place_result','cup_bronze'
]) {
  assert(region.includes(`${code}:'${code}'`), `Stable reward code missing: ${code}`);
  assert(typeof ru.arena?.terminal?.rewards?.[code] === 'string',
    `RU terminal reward key missing: ${code}`);
}
assert(region.includes("return key ? t(`arena.terminal.rewards.${key}`) : t('arena.terminal.reward_fallback');"),
  'Terminal reward labels must resolve dynamically through i18n.');

for (const key of [
  'reward_fallback','review_kicker','completed_kicker','self_hold_title','other_hold_title',
  'settling_title','self_hold_note','other_hold_note','settling_note','champion','second_place',
  'third_place','test_no_reward','coin_amount','participation_done','disqualified','placement',
  'reward_amount','no_money_reward','balance_after','all_done_rewards','not_defined',
  'your_result','go_rating'
]) {
  assert(typeof ru.arena?.terminal?.[key] === 'string' && ru.arena.terminal[key].length > 0,
    `RU Arena terminal key missing: ${key}`);
  assert(region.includes(`arena.terminal.${key}`),
    `Arena terminal owner does not consume key: ${key}`);
}

for (const key of [
  'match_finished_wait_round','eliminated_next_round','eliminated','next_round_formed',
  'round','round_pair','next_match_preparing','draw_replay_wait','draw_replay_ready',
  'round_break_wait','round_break_ready','rematch_wait','rematch_ready','attempt_suffix'
]) {
  assert(typeof ru.arena?.progression?.[key] === 'string' && ru.arena.progression[key].length > 0,
    `RU Arena progression key missing: ${key}`);
  assert(region.includes(`arena.progression.${key}`),
    `Arena progression owner does not consume key: ${key}`);
}

for (const invariant of [
  "terminal.settlement_complete !== true",
  "settlement_state || '') === 'review_hold'",
  "terminal?.prize_review?.self_held === true",
  "item?.reward_eligible === false",
  "String(selfResult?.result_code || '') === 'disqualified'",
  "Number(selfResult?.payout_amount || 0)",
  "selfResult?.balance?.available_amount",
  "progression?.tournament_complete === true",
  "progression?.participant_eliminated === true",
  "waitKind === 'draw_replay'",
  "waitKind === 'round_break'",
  "attemptNo > 1 && waitKind !== 'draw_replay'",
  "parseTournamentUtc(match.opens_at_utc)",
]) {
  assert(region.includes(invariant), `Accepted terminal/progression invariant missing: ${invariant}`);
}

assert(manifest.includes('mvp27_1_terminal=progression-i18n-v1'),
  'Active Arena owner must publish terminal/progression localization cache identity.');

console.log('MVP27_1_ARENA_TERMINAL_LOCALIZATION_CONTRACT=PASS');
