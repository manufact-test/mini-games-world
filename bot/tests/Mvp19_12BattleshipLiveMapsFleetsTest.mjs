import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const livePath = path.join(root, 'app/assets/js/games/battleship/renderer-cosmetics-v1.js');
const liveCssPath = path.join(root, 'app/assets/css/games/battleship/live-cosmetics-v1.css');
const baseRendererPath = path.join(root, 'app/assets/js/games/battleship/renderer.js');
const baseCssPath = path.join(root, 'app/assets/css/games/battleship/game.css');
const v102Path = path.join(root, 'app/assets/js/games/battleship/renderer-v102.js');
const storeCssPath = path.join(root, 'app/assets/css/games/battleship/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const live = fs.readFileSync(livePath, 'utf8');
const liveCss = fs.readFileSync(liveCssPath, 'utf8');
const baseRenderer = fs.readFileSync(baseRendererPath, 'utf8');
const baseCss = fs.readFileSync(baseCssPath, 'utf8');
const v102 = fs.readFileSync(v102Path, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const launch = fs.readFileSync(launchPath, 'utf8');

for (const slot of ['game_battleship_theme','game_battleship_elements']) {
  assert.ok(live.includes(slot), `LIVE Battleship wrapper must read ${slot}`);
}
assert.ok(!live.includes('game_battleship_effect'), 'Maps/fleets phase must not activate provisional Store effect concepts');

for (const variant of ['sea','dark-military','storm','neon']) {
  assert.ok(live.includes(`'${variant}'`), `LIVE map variants must include ${variant}`);
  assert.ok(liveCss.includes(`data-battleship-map="${variant}"`), `LIVE CSS must style map ${variant}`);
}
for (const variant of ['classic','modern','armored','neon']) {
  assert.ok(live.includes(`'${variant}'`), `LIVE fleet variants must include ${variant}`);
  assert.ok(liveCss.includes(`data-battleship-fleet="${variant}"`), `LIVE CSS must style fleet ${variant}`);
}

assert.ok(live.includes("state?.profileInventory?.equipped"), 'LIVE cosmetics must use canonical local equipped inventory');
assert.ok(live.includes("me?.game_cosmetics?.slots"), 'LIVE cosmetics must fall back to projected viewer slots');
assert.ok(live.includes("viewer?.game_cosmetics?.slots"), 'LIVE cosmetics must support game-player projection');
assert.ok(live.includes("renderBaseBattleshipSurface(args);"), 'LIVE cosmetics must wrap the accepted Battleship renderer rather than replace mechanics');
assert.ok(live.indexOf("renderBaseBattleshipSurface(args);") < live.indexOf("container.dataset.mgwBattleshipLiveCosmetics"), 'Presentation data must be applied after the accepted renderer');
assert.ok(!live.includes('enemy_board') && !live.includes('my_board') && !live.includes('last_result'), 'Presentation wrapper must not inspect or rewrite Battleship board/shot state');
assert.ok(!live.includes('onAction'), 'Presentation wrapper must not intercept Battleship gameplay actions');

assert.ok(v102.includes("from './renderer.js?v=56'"), 'V102 owner must still enter through the canonical Battleship renderer import');
assert.ok(v102.includes("createV102RandomizeAction"), 'Existing setup randomize action owner must remain intact');
assert.ok(baseRenderer.includes("onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) })"), 'Accepted fire action semantics must remain in base renderer');
assert.ok(baseRenderer.includes("const board = showingEnemy ? (game?.enemy_board || []) : (game?.my_board || [])"), 'Accepted own/enemy board projection must remain in base renderer');

assert.ok(baseCss.includes('rgba(51,94,162,.52)') && baseCss.includes('rgba(166,183,209,.96)'), 'Test must audit real free Battleship map/fleet colors');
assert.ok(liveCss.includes('#0b8ea8') && liveCss.includes('rgba(41,211,205,.64)'), 'Paid sea map must stay materially distinct from free blue');
assert.ok(liveCss.includes('#f3e2b8') && liveCss.includes('#e8bf68'), 'Paid classic fleet must stay ivory/brass instead of free gray');

for (const token of [
  '#1c281f','#536c80','#081326',
  '#9bd7ea','#8b959d','#41f6ff',
]) {
  assert.ok(liveCss.includes(token), `LIVE map/fleet material must keep accepted Store identity token ${token}`);
}
for (const token of ['#0b8ea8','#f3e2b8','#e8bf68','#9bd7ea','#8b959d','#41f6ff']) {
  assert.ok(storeCss.includes(token), `Store baseline must contain shared visual token ${token}`);
}

assert.ok(liveCss.includes('.battleship-cell.ship'), 'Fleet materials must target already-visible ship cells');
assert.ok(!liveCss.includes('data-battleship-fleet="classic"] .battleship-cell.unknown'), 'Fleet skin must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="modern"] .battleship-cell.unknown'), 'Modern fleet must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="armored"] .battleship-cell.unknown'), 'Armored fleet must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="neon"] .battleship-cell.unknown'), 'Neon fleet must never reveal unknown enemy cells');
assert.ok(liveCss.includes('.battleship-cell.hit') && liveCss.includes('.battleship-cell.sunk') && liveCss.includes('.battleship-cell.miss'), 'Shot-result states must remain explicit and readable above map skins');
assert.ok(liveCss.includes('.battleship-cell.pending') && liveCss.includes('.battleship-cell.invalid-pick'), 'Setup interaction states must remain explicit above fleet skins');

assert.ok(
  manifest.includes("'./assets/js/games/battleship/renderer.js?v=56' => './assets/js/games/battleship/renderer-cosmetics-v1.js?v=1&mvp19_12=live-maps-fleets-v1&base=v60-shot-miss-no-impact'"),
  'Active manifest must route canonical Battleship renderer import through LIVE maps/fleets wrapper'
);
assert.ok(launch.includes('battleship_live=maps-fleets-v1'), 'Telegram launch must publish Battleship LIVE maps/fleets identity');

console.log('Battleship LIVE maps/fleets contract passed.');
