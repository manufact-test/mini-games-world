import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const technicalStart = arena.indexOf('const TOURNAMENT_TECHNICAL_RESULT_KEYS');
const technicalEnd = arena.indexOf('\nconst TOURNAMENT_TERMINAL_REWARD_LABELS', technicalStart);
const progressionStart = arena.indexOf('function tournamentProgressionMarkup(');
const progressionEnd = arena.indexOf('\nfunction stopTournamentRenderedCountdownTicker', progressionStart);
assert(technicalStart >= 0 && technicalEnd > technicalStart, 'Technical/bracket owner boundary missing.');
assert(progressionStart >= 0 && progressionEnd > progressionStart, 'Progression owner boundary missing.');
const bracketOwner = arena.slice(technicalStart, technicalEnd);
const progressionOwner = arena.slice(progressionStart, progressionEnd);

assert(!/[\u0400-\u04FF]/.test(bracketOwner), 'Technical/bracket owner must contain zero hardcoded Cyrillic.');
assert(!/[\u0400-\u04FF]/.test(progressionOwner), 'Progression owner must contain zero hardcoded Cyrillic.');

const technicalCodes = [
  'technical_loss_at_start','both_absent_at_start','technical_bye_vacant_slot',
  'vacant_bracket_slot','player_left','disconnect_timeout',
  'tournament_disconnect_timeout','tournament_both_absent_timeout',
  'technical_restart_scheduled','technical_restart_exhausted'
];
for (const code of technicalCodes) {
  assert(arena.includes(`${code}:'arena.technical.${code}'`),
    `Stable technical outcome mapping missing: ${code}`);
  assert(typeof ru.arena?.technical?.[code] === 'string' && ru.arena.technical[code].length > 0,
    `RU technical outcome key missing: ${code}`);
}

for (const key of [
  'player_fallback','final_round','semifinal','round','pair','final','third_place',
  'self','participant','champion','second_place','no_result','third_place_status','fourth_place',
  'advanced','eliminated','self_playing','playing','self_suffix','free_slot','no_participant',
  'waiting_launch','final_complete','third_place_complete','winner_advances',
  'match_complete_no_winner','match_live','technical_restart','round_break','completed'
]) {
  assert(typeof ru.arena?.bracket?.[key] === 'string' && ru.arena.bracket[key].length > 0,
    `RU bracket key missing: ${key}`);
  assert(bracketOwner.includes(`arena.bracket.${key}`) || progressionOwner.includes(`arena.bracket.${key}`),
    `Bracket/progression owner does not consume key: ${key}`);
}

for (const key of [
  'finished_wait_round','eliminated_next_round','eliminated','next_round_ready',
  'stage_round_pair','next_match_preparing','draw_replay_wait','draw_replay_ready',
  'round_break_wait','round_break_ready','repeat_preparing','repeat_ready',
  'attempt_suffix','tournament_started'
]) {
  assert(typeof ru.arena?.progression?.[key] === 'string' && ru.arena.progression[key].length > 0,
    `RU progression key missing: ${key}`);
  assert(arena.includes(`arena.progression.${key}`), `Arena does not consume progression key: ${key}`);
}

for (const invariant of [
  "resultReason = 'both_absent_at_start'",
  "resultReason = 'technical_loss_at_start'",
  "match_kind:'elimination'",
  "String(match?.match_kind || 'elimination')",
  "String(match?.wait_kind || '')",
  "waitKind === 'draw_replay'",
  "waitKind === 'round_break'",
  'attemptNo > 1',
  'data-tournament-round-archive',
  'data-tournament-progression-countdown',
  'formatReadyCountdown(opensAt.getTime() - Date.now())'
]) {
  assert(arena.includes(invariant), `Tournament progression semantic invariant missing: ${invariant}`);
}

assert(arena.includes("countdown.textContent = startedNow ? t('arena.progression.tournament_started')"),
  'Tournament start ticker must use localization.');
assert(arena.includes("hallButton.textContent = t('arena.hall.enter');"),
  'Hall ticker must not restore hardcoded RU copy.');
assert(manifest.includes('mvp27_1_progression=bracket-i18n-v1'),
  'Active Arena owner must publish progression localization cache identity.');

console.log('MVP27_1_ARENA_PROGRESSION_LOCALIZATION_CONTRACT=PASS');
