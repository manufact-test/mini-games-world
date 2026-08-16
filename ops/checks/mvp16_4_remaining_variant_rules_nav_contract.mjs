import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const owner = read('app/assets/js/games/game-rules.js');
const launcher = read('app/assets/js/games/unified-game-launcher.js');
const fourRules = read('app/assets/js/games/four-in-a-row/rules.js');
const reversiRules = read('app/assets/js/games/reversi/rules.js');
const goRules = read('app/assets/js/games/go/rules.js');
const fourBackend = read('bot/services/FourInARowService.php');
const reversiBackend = read('bot/games/reversi/ReversiService.php');
const goBackend = read('bot/games/go/GoService.php');
const layout = read('app/assets/css/base/layout.css');
const mainCss = read('app/assets/css/main.css');
const locale = JSON.parse(read('app/locales/ru.json'));
const localeManifest = JSON.parse(read('app/locales/manifest.json'));
const clientManifest = read('app/runtime/client/version-manifest.php');
const runtimeManifest = read('bot/helpers/staging-e2e-runtime-files.txt')
  .split(/\r?\n/u)
  .map(line => line.trim())
  .filter(line => line && !line.startsWith('#'));

// The shared owner must resolve the real active-match size for every game,
// while pre-match setup continues to pass its current selected variant.
for (const token of ['game?.board_size','game?.boardSize','game?.game_variant_size','game?.board_columns']) {
  if (!owner.includes(token)) throw new Error(`Shared rules owner missing active-game variant source: ${token}`);
}
if (!owner.includes('button.dataset.gameRulesVariant')) throw new Error('Setup rules must consume the selected variant.');
if (!owner.includes('renderer({ ...context, gameType, variant })')) throw new Error('Pre-match and in-match rules must share one renderer path.');
if (!owner.includes('returnToPrevious:context.returnToPrevious === true')) throw new Error('Rules must preserve accepted nested return behavior.');

// Four in a Row: 6x5 / 7x6 / 8x7, always connect four.
if (!fourBackend.includes('private const CONNECT_LENGTH = 4;')) throw new Error('Four-in-a-Row connect length must remain 4.');
for (const token of ['6 => [6, 5]','8 => [8, 7]','default => [7, 6]']) {
  if (!fourBackend.includes(token)) throw new Error(`Four-in-a-Row backend dimension mapping missing: ${token}`);
}
for (const token of [
  '6:Object.freeze({ columns:6, rows:5, connect:4 })',
  '7:Object.freeze({ columns:7, rows:6, connect:4 })',
  '8:Object.freeze({ columns:8, rows:7, connect:4 })',
  'data-rule-variant="${rules.columns}"',
  'grid-template-columns:repeat(${rules.columns}',
]) {
  if (!fourRules.includes(token)) throw new Error(`Four-in-a-Row variant rules missing: ${token}`);
}

// Reversi: same mechanics, but selected board and diagrams must be 6/8/10.
if (!reversiBackend.includes('private const ALLOWED_SIZES = [6, 8, 10];')) throw new Error('Reversi backend sizes must remain 6/8/10.');
if (!reversiRules.includes('const REVERSI_RULE_SIZES = Object.freeze([6, 8, 10]);')) throw new Error('Reversi rules must expose all accepted sizes.');
if (!reversiRules.includes('ruleBoard(\'start\', size)') || !reversiRules.includes('style="--reversi-rule-size:${size}"')) {
  throw new Error('Reversi rule diagrams must follow the selected size.');
}

// Go: 9/13 with the same accepted 6.5 komi; diagrams must use selected size.
if (!goBackend.includes('private const ALLOWED_SIZES = [9, 13];')) throw new Error('Go backend sizes must remain 9/13.');
if (!goBackend.includes('private const KOMI = 6.5;')) throw new Error('Go komi must remain 6.5.');
if (!goRules.includes('const GO_RULE_SIZES = Object.freeze([9, 13]);') || !goRules.includes('const GO_KOMI = 6.5;')) {
  throw new Error('Go rules must mirror accepted 9/13 sizes and 6.5 komi.');
}
if (!goRules.includes('ruleBoard(\'start\', size)') || !goRules.includes('gridSvg(size)') || !goRules.includes('starMarkup(size)')) {
  throw new Error('Go rule diagrams must be rendered for the selected board size.');
}

