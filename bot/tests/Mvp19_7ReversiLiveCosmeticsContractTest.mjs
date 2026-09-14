import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const wrapperPath = path.join(root, 'app/assets/js/games/reversi/renderer-cosmetics-v1.js');
const liveCssPath = path.join(root, 'app/assets/css/games/reversi/live-cosmetics-v1.css');
const heightFitPath = path.join(root, 'app/assets/css/games/reversi/telegram-height-fit-v1.css');
const baseRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');
const baseCssPath = path.join(root, 'app/assets/css/games/reversi/game.css');
const storeCssPath = path.join(root, 'app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const wrapper = fs.readFileSync(wrapperPath, 'utf8');
const liveCss = fs.readFileSync(liveCssPath, 'utf8');
const heightFit = fs.readFileSync(heightFitPath, 'utf8');
const baseRenderer = fs.readFileSync(baseRendererPath, 'utf8');
const baseCss = fs.readFileSync(baseCssPath, 'utf8');
const storeCss = fs.readFileSync(storeCssPath, 'utf8');
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
assert.ok(wrapper.includes('normalizeSide(game?.last_move?.side)'), 'Mover resolution must normalize authoritative B/W and black/white side values');
assert.ok(wrapper.includes("if (side === 'black' || side === 'b') return 'black';"), 'Mover side normalization must accept black/B');
assert.ok(wrapper.includes("if (side === 'white' || side === 'w') return 'white';"), 'Mover side normalization must accept white/W');

assert.ok(wrapper.includes('const presentationOwner = viewer || blackPlayer || whitePlayer || players[0] || null;'), 'Live shared presentation must prefer the current viewer');
assert.ok(wrapper.includes('const pieces = piecesVariant(gameId, presentationOwner);'), 'One selected Reversi pieces SKU must resolve as a complete black+white set');
assert.ok(wrapper.includes('container.dataset.reversiBlackPieces = pieces;'), 'Viewer complete piece set must style black discs');
assert.ok(wrapper.includes('container.dataset.reversiWhitePieces = pieces;'), 'Viewer complete piece set must style white discs');
assert.ok(wrapper.includes('const theme = fieldVariant(gameId, presentationOwner);'), 'Shared Reversi field must follow viewer-owned presentation');
assert.ok(!wrapper.includes('reversiOpponentTheme'), 'Live Reversi must not invent an opponent-theme overlay on the shared field');

assert.ok(wrapper.includes('ensureTelegramHeightFitStyles();'), 'Reversi live wrapper must install its Telegram footer/menu height-fit owner');
assert.ok(wrapper.includes('telegram-height-fit-v1.css?v=1&mvp19_7=footer-menu-height-fit-v1'), 'Height-fit asset must be cache-addressed from the active live wrapper');

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
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="placement"].placed-fresh .reversi-disc::before'), 'Placement Store ring must live on the actual newly placed disc');
assert.ok(liveCss.includes('inset:-38%'), 'Placement live ring must use Store exact ring geometry');
assert.ok(liveCss.includes('border:2px solid rgba(94,238,255,.95)'), 'Placement live ring must use Store exact cyan visual language');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="line"].flip-out .reversi-disc::before'), 'Line halo must attach to the actual real flip-out disc');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="line"].flip-in .reversi-disc::before'), 'Line halo must stay on the actual real flip-in disc');
assert.ok(liveCss.includes('inset:-22%'), 'Line effect must retain Store halo geometry');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="mass-flip"].flip-out .reversi-disc'), 'Mass Flip must animate the actual flipping disc, not a detached clone');
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="mass-flip"].flip-in .reversi-disc'), 'Mass Flip must finish on the same actual disc node');
assert.ok(liveCss.includes('inset:-32%'), 'Mass Flip must retain Store halo geometry');
assert.ok(!liveCss.includes('position:fixed'), 'Reversi live cosmetics must not create detached viewport overlays');

for (const token of [
  'radial-gradient(circle at 50% 46%,#1b1e22 0 54%,#080a0d 55% 70%,#434950 71% 77%,#111419 78% 100%)',
  'linear-gradient(145deg,#9aa1a7 0 8%,#383e43 26%,#0b0e11 52%,#6b737a 77%,#15191d)',
  'border:2px solid #00eaff',
  'border:2px solid #c45cff',
]) {
  assert.ok(storeCss.includes(token), `Accepted Store must contain visual token: ${token}`);
  assert.ok(liveCss.includes(token), `Live Reversi must reuse Store visual token: ${token}`);
}

assert.ok(heightFit.includes('height:calc(var(--tg-viewport-stable-height,100dvh))'), 'Reversi screen must use Telegram stable viewport height like accepted Checkers');
assert.ok(heightFit.includes('max-height:calc(var(--tg-viewport-stable-height,100dvh))'), 'Reversi screen must be bounded to Telegram stable viewport height');
assert.ok(heightFit.includes('overflow:hidden'), 'Outer Reversi game screen must not push bottom navigation below the viewport');
assert.ok(heightFit.includes('overflow-y:auto'), 'Reversi content must scroll internally when board plus chrome exceed the Telegram viewport');
assert.ok(heightFit.includes('padding-bottom:max(56px'), 'Reversi safe content must reserve bottom-menu space');
assert.ok(heightFit.includes('var(--tg-content-safe-area-inset-bottom'), 'Reversi height owner must respect Telegram bottom safe area');
assert.ok(heightFit.includes('calc(100dvh - 285px)'), 'Short Telegram viewports must shrink the board instead of hiding the menu');

for (const size of ['6','8','10']) {
  assert.ok(baseRenderer.includes(size), `Accepted base renderer must retain ${size}x${size} support`);
}
assert.ok(baseRenderer.includes('last_flipped_cells'), 'Accepted base renderer must remain the authoritative flip owner');
assert.ok(baseRenderer.includes('animateSingleFlip(container, cell, finalBoard[cell]'), 'Accepted base renderer must keep real per-disc flipping');
assert.ok(baseRenderer.includes('firstFlipDelay = 320'), 'Accepted real move sequencing must remain unchanged');
assert.ok(baseRenderer.includes('flipStep = 150'), 'Accepted real flip cadence must remain unchanged');
assert.ok(baseCss.includes('.reversi-cell.flip-out .reversi-disc'), 'Accepted base flip-out primitive must remain available');
assert.ok(baseCss.includes('.reversi-cell.flip-in .reversi-disc'), 'Accepted base flip-in primitive must remain available');

assert.ok(manifest.includes("'./assets/js/games/reversi/renderer.js?v=66' => './assets/js/games/reversi/renderer-cosmetics-v1.js?v=2&mvp19_7=live-corrective-store-exact-v2&pieces=viewer-complete-set-v1&effects=real-disc-store-language-v2&footer=telegram-height-fit-v1'"), 'Active import map must publish the Reversi corrective v2 wrapper');
assert.ok(launch.includes('/app/v110.php?v=1132'), 'Telegram launch must force the fresh Reversi corrective asset chain');

console.log('MVP-19.7 Reversi live corrective contract passed: complete Store piece set, real-disc effects and Telegram footer height fit are active.');
