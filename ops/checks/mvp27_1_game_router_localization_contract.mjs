import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const router = read('app/assets/js/games/game-router.js');
const wrapper = read('app/assets/js/games/game-router-v102.js');
const manifest = read('app/runtime/client/version-manifest.php');
const locale = JSON.parse(read('app/locales/ru.json'));
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(!/[\u0400-\u04FF]/.test(router), 'Active game router must contain zero hardcoded Cyrillic');
assert.ok(router.includes("import { t } from '@mgw/i18n';"), 'Active game router must consume canonical @mgw/i18n');

for (const token of [
  "explicit === 'domino'",
  "renderer === 'domino'",
  "actionType === 'domino_action'",
  "title.includes(aliases.domino)",
  "title.includes('domino')",
  "explicit === 'go'",
  "actionType === 'go_action'",
  "title === aliases.go",
  "title.includes('go')",
  "explicit === 'chess'",
  "actionType === 'chess_move'",
  "title.includes(aliases.chess)",
  "title.includes('chess')",
  "explicit === 'reversi'",
  "title.includes(aliases.reversi)",
  "title.includes('reversi')",
  "explicit === 'checkers'",
  "actionType === 'checkers_move'",
  "title.includes(aliases.checkers)",
  "title.includes('checkers')",
  "explicit === 'battleship'",
  "actionType === 'battleship_action'",
  "title.includes(aliases.battleship)",
  "title.includes('battleship')",
  "explicit === 'four_in_a_row'",
  "actionType === 'column'",
  "title.includes(aliases.fourInARow)",
  "title.includes('four in a row')",
  "return explicit || 'tictactoe';",
]) assert.ok(router.includes(token), 'Game routing predicate changed: ' + token);

for (const token of [
  "Array.isArray(game?.viewer_hand)",
  "Array.isArray(game?.chain)",
  "Boolean(game?.final_score?.komi)",
  "Boolean(game?.king_cells)",
  "Object.prototype.hasOwnProperty.call(game.final_counts, 'black')",
  "Number(game?.board_size) === 8",
  "Array.isArray(game?.my_board)",
  "Array.isArray(game?.enemy_board)",
  "rows >= 5 && connectLength === 4",
  "boardStringLength === columns * rows",
]) assert.ok(router.includes(token), 'Structural game routing evidence changed: ' + token);

for (const token of [
  "renderTicTacToeSurface",
  "renderFourInARowSurface",
  "renderBattleshipSurface",
  "renderCheckersSurface",
  "renderReversiSurface",
  "renderChessSurface",
  "renderGoSurface",
  "renderDominoSurface",
  "route.render({ game, me, container, onAction });",
]) assert.ok(router.includes(token), 'Game render delegation changed: ' + token);

assert.ok(router.includes("t('games.router.game_fallback')"), 'Router fallback room must use localization');
assert.ok(router.includes("t('games.router.meta_with_bet', { room, bet })"), 'Router fallback meta must use localization');
assert.ok(router.includes("t('games.router.status.finished')"), 'Router finished status must use localization');
assert.ok(router.includes("t('games.router.status.your_turn')"), 'Router self-turn status must use localization');
assert.ok(router.includes("t('games.router.status.opponent_turn')"), 'Router opponent-turn status must use localization');
assert.ok(router.includes("t('games.router.unsupported', { game:gameLabel })"), 'Unsupported-game copy must use localization');
assert.ok(router.includes("const gameLabel = escapeHtml(game?.game_title || gameTypeOf(game));"),
  'Unsupported-game interpolation must remain escaped before localization');

assert.ok(wrapper.includes("from './game-router.js?v=74';"), 'v102 wrapper must retain canonical game-router specifier');
assert.ok(manifest.includes("'./assets/js/games/game-router.js?v=74' => './assets/js/games/game-router.js?v=75&mvp27_1=localized-v1'"),
  'Manifest must publish localized game-router identity');

assert.ok(Number(locale?._meta?.version) >= 52, 'RU locale revision must include game-router localization');
assert.deepEqual(locale?.games?.router?.aliases, {
  domino:'домино',
  go:'го',
  chess:'шахмат',
  reversi:'реверси',
  checkers:'шашк',
  battleship:'морской бой',
  four_in_a_row:'4 в ряд',
}, 'RU game-router aliases must preserve the exact pre-localization heuristics');
assert.equal(locale?.games?.router?.game_fallback, 'Игра');
assert.equal(locale?.games?.router?.meta_with_bet, '{room} · {bet} коинов');
assert.equal(locale?.games?.router?.status?.finished, 'Игра завершена');
assert.equal(locale?.games?.router?.status?.your_turn, 'Ваш ход');
assert.equal(locale?.games?.router?.status?.opponent_turn, 'Ход соперника');
assert.equal(locale?.games?.router?.unsupported, 'Экран игры «{game}» пока не подключён.');

assert.ok(Number(baseline.scanned_files) <= 720, 'Game-router successor must not restore classified runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1944, 'Game-router localization total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 291, 'Game-router localization client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 game-router localization: OK — 12 active Cyrillic lines moved to canonical locale ownership with routing/render invariants frozen.');
