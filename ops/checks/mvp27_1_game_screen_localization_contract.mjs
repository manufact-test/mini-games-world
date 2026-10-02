import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const game = read('app/assets/js/screens/game-screen-v102.js');
const manifest = read('app/runtime/client/version-manifest.php');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
const ru = JSON.parse(read('app/locales/ru.json'));

assert(!/[\u0400-\u04FF]/.test(game), 'Active Game Screen must contain zero hardcoded Cyrillic.');
assert(game.includes("from '@mgw/i18n'"), 'Active Game Screen must use canonical @mgw/i18n.');
assert(game.includes("gameText('result.not_started_title')") && game.includes("gameText('result.not_started_text')"),
  'Preparation-timeout Result copy must resolve through locale keys.');
assert(game.includes("gameText('result.summary', { delta, balance })"),
  'Result economy summary must resolve through canonical locale ownership.');
assert(game.includes("gameText('result.go_tournament')") && game.includes("gameText('result.new_opponent')") && game.includes("gameText('result.home')"),
  'Accepted Result navigation actions must resolve through canonical locale ownership.');
assert(game.includes("await api.gameAction(gameId, queued.action)") && game.includes("const result = await api.leaveGame(id)"),
  'Game action and surrender network owners must remain unchanged.');
assert(game.includes('function buildOptimisticSurrender(game, viewerId)') && game.includes('await api.history()'),
  'Optimistic surrender and authoritative Result hydration owners must remain unchanged.');
assert(game.includes('id="newOpponent"') && game.includes('id="goHome"') && game.includes('id="goTournament"'),
  'Accepted Result action IDs must remain stable.');
assert(game.includes("document.dispatchEvent(new CustomEvent('mgw:tournament-progression-open'))")
  && game.includes("document.dispatchEvent(new CustomEvent('mgw:v99-search-request'"),
  'Tournament return and rematch-search handoffs must remain unchanged.');
assert(game.includes("if (game.finish_reason === 'preparation_timeout')")
  && game.includes("game.finish_reason === 'timeout'")
  && game.includes("game.finish_reason === 'player_left'"),
  'Accepted terminal Result classification must remain intact.');

assert(Number(ru._meta?.version || 0) >= 34, 'RU locale revision must retain Game Screen v34 or a newer successor.');
assert(ru.game_screen?.leave?.title === 'Выйти из матча?', 'Accepted RU leave title must be preserved.');
assert(ru.game_screen?.result?.not_started_title === 'Матч не начался', 'Accepted RU preparation-timeout title must be preserved.');
assert(ru.game_screen?.result?.go_tournament === 'Вернуться в турнир', 'Accepted RU tournament return action must be preserved.');
assert(ru.game_screen?.result?.summary === 'За игру: {delta} · Баланс: {balance}', 'Accepted RU economy summary must be preserved.');
assert(ru.game_screen?.result?.chess_draw?.fifty_move === 'Сработало правило 50 ходов.', 'Accepted RU chess draw explanation must be preserved.');
assert(ru.game_screen?.result?.domino_blocked_score === 'Партия заблокирована. Оставшиеся точки: {mine}:{theirs}.', 'Accepted RU Domino terminal copy must be preserved.');

assert(manifest.includes('mvp27_1=game-screen-localized-v1'), 'Canonical manifest must publish localized Game Screen identity.');
assert(baseline.cyrillic_lines_total <= 3619 && baseline.by_scope?.client <= 1938,
  'Game Screen localization debt must never regress above the accepted post-Game-Screen baseline.');

console.log('MVP27_1_GAME_SCREEN_LOCALIZATION_CONTRACT=PASS');
console.log('MVP27_1_ACTIVE_GAME_SCREEN_HARDCODED_CYRILLIC=0');
