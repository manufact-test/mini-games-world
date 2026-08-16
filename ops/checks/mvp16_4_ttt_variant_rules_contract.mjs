import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const backend = read('bot/services/GameService.php');
const owner = read('app/assets/js/games/game-rules.js');
const launcher = read('app/assets/js/games/unified-game-launcher.js');
const rules = read('app/assets/js/games/tictactoe/rules.js');
const rulesCss = read('app/assets/css/games/tictactoe/rules.css');
const mainCss = read('app/assets/css/main.css');
const locale = JSON.parse(read('app/locales/ru.json'));
const localeManifest = JSON.parse(read('app/locales/manifest.json'));
const clientManifest = read('app/runtime/client/version-manifest.php');
const runtimeManifest = read('bot/helpers/staging-e2e-runtime-files.txt').split(/\r?\n/u);

if (!backend.includes('$need = $size === 3 ? 3 : ($size === 5 ? 4 : 5);')) {
  throw new Error('TTT rules must be derived from the accepted backend win lengths 3/4/5.');
}
if (!backend.includes('$dirs = [[1,0],[0,1],[1,1],[1,-1]];')) {
  throw new Error('TTT rules must preserve horizontal, vertical and both diagonal win directions.');
}
if (!backend.includes("elseif (!str_contains($board, '-'))")) {
  throw new Error('TTT rules must preserve full-board draw semantics.');
}

for (const [size, need] of [[3,3],[5,4],[9,5]]) {
  if (!rules.includes(`${size}:Object.freeze({ size:${size}, need:${need}`)) {
    throw new Error(`Missing TTT rules variant ${size}x${size} with backend need=${need}.`);
  }
  if (!rulesCss.includes(`.rule-tic-grid.size-${size}`)) {
    throw new Error(`Missing distinct TTT rules diagram layout for ${size}x${size}.`);
  }
}

if (!launcher.includes('data-game-rules-variant="${Number(activeSize)}"')) {
  throw new Error('Setup Rules control must carry the currently selected variant.');
}
if (!launcher.includes('rulesButton.dataset.gameRulesVariant = String(activeSize);')) {
  throw new Error('Switching setup size must update the Rules variant immediately.');
}
if (!owner.includes("const game = current ? state.activeGame : null;")) {
  throw new Error('In-match Rules must resolve from the authoritative active game.');
}
if (!owner.includes('game?.board_size') || !owner.includes('game?.boardSize')) {
  throw new Error('In-match TTT Rules must derive the real board size from the active match payload.');
}
if (!owner.includes('button.dataset.gameRulesVariant')) {
  throw new Error('Pre-match Rules must consume the selected setup variant.');
}
if (!owner.includes('renderer({ ...context, gameType, variant })')) {
  throw new Error('Setup and in-match rules must share one renderer call path.');
}
if (!rules.includes('export function ticTacToeRules({ variant } = {})')) {
  throw new Error('TTT renderer must accept the shared variant context.');
}

for (const key of [
  'title','subtitle','turn_title','turn_text','win_title','win_text','win_tip',
  'draw_title','draw_text','diagram_label','diagram_caption',
]) {
  if (typeof locale.rules?.tictactoe?.[key] !== 'string' || !locale.rules.tictactoe[key]) {
    throw new Error(`Missing localized TTT variant-rules key: ${key}`);
  }
}
if (localeManifest.rules?.games?.tictactoe?.version !== 2) {
  throw new Error('TTT variant-aware rules metadata must be version 2.');
}
if (/[А-Яа-яЁё]/u.test(rules)) {
  throw new Error('TTT rule product copy must live in locale files, not the renderer.');
}

if (!mainCss.includes("./games/tictactoe/rules.css?v=54&mvp16=variant-rules")) {
  throw new Error('TTT rule diagram CSS must use a fresh nested cache target.');
}
if (!/game-rules\.js\?v=\d+&mvp16=(?:variant-rules|rules-return-to-setup|all-variant-rules)/u.test(clientManifest)) {
  throw new Error('Shared rule owner must stay on a versioned MVP-16 cache target.');
}
if (!clientManifest.includes("unified-game-launcher.js?v=4&mvp16=setup-subtitle-width")) {
  throw new Error('Unified setup launcher must use fresh bytes after variant binding.');
}
if (!/main\.css\?v=\d+&sk=3&icons=c1efd5af&render=\d+&mvp16=(?:setup-subtitle-width|variant-rules-nav-balance|optical-bottom-nav|painted-bottom-nav)/u.test(clientManifest)) {
  throw new Error('Outer CSS target must remain fresh after rules and navigation changes.');
}
if (!clientManifest.includes("'version' => 'v2-route-scoped-polling'")) {
  throw new Error('Client manifest schema version must remain stable.');
}
if (!clientManifest.includes("'version' => 'keys-v1'")) {
  throw new Error('Localization infrastructure contract must remain keys-v1.');
}

for (const path of [
  'app/assets/js/games/game-rules.js',
  'app/assets/js/games/tictactoe/rules.js',
  'app/assets/css/games/tictactoe/rules.css',
  'app/assets/js/games/unified-game-launcher.js',
  'app/assets/css/main.css',
  'app/locales/manifest.json',
  'app/locales/ru.json',
  'app/runtime/client/version-manifest.php',
]) {
  if (!runtimeManifest.includes(path)) {
    throw new Error(`Variant-rules runtime file is missing from exact Hostinger fingerprint: ${path}`);
  }
}

console.log('MVP16_4_TTT_VARIANT_RULES_CONTRACT=PASS');