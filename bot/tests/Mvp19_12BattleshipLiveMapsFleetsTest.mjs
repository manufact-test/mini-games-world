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
assert.ok(live.includes('game_battleship_effect'), 'LIVE wrapper must read the canonical Battleship effect slot for accepted effects');
for (const effectId of ['game-battleship-effect-shot','game-battleship-effect-hit','game-battleship-effect-destroy']) {
  assert.ok(live.includes(`'${effectId}'`), `LIVE wrapper must expose accepted/review effect id ${effectId}`);
}

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
assert.ok(live.includes("player?.game_cosmetics?.slots"), 'LIVE cosmetics must support per-player projected cosmetics');
assert.ok(live.includes("renderBaseBattleshipSurface(args);"), 'LIVE cosmetics must wrap the accepted Battleship renderer rather than replace mechanics');
assert.ok(live.indexOf("renderBaseBattleshipSurface(args);") < live.indexOf("container.dataset.mgwBattleshipLiveCosmetics"), 'Presentation data must be applied after the accepted renderer');
assert.ok(!live.includes('enemy_board') && !live.includes('my_board'), 'Presentation wrapper must never inspect hidden Battleship board payloads');
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
  '#9bd7ea','#8b959d','#55f5ff',
]) {
  assert.ok(liveCss.includes(token), `LIVE map/fleet material must keep accepted Store identity token ${token}`);
}
for (const token of ['#0b8ea8','#e7c56e','#ffe6a6','#4f7f96','#4b535b','#59f6ff']) {
  assert.ok(storeCss.includes(token), `Store preview baseline must contain the current scale-appropriate visual token ${token}`);
}

assert.ok(liveCss.includes('.battleship-cell.ship'), 'Fleet materials must target already-visible ship cells');
assert.ok(!liveCss.includes('data-battleship-fleet="classic"] .battleship-cell.unknown'), 'Fleet skin must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="modern"] .battleship-cell.unknown'), 'Modern fleet must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="armored"] .battleship-cell.unknown'), 'Armored fleet must never reveal unknown enemy cells');
assert.ok(!liveCss.includes('data-battleship-fleet="neon"] .battleship-cell.unknown'), 'Neon fleet must never reveal unknown enemy cells');
assert.ok(liveCss.includes('.battleship-cell.hit') && liveCss.includes('.battleship-cell.sunk') && liveCss.includes('.battleship-cell.miss'), 'Shot-result states must remain explicit and readable above map skins');
assert.ok(liveCss.includes('.battleship-cell.pending') && liveCss.includes('.battleship-cell.invalid-pick'), 'Setup interaction states must remain explicit above fleet skins');
assert.ok(liveCss.includes('padding:4px 7px 7px 4px') && liveCss.includes('box-sizing:border-box') && liveCss.includes('border:1px solid rgba(151,190,215,.24)'), 'Every LIVE Battleship board must expose a complete four-sided frame with right/bottom breathing room');
assert.ok(liveCss.includes('#4d3aaa') && liveCss.includes('#2a205f') && liveCss.includes('#12152e') && liveCss.includes('#55f5ff') && liveCss.includes('inset 0 0 0 2px rgba(255,70,223,.34)'), 'Neon fleet must use a filled violet hull with cyan luminous rim and inner magenta tube glow, without a center dot');

assert.ok(
  manifest.includes("'./assets/js/games/battleship/renderer.js?v=56' => './assets/js/games/battleship/renderer-cosmetics-v1.js?v=4&mvp19_12=live-maps-fleets-v4&frame=full-v1&neon_fleet=tube-v4&base=v60-shot-miss-no-impact'"),
  'Accepted manifest baseline must remain on the LIVE maps/fleets wrapper during Shot manual review'
);
assert.ok(launch.includes('battleship_live=maps-fleets-v4') && launch.includes('battleship_shot=live-v2') && launch.includes('battleship_impacts=live-v2') && launch.includes('battleship_frame=full-v1') && launch.includes('battleship_neon_fleet=tube-v4') && launch.includes('battleship_preview_geometry=svg-circles-v6') && launch.includes('battleship_preview_inline_owner=svg-v5') && launch.includes('battleship_fleet_preview=svg-models-v3&battleship_neon_map_ships=white-v1'), 'Telegram launch must publish Battleship LIVE/SVG-preview parity identity');

assert.ok(liveCss.includes('data-mgw-battleship-live-cosmetics="maps-fleets-v4"'), 'LIVE frame/reduced-motion selectors must match the renderer dataset identity');
console.log('Battleship LIVE maps/fleets + effects safety contract passed.');
