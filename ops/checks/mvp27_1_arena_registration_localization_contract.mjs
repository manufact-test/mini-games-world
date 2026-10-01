import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

const requiredKeys = [
  'scroll_left','scroll_right','official_title',
];
for (const key of requiredKeys) {
  assert(typeof ru.arena?.[key] === 'string' && ru.arena[key].length > 0, `RU Arena key missing: ${key}`);
  assert(arena.includes(`arena.${key}`), `Arena owner does not consume key: ${key}`);
}

for (const key of [
  'sync_start','sync_launch','update_hall','update_tournament','sync_result',
  'ready','enter_hall','update_pair_ready','update_presence','load'
]) {
  assert(typeof ru.arena?.errors?.[key] === 'string' && ru.arena.errors[key].length > 0, `RU Arena error key missing: ${key}`);
  assert(arena.includes(`arena.errors.${key}`), `Arena owner does not consume error key: ${key}`);
}

for (const key of [
  'read_rules','confirm_updated_rules','confirm_register','confirm_leave',
  'register_not_saved','leave_not_saved','publish_not_saved','change_error'
]) {
  assert(typeof ru.arena?.registration?.[key] === 'string' && ru.arena.registration[key].length > 0,
    `RU Arena registration key missing: ${key}`);
  assert(arena.includes(`arena.registration.${key}`), `Arena owner does not consume registration key: ${key}`);
}

for (const apiOwner of [
  'api.tournamentHallStatus()',
  'api.tournamentMatchState()',
  'api.tournamentHallEnter()',
  'api.tournamentHallHeartbeat()',
  'api.tournamentRegister({',
  'api.tournamentLeave()',
  'api.tournamentStatus()',
  'api.tournamentRegistrationPublish()',
]) {
  assert(arena.includes(apiOwner), `Accepted Arena API owner missing: ${apiOwner}`);
}

for (const invariant of [
  "const fee = formatNumber(Math.max(0, Number(tournament?.entry_fee?.amount || 50000)));",
  "action === 'register'",
  "action === 'leave'",
  "registrationState !== 'registered'",
  "publicationSnapshot?.registration?.published !== true",
]) {
  assert(arena.includes(invariant), `Arena registration invariant missing: ${invariant}`);
}

for (const removed of [
  'Прокрутить игры влево','Прокрутить игры вправо',
  'Не удалось синхронизировать старт турнира.','Не удалось синхронизировать запуск матча.',
  'Не удалось обновить Турнирный зал.','Не удалось обновить турнир.',
  'Не удалось синхронизировать результат турнира.','Не удалось подтвердить готовность.',
  'Не удалось войти в Турнирный зал.','Не удалось обновить готовность пары.',
  'Не удалось обновить присутствие в Турнирный зал.','Не удалось загрузить турнир.',
  'Перед регистрацией прочитайте правила и подтвердите согласие.',
  'Подтвердить обновлённые правила турнира?','Регистрация не сохранилась. Попробуйте ещё раз.',
  'Отмена регистрации не сохранилась. Попробуйте ещё раз.',
  'Регистрация сохранилась, но ещё не опубликована. Повторите попытку.',
]) {
  assert(!arena.includes(removed), `Migrated Arena copy remains hardcoded: ${removed}`);
}
assert(!arena.includes('<h2>Официальный турнир</h2>'),
  'Official tournament top-level heading must use the Arena catalog.');
assert(!arena.includes("humanizeTournamentError(error?.message || 'Не удалось изменить регистрацию.')"),
  'Registration mutation fallback must use the Arena catalog.');

assert(manifest.includes('mvp27_1=registration-sync-i18n-v1'),
  'Active Arena owner must publish registration localization cache identity.');

console.log('MVP27_1_ARENA_REGISTRATION_LOCALIZATION_CONTRACT=PASS');