// Do not invent fake variants for games that only expose one setup option today.
for (const token of [
  "battleship:Object.freeze({ defaultSize:10, options:() => [{ value:10, label:'10×10' }] })",
  "checkers:Object.freeze({ defaultSize:8, options:() => [{ value:8, label:'8×8' }] })",
  "chess:Object.freeze({ defaultSize:8, options:() => [{ value:8, label:'8×8' }] })",
  "options:() => [{ value:Number(DOMINO_META.boardSize || 7), label:t('setup.one_on_one') }]",
]) {
  if (!launcher.includes(token)) throw new Error(`Single-variant setup contract changed unexpectedly: ${token}`);
}

// New copy for edited rules belongs to the shared locale catalog.
for (const game of ['four_in_a_row','reversi','go']) {
  if (localeManifest.rules?.games?.[game]?.version !== 2) throw new Error(`${game} variant-aware rules metadata must be version 2.`);
  if (!locale.rules?.[game]?.title || !locale.rules?.[game]?.size_text || !locale.rules?.[game]?.diagram_label) {
    throw new Error(`Missing localized variant-aware rule copy for ${game}.`);
  }
}
for (const source of [fourRules, reversiRules, goRules]) {
  if (/[А-Яа-яЁё]/u.test(source)) throw new Error('Edited rule renderers must not introduce hardcoded RU product copy.');
}

// Final bottom navigation polish: +3px icon/label air while reducing total bar height.
if (!layout.includes('padding:2px 5px max(2px,env(safe-area-inset-bottom))')) throw new Error('Bottom nav outer panel must use compact symmetric padding.');
if (!layout.includes('min-height:48px;\n  padding:3px 3px;')) throw new Error('Bottom navigation item must reclaim excess vertical space.');
if (!layout.includes('justify-content:flex-start;\n  gap:0;\n  font-size:10px;\n  line-height:1;')) {
  throw new Error('Bottom navigation content must remain compact and top-owned.');
}
if (!layout.includes('.app-bottom-nav-icon{width:34px;height:29px')
    || !layout.includes('place-items:start center')
    || !layout.includes('flex:0 0 29px;overflow:visible')
    || !layout.includes('.app-bottom-nav-icon .shield-king-metal-icon{width:32px;height:32px')) {
  throw new Error('Bottom navigation must preserve accepted 32px artwork and add exactly 3px to the painted-bound icon/label spacing.');
}
if (!layout.includes('padding-bottom:calc(62px + env(safe-area-inset-bottom))')) {
  throw new Error('Primary screens must reclaim the height removed from the compact bottom navigation.');
}
if (!mainCss.includes("./base/layout.css?v=131&sk=1&mvp16=final-bottom-nav")) {
  throw new Error('Final bottom navigation must have a fresh nested CSS target.');
}

if (!clientManifest.includes("game-rules.js?v=78&mvp16=all-variant-rules")) {
  throw new Error('Shared rules owner must keep accepted Telegram cache bytes.');
}
if (!clientManifest.includes("main.css?v=165&sk=3&icons=c1efd5af&render=34&mvp16=final-bottom-nav")) {
  throw new Error('Outer CSS must use fresh Telegram cache bytes for final nav polish.');
}
if (!clientManifest.includes("'version' => 'v2-route-scoped-polling'")) throw new Error('Client manifest schema must remain stable.');
if (!clientManifest.includes("'version' => 'keys-v1'")) throw new Error('Localization infrastructure must remain keys-v1.');

const rulePaths = [
  'app/assets/js/games/tictactoe/rules.js',
  'app/assets/js/games/four-in-a-row/rules.js',
  'app/assets/js/games/battleship/rules.js',
  'app/assets/js/games/checkers/rules.js',
  'app/assets/js/games/reversi/rules.js',
  'app/assets/js/games/chess/rules.js',
  'app/assets/js/games/go/rules.js',
  'app/assets/js/games/domino/rules.js',
  'app/assets/css/games/tictactoe/rules.css',
  'app/assets/css/games/four-in-a-row/rules.css',
  'app/assets/css/games/battleship/rules.css',
  'app/assets/css/games/checkers/rules.css',
  'app/assets/css/games/reversi/rules.css',
  'app/assets/css/games/chess/rules.css',
  'app/assets/css/games/go/rules.css',
  'app/assets/css/games/domino/rules.css',
];
for (const path of rulePaths) {
  if (!runtimeManifest.includes(path)) throw new Error(`Rules surface missing from exact Hostinger fingerprint: ${path}`);
}

console.log('MVP16_4_REMAINING_VARIANT_RULES_NAV_CONTRACT=PASS');
