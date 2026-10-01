import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const hallStart = arena.indexOf('function tournamentHallMarkup(');
const hallEnd = arena.indexOf('\nfunction tournamentBracketMarkup(', hallStart);
const readyStart = arena.indexOf('function tournamentMatchMarkup(');
const readyEnd = arena.indexOf('\nconst TOURNAMENT_TECHNICAL_RESULT_LABELS', readyStart);
assert(hallStart >= 0 && hallEnd > hallStart, 'Tournament Hall owner boundary missing.');
assert(readyStart >= 0 && readyEnd > readyStart, 'Tournament readiness owner boundary missing.');
const hall = arena.slice(hallStart, hallEnd);
const ready = arena.slice(readyStart, readyEnd);

assert(!/[\u0400-\u04FF]/.test(hall), 'Tournament Hall owner must contain zero hardcoded Cyrillic.');
assert(!/[\u0400-\u04FF]/.test(ready), 'Tournament readiness owner must contain zero hardcoded Cyrillic.');

for (const key of [
  'entering','enter','label','started','open','opens_15','gate_note','present_at_start',
  'technical_loss','present','left','not_entered','player_fallback','bracket_hidden',
  'bracket_hidden_note','bracket_title','start','inside_title','participants'
]) {
  assert(typeof ru.arena?.hall?.[key] === 'string' && ru.arena.hall[key].length > 0,
    `RU Arena Hall key missing: ${key}`);
  assert(hall.includes(`arena.hall.${key}`) || ready.includes(`arena.hall.${key}`),
    `Arena Hall/ready owner does not consume key: ${key}`);
}

for (const key of [
  'saving_result','loading_pair','match_label','self_suffix','ready','waiting','prompt',
  'expired','launched','both_ready','self_ready','confirming','action','first_match_pair'
]) {
  assert(typeof ru.arena?.ready?.[key] === 'string' && ru.arena.ready[key].length > 0,
    `RU Arena ready key missing: ${key}`);
  assert(ready.includes(`arena.ready.${key}`), `Arena readiness owner does not consume key: ${key}`);
}

for (const invariant of [
  '15 * 60 * 1000',
  'data-tournament-hall-enter',
  'data-hall-opens-at',
  'data-hall-start-at',
  "tournamentHallSnapshot?.hall",
  "tournamentHallSnapshot?.bracket",
]) {
  assert(hall.includes(invariant), `Tournament Hall invariant missing: ${invariant}`);
}

for (const invariant of [
  "readiness_deadline_at_utc",
  "String(match.launch_state || '') === 'readiness_expired'",
  "String(match.launch_state || '') === 'launched'",
  'match.both_ready === true',
  'match.self_ready === true',
  'match.can_ready === true',
  'data-tournament-ready',
  'formatReadyCountdown',
]) {
  assert(ready.includes(invariant), `Tournament readiness invariant missing: ${invariant}`);
}

assert(manifest.includes('mvp27_1_hall=ready-i18n-v1'),
  'Active Arena owner must publish Hall/ready localization cache identity.');

console.log('MVP27_1_ARENA_HALL_READY_LOCALIZATION_CONTRACT=PASS');
