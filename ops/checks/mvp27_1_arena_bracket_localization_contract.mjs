import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const start = arena.indexOf('const TOURNAMENT_TECHNICAL_RESULT_KEYS = Object.freeze({');
const end = arena.indexOf('\nconst TOURNAMENT_TERMINAL_REWARD_KEYS = Object.freeze({', start);
assert(start >= 0 && end > start, 'Arena bracket localization boundary missing.');
const bracketRegion = arena.slice(start, end);

assert(!/[\u0400-\u04FF]/.test(bracketRegion),
  'Arena bracket/technical-outcome region must not contain hardcoded Cyrillic.');

for (const code of [
  'technical_loss_at_start','both_absent_at_start','technical_bye_vacant_slot',
  'vacant_bracket_slot','player_left','disconnect_timeout',
  'tournament_disconnect_timeout','tournament_both_absent_timeout',
  'technical_restart_scheduled','technical_restart_exhausted'
]) {
  assert(bracketRegion.includes(`${code}:'${code}'`),
    `Stable tournament technical result code missing: ${code}`);
  assert(typeof ru.arena?.bracket?.[code] === 'string' && ru.arena.bracket[code].length > 0,
    `RU technical result translation missing: ${code}`);
}
assert(bracketRegion.includes("return key ? t(`arena.bracket.${key}`) : '';"),
  'Technical outcome labels must resolve dynamically through i18n.');

for (const key of [
  'final_round','semifinal','round','pair','final','third_place',
  'self','participant','champion','second_place','no_result','third_place_status',
  'fourth_place','advanced','eliminated','self_playing','playing',
  'vacant_slot','no_participant','waiting','final_done','third_done',
  'winner_advances','done_no_winner','running','restart_minute','round_break',
  'round_completed'
]) {
  assert(typeof ru.arena?.bracket?.[key] === 'string' && ru.arena.bracket[key].length > 0,
    `RU Arena bracket key missing: ${key}`);
  assert(bracketRegion.includes(`arena.bracket.${key}`),
    `Arena bracket owner does not consume key: ${key}`);
}

for (const invariant of [
  "resultReason = 'both_absent_at_start'",
  "resultReason = 'technical_loss_at_start'",
  "match_kind:'elimination'",
  "launch_state:completed ? 'completed' : 'waiting_ready'",
  "String(match?.launch_state || '') === 'launched'",
  "String(match?.wait_kind || '') === 'technical_restart'",
  "String(match?.wait_kind || '') === 'round_break'",
  'tournamentRoundSectionsMarkup(bracket, progression)',
  'data-tournament-round-archive=',
]) {
  assert(arena.includes(invariant), `Accepted Arena bracket invariant missing: ${invariant}`);
}

assert(manifest.includes('mvp27_1_bracket=outcomes-i18n-v1'),
  'Active Arena owner must publish bracket localization cache identity.');

console.log('MVP27_1_ARENA_BRACKET_LOCALIZATION_CONTRACT=PASS');
