import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/four-in-a-row/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/four-in-a-row/live-cosmetics-v1.css', 'utf8');
const base = fs.readFileSync('app/assets/js/games/four-in-a-row/renderer.js', 'utf8');
const gameScreen = fs.readFileSync('app/assets/js/screens/game-screen.js', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const response = fs.readFileSync('bot/helpers/response.php', 'utf8');

assert.ok(
  live.includes("from './renderer.js?v=53&base=mvp19-10-live-v1'"),
  'Four live cosmetics must decorate the accepted base renderer instead of replacing gameplay',
);
assert.ok(live.includes('renderBaseFourInARowSurface(args);'), 'Base Four renderer must remain the gameplay/render owner');
assert.ok(!live.includes('api.gameAction') && !live.includes('setTimeout(') && !live.includes('MutationObserver'), 'Live wrapper must remain presentation-only without a second action/timer owner');

for (const slot of ['game_four_in_a_row_theme','game_four_in_a_row_elements','game_four_in_a_row_effect']) {
  assert.ok(live.includes(slot), `Live Four must read canonical slot ${slot}`);
}
for (const id of ['game-four-effect-drop','game-four-effect-four','game-four-effect-victory-wave']) {
  assert.ok(live.includes(id), `Live Four must recognize effect ${id}`);
}

assert.ok(live.includes('game?.last_move'), 'Drop effect must use authoritative last_move');
assert.ok(live.includes('game?.winning_cells'), 'Finish effects must use authoritative winning_cells');
assert.ok(live.includes("String(game?.finish_reason || '') === 'normal_win'"), 'Finish effects must be gated to a real normal win');
assert.ok(live.includes('player?.game_cosmetics?.slots'), 'Opponent-owned effects must use public owner-specific cosmetic projection');
assert.ok(live.includes('state?.profileInventory?.equipped'), 'Viewer cosmetics must retain the proven local inventory fallback');
assert.ok(live.includes('seenMoveByGame'), 'Polling rerenders must not replay the same authoritative move');
assert.ok(live.includes("FIELD_VARIANTS = new Set(['blue', 'dark', 'metal', 'neon'])"), 'All four accepted field identities must be live');
assert.ok(live.includes("DISC_VARIANTS = new Set(['classic', '3d', 'metal', 'neon'])"), 'All four accepted disc identities must be live');
assert.ok(live.includes('[6, 7, 8].includes(value)'), 'Live wrapper must retain 6x5, 7x6 and 8x7 boards');

assert.ok(css.includes('#8750d5') && css.includes('#4c287f'), 'Historical blue field id must render the accepted violet paid field');
assert.ok(css.includes('#ff78b7') && css.includes('#66e8f3'), 'Historical classic disc id must render the accepted arcade paid pair');
assert.ok(css.includes('data-four-theme="dark"') && css.includes('data-four-theme="metal"') && css.includes('data-four-theme="neon"'), 'All accepted paid field styles must be represented');
assert.ok(css.includes('data-four-discs="3d"') && css.includes('data-four-discs="metal"') && css.includes('data-four-discs="neon"'), 'All accepted paid disc styles must be represented');
assert.ok(css.includes('@keyframes mgwFourLiveDrop'), 'Drop must have a real live motion owner');
assert.ok(css.includes('@keyframes mgwFourLiveLineDraw'), 'Four must have a real connecting-line animation');
assert.ok(css.includes('@keyframes mgwFourVictoryWave'), 'Victory Wave must have a distinct broad-wave animation');
assert.ok(css.includes('@media (prefers-reduced-motion:reduce)'), 'Four live effects must be reduced-motion safe');
assert.ok(css.includes('pointer-events:none'), 'Presentation effects must not steal gameplay hit targets');

assert.ok(base.includes("onAction?.({ type:'column', column });"), 'Accepted Four action owner must stay in the base renderer');
assert.ok(base.includes("container.innerHTML ="), 'Accepted base renderer structure remains authoritative');

assert.ok(gameScreen.includes("fourTerminalEffect(game, me)"), 'Result sheet must wait only when a real equipped Four terminal effect needs presentation time');
assert.ok(gameScreen.includes("fourEffect === 'game-four-effect-drop'") && gameScreen.includes('? 820'), 'Winning Drop must finish before result sheet');
assert.ok(gameScreen.includes("fourEffect === 'game-four-effect-four'") && gameScreen.includes('? 1380'), 'Four line must finish before result sheet');
assert.ok(gameScreen.includes("fourEffect === 'game-four-effect-victory-wave'") && gameScreen.includes('? 1620'), 'Victory Wave must finish before result sheet');
assert.ok(gameScreen.includes("String(game?.finish_reason || '') !== 'normal_win'"), 'Technical finishes must not gain cosmetic delay');

assert.ok(
  manifest.includes("'./assets/js/games/four-in-a-row/renderer.js?v=53' => './assets/js/games/four-in-a-row/renderer-cosmetics-v1.js?v=1&mvp19_10=live-game-v1'"),
  'Active import map must route Four through the live cosmetics wrapper',
);
assert.ok(
  manifest.includes("'./assets/js/screens/game-screen.js?v=74' => './assets/js/screens/game-screen.js?v=75&mvp19_10=four-live-result-gate-v1'"),
  'Active graph must cache-bust the Four terminal presentation gate',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1202 && launch.includes('four_live=game-v1'), 'Telegram staging launch must publish Four live graph');

assert.ok(
  response.includes("WHERE c.item_type = \\'game\\' AND c.catalog_status = \\'active\\'") || response.includes("WHERE c.item_type = 'game' AND c.catalog_status = 'active'"),
  'Canonical response boundary must still project equipped game cosmetics',
);
assert.ok(response.includes("$player['game_cosmetics'] = $gameCosmetics;"), 'Both players must receive public game cosmetic slots');

console.log('MVP-19.10 Four in a Row LIVE contract passed.');
