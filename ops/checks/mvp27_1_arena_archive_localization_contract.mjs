import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const arena = fs.readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const ru = JSON.parse(fs.readFileSync('app/locales/ru.json', 'utf8'));
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');

assert(!/[\u0400-\u04FF]/.test(arena),
  'Active Arena owner must contain zero hardcoded Cyrillic after MVP-27.1 Arena closure.');
assert(!arena.includes('ru-RU'), 'Arena must contain zero direct RU Intl locale ownership.');

for (const key of [
  'load_error','hall_title','championships','date_unknown','podium_unavailable','participants'
]) {
  assert(typeof ru.arena?.archive?.[key] === 'string' && ru.arena.archive[key].length > 0,
    `RU Arena archive key missing: ${key}`);
  assert(arena.includes(`arena.archive.${key}`),
    `Arena archive owner does not consume key: ${key}`);
}

for (const existingKey of [
  'arena.official_title',
  'profile.player',
  'profile.rating_points',
  'profile.leaderboard_error',
  'profile.leaderboard_empty',
  'shell.competition_archive_tournaments_empty',
  'shell.competition_archive_empty',
  'shell.competition_archive_top100',
  'shell.competition_hall_of_fame',
]) {
  assert(arena.includes(existingKey), `Arena must preserve existing localized owner: ${existingKey}`);
}

for (const invariant of [
  'api.ratingArchiveOverview()',
  'api.ratingArchiveSeason(seasonId, gameType)',
  'loadTournamentArchiveOverview',
  'renderTournamentArchiveOverview',
  'tournamentHallOfFameCard',
  'tournamentHallTrophySvg',
  'tournamentArchiveCard',
  'tournaments-v2-tournament-archive-podium',
  "document.addEventListener('mgw:tournament-hall-of-fame-open'",
  'hallOfFameForArchive',
  'leaderboardRow',
]) {
  assert(arena.includes(invariant), `Accepted Arena archive/leaderboard invariant missing: ${invariant}`);
}

assert(manifest.includes('mvp27_1_archive=localized-v1'),
  'Active Arena owner must publish archive localization cache identity.');

console.log('MVP27_1_ARENA_ARCHIVE_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ARENA_HARDCODED_CYRILLIC=0');
