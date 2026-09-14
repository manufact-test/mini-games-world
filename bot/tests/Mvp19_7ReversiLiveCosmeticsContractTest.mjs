import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const wrapperPath = path.join(root, 'app/assets/js/games/reversi/renderer-cosmetics-v1.js');
const liveCssPath = path.join(root, 'app/assets/css/games/reversi/live-cosmetics-v1.css');
const baseRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');
const baseCssPath = path.join(root, 'app/assets/css/games/reversi/game.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const wrapper = fs.readFileSync(wrapperPath, 'utf8');
const liveCss = fs.readFileSync(liveCssPath, 'utf8');
const baseRenderer = fs.readFileSync(baseRendererPath, 'utf8');
const baseCss = fs.readFileSync(baseCssPath, 'utf8');
const manifest = fs.readFileSync(manifestPath, 'utf8');
const launch = fs.readFileSync(launchPath, 'utf8');

assert.ok(wrapper.includes("from './renderer.js?v=66&base=mvp14r-accepted'"), 'Live cosmetic wrapper must preserve the accepted Reversi gameplay renderer as base owner');
assert.ok(wrapper.includes('renderBaseReversiSurface(args);'), 'Wrapper must delegate every render to the accepted base renderer first');
assert.ok(wrapper.indexOf('renderBaseReversiSurface(args);') < wrapper.indexOf('decorateLiveReversi({ game, me, container });'), 'Cosmetics must decorate the already-rendered authoritative surface');

for (const slot of ['game_reversi_theme','game_reversi_elements','game_reversi_effect']) {
  assert.ok(wrapper.includes(slot), `Live wrapper must read authoritative ${slot}`);
}
for (const id of [
  'game-reversi-effect-placement',
  'game-reversi-effect-line',
  'game-reversi-effect-mass-flip',
]) {
  assert.ok(wrapper.includes(id), `Live wrapper must support ${id}`);
}

assert.ok(wrapper.includes('game?.last_move?.cell'), 'Paid effects must attach to the authoritative real placed cell');
assert.ok(wrapper.includes('game?.last_flipped_cells'), 'Flip effects must use authoritative actually flipped cells');
assert.ok(wrapper.includes("container.classList.contains('is-animating')"), 'Paid effects must only decorate the accepted real move animation window');
assert.ok(wrapper.includes('moverPlayer(game, players)'), 'Paid effect ownership must follow the player who actually made the move');
assert.ok(wrapper.includes("String(game?.last_move?.player_id || '')"), 'Mover resolution must prefer authoritative player_id');
assert.ok(wrapper.includes("String(game?.last_move?.side || '')"), 'Mover resolution must retain authoritative side fallback');
assert.ok(wrapper.includes("players.find(player => String(player?.side || '') === 'black')"), 'Black piece cosmetics must belong to the actual black player');
assert.ok(wrapper.includes("players.find(player => String(player?.side || '') === 'white')"), 'White piece cosmetics must belong to the actual white player');
assert.ok(wrapper.includes('fieldVariant(gameId, viewer)'), 'Shared Reversi field must follow the accepted viewer-owned board-theme convention');
assert.ok(!wrapper.includes('reversiOpponentTheme'), 'Live Reversi must not invent an opponent-theme overlay on the shared field');

for (const forbiddenTimer of ['setTimeout(', 'setInterval(', 'requestAnimationFrame(']) {
  assert.ok(!wrapper.includes(forbiddenTimer), `Cosmetic wrapper must not create fake effect timing with ${forbiddenTimer}`);
}
assert.ok(!wrapper.includes('innerHTML ='), 'Cosmetic wrapper must not rebuild the authoritative Reversi board');
assert.ok(!wrapper.includes('onAction?.'), 'Cosmetic wrapper must not own gameplay actions');

for (const variant of ['green','dark','marble','neon']) {
  assert.ok(liveCss.includes(`data-reversi-theme=\"${variant}\"`), `Live field CSS must include accepted ${variant} field`);
}
for (const variant of ['classic','marble','metal','neon']) {
  assert.ok(liveCss.includes(`data-reversi-black-pieces=\"${variant}\"`), `Live black discs must include accepted ${variant} pieces`);
  assert.ok(liveCss.includes(`data-reversi-white-pieces=\"${variant}\"`), `Live white discs must include accepted ${variant} pieces`);
}

assert.ok(liveCss.includes('radial-gradient(circle at 18% 22%'), 'Live marble field must retain the accepted subtle mineral texture instead of stripe veins');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="placement"].placed-fresh::after'), 'Placement effect must attach to the real placed cell');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="line"].flip-out::after'), 'Line effect must attach to the real flip-out cell');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="line"].flip-in::after'), 'Line effect must remain attached through the real flip-in cell');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="mass-flip"].flip-out .reversi-disc'), 'Mass Flip must animate the actual flipping disc, not a detached clone');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="mass-flip"].flip-in .reversi-disc'), 'Mass Flip must finish on the same actual disc node');
assert.ok(!liveCss.includes('position:fixed'), 'Reversi live cosmetics must not create detached viewport overlays');

for (const size of ['6','8','10']) {
  assert.ok(baseRenderer.includes(size), `Accepted base renderer must retain ${size}x${size} support`);
}
assert.ok(baseRenderer.includes('last_flipped_cells'), 'Accepted base renderer must remain the authoritative flip owner');
assert.ok(baseRenderer.includes('animateSingleFlip(container, cell, finalBoard[cell]'), 'Accepted base renderer must keep real per-disc flipping');
assert.ok(baseRenderer.includes('firstFlipDelay = 320'), 'Accepted real move sequencing must remain unchanged');
assert.ok(baseRenderer.includes('flipStep = 150'), 'Accepted real flip cadence must remain unchanged');
assert.ok(baseCss.includes('.reversi-cell.flip-out .reversi-disc'), 'Accepted base flip-out primitive must remain available');
assert.ok(baseCss.includes('.reversi-cell.flip-in .reversi-disc'), 'Accepted base flip-in primitive must remain available');

assert.ok(manifest.includes("'./assets/js/games/reversi/renderer.js?v=66' => './assets/js/games/reversi/renderer-cosmetics-v1.js?v=1&mvp19_7=live-cosmetics-real-events-v1'"), 'Active import map must route the exact Reversi v66 specifier through the live cosmetic wrapper');
assert.ok(launch.includes('/app/v110.php?v=1131'), 'Telegram launch must force the fresh Reversi live asset chain');

console.log('MVP-19.7 Reversi live cosmetics contract passed: accepted renderer preserved, Store-parity cosmetics bound to real move events.');
