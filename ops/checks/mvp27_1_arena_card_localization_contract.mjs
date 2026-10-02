import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const start = arena.indexOf('function startTournamentRenderedCountdownTicker');
const end = arena.indexOf('\nfunction bindTabs(screen){', start);
assert(start >= 0 && end > start, 'Arena tournament-card localization boundary missing.');
const region = arena.slice(start, end);

assert(!/[\u0400-\u04FF]/.test(region),
  'Arena tournament-card/cancellation/schedule region must not contain hardcoded Cyrillic.');
assert(!arena.includes('ru-RU'), 'Arena must not hardcode the RU Intl locale.');
assert(arena.includes("formatDateTime as formatLocalizedDateTime"),
  'Arena must use canonical locale-aware datetime formatting.');
assert(region.includes("formatLocalizedDateTime(date,'short')"),
  'Arena tournament/consent timestamps must use canonical locale-aware formatting.');

for (const key of [
  'started','emergency_stop','cancelled','refund','coins','refund_full','cancel_reason',
  'cancel_reason_default','cancel_closed','unavailable','rules_title','rules_unavailable',
  'consent','consent_accepted','consent_accepted_at','insufficient','registering','checking',
  'register','cancelling','cancel_registration','saving_consent','confirm_rules',
  'state_draft','state_scheduled','state_waiting_date','state_full','state_open',
  'own_scheduled','own_scheduled_appointed','own_waiting_date','own_full','own_registered',
  'own_roster_fixed','own_registration_done','own_insufficient','schedule_aria','start_local',
  'until_start','entry','capacity_scheduled','capacity_waiting','capacity_open','participants',
  'first_place','second_place','third_place','silver_reward','bronze_reward','days_short',
  'error_insufficient','error_runtime','error_registration_race','error_balance_race',
  'error_balance_identity','error_account_identity'
]) {
  assert(typeof ru.arena?.card?.[key] === 'string' && ru.arena.card[key].length > 0,
    `RU Arena card key missing: ${key}`);
  assert(region.includes(`arena.card.${key}`),
    `Arena card owner does not consume key: ${key}`);
}

for (const invariant of [
  "state === 'registration_open'",
  "state === 'waiting_for_date'",
  "state === 'scheduled'",
  "tournament.is_full === true",
  "Number(tournament.capacity || 0)",
  "Number(tournament.registered_count || 0)",
  "Number(tournament?.entry_fee?.amount || 50000)",
  "registration?.rules_consent?.accepted === true",
  "String(registration?.rules_consent?.sha256 || '') === String(rules.sha256 || '')",
  'data-tournament-action="register"',
  'data-tournament-action="leave"',
  "tournamentPendingAction === 'register'",
  "tournamentPendingAction === 'leave'",
  'data-tournament-rules-consent',
  'scheduleTournamentStartBoundaryRefresh(scheduledStart, registered)',
  'startTournamentRenderedCountdownTicker(body, scheduledStart)',
  "String(cancelled.kind || '') === 'emergency'",
]) {
  assert(region.includes(invariant), `Accepted Arena card invariant missing: ${invariant}`);
}

for (const backendError of [
  'Insufficient available balance.',
  'Tournament registration requires canonical DB-primary runtime state.',
  'Tournament registration changed concurrently.',
  'Concurrent balance update was detected.',
  'Balance identity does not match the account reference.',
  'Canonical tournament account_ref is required.',
]) {
  assert(region.includes(backendError), `Known backend registration error mapping missing: ${backendError}`);
}

assert(manifest.includes('mvp27_1_card=cancellation-schedule-i18n-v1'),
  'Active Arena owner must publish tournament-card localization cache identity.');

console.log('MVP27_1_ARENA_CARD_LOCALIZATION_CONTRACT=PASS');
