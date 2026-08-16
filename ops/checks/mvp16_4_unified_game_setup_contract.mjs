import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const launcher = read('app/assets/js/games/unified-game-launcher.js');
const search = read('app/assets/js/screens/search-screen-v102.js');
const config = read('app/assets/js/config.js');
const goMeta = read('app/assets/js/games/go/meta.js');
const dominoMeta = read('app/assets/js/games/domino/meta.js');
const index = read('app/index.html');
const locale = JSON.parse(read('app/locales/ru.json'));
const manifest = read('app/runtime/client/version-manifest.php');

const games = ['tictactoe','four_in_a_row','battleship','checkers','reversi','chess','go','domino'];
for (const game of games) {
  if (!index.includes(`data-game-card="${game}"`)) throw new Error(`Home card missing: ${game}`);
  if (!launcher.includes(`${game}:Object.freeze`)) throw new Error(`Unified setup config missing: ${game}`);
  if (typeof locale.games?.[game]?.name !== 'string' || !locale.games[game].name) throw new Error(`Localized game name missing: ${game}`);
}

if (!shell.includes("import { initUnifiedGameLauncher } from './games/unified-game-launcher.js?v=1&mvp16=unified-game-setup'")) {
  throw new Error('Shell must import the single unified game launcher.');
}
if (!shell.includes('initUnifiedGameLauncher();')) throw new Error('Shell must initialize the unified game launcher.');

for (const legacyOwner of [
  'initTicTacToeEntry','initFourInARowEntry','initBattleshipEntry','initCheckersEntry',
  'initReversiEntry','initChessEntry','initGoEntry','initDominoEntry',
]) {
  if (shell.includes(legacyOwner)) throw new Error(`Legacy setup owner is still active in shell: ${legacyOwner}`);
}

if (!launcher.includes("import { beginSearch } from '../screens/search-screen-v102.js?v=103'")) {
  throw new Error('Unified setup must hand off to the accepted v102 search owner.');
}
if (/api\.startSearch\s*\(/u.test(launcher) || /setInterval\s*\(/u.test(launcher)) {
  throw new Error('Unified setup must not create a second matchmaking/polling owner.');
}
if (!search.includes('export async function beginSearch(rawContext)')) throw new Error('Accepted v102 search owner is unavailable.');

if (!launcher.includes("state.room = 'match'")) throw new Error('Unified setup must remove room selection and use normal Match only.');
if (!launcher.includes('APP_CONFIG.matchBet')) throw new Error('Unified setup must use server-provided normal-match entry cost.');
if (!config.includes('boardSizes: [3, 5, 9]')) throw new Error('Tic Tac Toe sizes 3/5/9 must remain available.');
for (const label of ['6×5','7×6','8×7']) {
  if (!launcher.includes(`label:'${label}'`)) throw new Error(`Four-in-a-row size missing: ${label}`);
}
if (!launcher.includes('options:() => [6, 8, 10]')) throw new Error('Reversi sizes 6/8/10 must remain available.');
if (!goMeta.includes('GO_BOARD_SIZES') || !/\[9\s*,\s*13\]/u.test(goMeta)) throw new Error('Go sizes 9/13 must remain available.');
if (!dominoMeta.includes('boardSize: 7')) throw new Error('Domino canonical setup size must remain 7.');
for (const fixed of ['value:10, label:\'10×10\'','value:8, label:\'8×8\'']) {
  if (!launcher.includes(fixed)) throw new Error(`Fixed game size missing: ${fixed}`);
}

if (!launcher.includes('data-game-rules=') || !launcher.includes('data-invite-friend=')) {
  throw new Error('Unified setup must preserve rules and invite entry points.');
}
if (!launcher.includes("t('setup.insufficient_title')") || !launcher.includes("t('setup.insufficient_note'")) {
  throw new Error('Simple insufficient-balance modal is missing.');
}
if (/(купить|реклам|buy|advert)/iu.test(JSON.stringify(locale.setup)) || /(купить|реклам|buy|advert)/iu.test(launcher)) {
  throw new Error('MVP-16.4 insufficient-balance flow must not add buy/ad buttons.');
}
if (!launcher.includes("id=\"startUnifiedGameSearchBtn\"")) throw new Error('Unified setup must have one start-search control.');
if (!launcher.includes("if (info) info.textContent = label")) throw new Error('Search screen must receive the exact selected variant label.');

for (const key of [
  'subtitle','variant_label','entry_label','entry_value','confirm','invite','one_on_one',
  'search_label','insufficient_title','insufficient_note','economy_unavailable',
]) {
  if (typeof locale.setup?.[key] !== 'string' || !locale.setup[key]) throw new Error(`Missing setup locale key: ${key}`);
}

if (!manifest.includes("'version' => 'v3-unified-game-setup'")) throw new Error('Client manifest version was not advanced for MVP-16.4.');
if (!manifest.includes("main-v110-handoff-shell.js?v=1151&mvp16=unified-game-setup")) throw new Error('Shell cache target was not advanced for MVP-16.4.');
if (!manifest.includes("unified-game-launcher.js?v=2&mvp16=unified-game-setup")) throw new Error('Unified launcher cache target is missing.');
if (!manifest.includes("'version' => 'keys-v2'")) throw new Error('Localization manifest version was not advanced.');

console.log('MVP16_4_UNIFIED_GAME_SETUP_CONTRACT=PASS');
