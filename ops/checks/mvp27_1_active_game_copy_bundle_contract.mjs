import fs from 'node:fs';
import assert from 'node:assert/strict';

const OWNERS = [
  'app/assets/js/games/game-router.js',
  'app/assets/js/games/battleship/rules.js',
  'app/assets/js/games/checkers/rules.js',
  'app/assets/js/games/chess/rules.js',
  'app/assets/js/games/domino/rules.js',
  'app/assets/js/games/chess/renderer.js',
  'app/assets/js/games/go/renderer.js',
  'app/assets/js/games/tictactoe/renderer.js',
  'app/assets/js/games/four-in-a-row/renderer.js',
];

const read = path => fs.readFileSync(path, 'utf8');
const cyrillic = /[\u0400-\u04FF]/;

for (const path of OWNERS) {
  const source = read(path);
  assert.ok(!cyrillic.test(source), `Active localized owner still contains Cyrillic: ${path}`);
  assert.ok(source.includes("from '@mgw/i18n'"), `Active localized owner must consume canonical i18n: ${path}`);
}

const chess = read('app/assets/js/games/chess/renderer.js');
assert.ok(chess.includes("onAction({ type:'chess_move', from:selected, to:cell });"), 'Chess move action owner changed');
assert.ok(chess.includes("onAction({ type:'chess_move', from, to, promotion:String(button.dataset.chessPromotion || 'q') });"), 'Chess promotion action owner changed');
assert.ok(chess.includes('const EFFECT_LANDING_DELAY_MS = 400;'), 'Chess landing timing changed');
assert.ok(chess.includes('const MOVE_EFFECT_HOLD_MS = 1250;'), 'Chess move-effect hold changed');

const go = read('app/assets/js/games/go/renderer.js');
assert.ok(go.includes('const captureStart = 330;'), 'Go capture start changed');
assert.ok(go.includes("captureStart + Math.min(index, 10) * 38"), 'Go capture stagger changed');
assert.ok(go.includes("onAction?.({ type:'cell', cell });"), 'Go cell action owner changed');
assert.ok(go.includes("onAction?.({ type:'pass' });"), 'Go pass action owner changed');

const ttt = read('app/assets/js/games/tictactoe/renderer.js');
assert.ok(ttt.includes("type: 'cell'"), 'Tic-Tac-Toe cell action owner changed');
assert.ok(ttt.includes('game_tictactoe_effect'), 'Tic-Tac-Toe effect slot ownership changed');
assert.ok(ttt.includes('c2_6') === false, 'Renderer must not own cache-identity semantics');

const four = read('app/assets/js/games/four-in-a-row/renderer.js');
assert.ok(four.includes("type: 'column'"), 'Four in a Row column action owner changed');
assert.ok(four.includes("data-four-column"), 'Four in a Row column controls changed');

const chessWrapper = read('app/assets/js/games/chess/renderer-move-effect-v3.js');
assert.ok(chessWrapper.includes('renderBaseChessSurface({ ...args, game:baseGame });'), 'Chess premium wrapper must still delegate to base renderer');
assert.ok(chessWrapper.includes("game_chess_effect"), 'Chess premium effect slot changed');

const goWrapper = read('app/assets/js/games/go/renderer-cosmetics-v1.js');
assert.ok(goWrapper.includes('renderBaseGoSurface(args);'), 'Go cosmetics wrapper must still delegate to base renderer');
assert.ok(goWrapper.includes("game_go_effect"), 'Go effect slot changed');

const fourWrapper = read('app/assets/js/games/four-in-a-row/renderer-cosmetics-v1.js');
assert.ok(fourWrapper.includes('renderBaseFourInARowSurface(args);'), 'Four in a Row cosmetics wrapper must still delegate to base renderer');
for (const slot of ['game_four_in_a_row_theme','game_four_in_a_row_elements','game_four_in_a_row_effect']) {
  assert.ok(fourWrapper.includes(slot), `Four in a Row slot changed: ${slot}`);
}

const gameRules = read('app/assets/js/games/game-rules.js');
for (const token of [
  "./battleship/rules.js?v=55&mvp27_1=localized-v1",
  "./checkers/rules.js?v=59&mvp27_1=localized-v1",
  "./chess/rules.js?v=70&mvp27_1=localized-v1",
  "./domino/rules.js?v=76&mvp27_1=localized-v1",
]) assert.ok(gameRules.includes(token), `Active rules cache chain missing: ${token}`);

const manifest = read('app/runtime/client/version-manifest.php');
for (const token of [
  "game-rules.js?v=79&mvp27_1=active-game-copy-bundle-v1",
  "game-router.js?v=75&mvp27_1=localized-v1",
  "tictactoe/renderer.js?v=59&mvp27_1=localized-v1",
  "chess/renderer-move-effect-v3.js?v=3&mvp27_1=localized-v1",
  "go/renderer-cosmetics-v1.js?v=3&mvp27_1=localized-v1",
  "four-in-a-row/renderer-cosmetics-v1.js?v=13&mvp27_1=localized-v1",
  "c2_6=cell-native-dom-fx",
]) assert.ok(manifest.includes(token), `Active manifest identity missing: ${token}`);

const locale = JSON.parse(read('app/locales/ru.json'));
assert.ok(Number(locale?._meta?.version) >= 49, 'RU locale revision must include active-game bundle');
for (const path of [
  ['games','router','status','finished'],
  ['games','router','aliases','domino'],
  ['rules','battleship','title'],
  ['rules','checkers','title'],
  ['rules','chess','title'],
  ['rules','domino','title'],
  ['games','chess','ui','meta'],
  ['games','go','ui','meta'],
  ['games','tictactoe','ui','meta'],
  ['games','four_in_a_row','ui','meta'],
]) {
  let value = locale;
  for (const key of path) value = value?.[key];
  assert.equal(typeof value, 'string', `Missing RU localization key: ${path.join('.')}`);
}

const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));
assert.ok(Number(baseline.cyrillic_lines_total) <= 1944, 'Successor localization must not increase the accepted game-bundle debt ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 291, 'Successor localization must not increase the accepted game-bundle client debt ceiling');

console.log('MVP-27.1 active game-copy bundle contract: OK — active game copy remains localized with routing/gameplay/cosmetics invariants frozen.');
