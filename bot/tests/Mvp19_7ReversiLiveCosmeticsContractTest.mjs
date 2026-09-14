import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const wrapperPath = path.join(root, 'app/assets/js/games/reversi/renderer-cosmetics-v1.js');
const premiumWrapperPath = path.join(root, 'app/assets/js/games/reversi/renderer-cosmetics-premium-v4.js');
const liveCssPath = path.join(root, 'app/assets/css/games/reversi/live-cosmetics-v1.css');
const premiumCssPath = path.join(root, 'app/assets/css/games/reversi/live-effects-premium-v4.css');
const heightFitPath = path.join(root, 'app/assets/css/games/reversi/telegram-height-fit-v1.css');
const baseRendererPath = path.join(root, 'app/assets/js/games/reversi/renderer.js');
const baseCssPath = path.join(root, 'app/assets/css/games/reversi/game.css');
const storeCssPath = path.join(root, 'app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifestPath = path.join(root, 'app/runtime/client/version-manifest.php');
const launchPath = path.join(root, 'bot/helpers/WebAppLaunchUrl.php');

const wrapper = fs.readFileSync(wrapperPath, 'utf8');
const premiumWrapper = fs.readFileSync(premiumWrapperPath, 'utf8');
const liveCss = fs.readFileSync(liveCssPath, 'utf8');
const premiumCss = fs.readFileSync(premiumCssPath, 'utf8');
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

assert.ok(wrapper.includes('const REAL_FIRST_FLIP_DELAY_MS = 320;'), 'Live cosmetic phase must start on the accepted real first-flip delay');
assert.ok(wrapper.includes('const REAL_FLIP_STEP_MS = 150;'), 'Live cosmetic phase must follow the accepted real per-disc cadence');
assert.ok(wrapper.includes("cell.style.setProperty('--mgw-rv-fx-delay'"), 'Each real flipped disc must receive its authoritative visual delay');
assert.ok(wrapper.includes("cell.style.removeProperty('--mgw-rv-fx-delay')"), 'Per-move visual delay must be cleaned after the authoritative animation window');

assert.ok(wrapper.includes('ensureTelegramHeightFitStyles();'), 'Reversi live wrapper must install its Telegram viewport owner');
assert.ok(wrapper.includes('live-cosmetics-v1.css?v=3&mvp19_7=store-motion-parity-v3'), 'Live Store-motion CSS must remain cache-addressed from the accepted v3 wrapper');
assert.ok(wrapper.includes('telegram-height-fit-v1.css?v=2&mvp19_7=fullwidth-scroll-footer-v2'), 'Full-width Telegram layout CSS must remain cache-addressed from the accepted v3 wrapper');

assert.ok(premiumWrapper.includes("from './renderer-cosmetics-v1.js?v=3&mvp19_7=live-parity-store-motion-v3"), 'Premium wrapper must reuse the accepted live parity v3 owner');
assert.ok(premiumWrapper.includes('ensurePremiumReversiEffectStyles();'), 'Premium wrapper must add only the final Line/Mass visual owner');
assert.ok(premiumWrapper.includes('live-effects-premium-v4.css?v=2&mvp19_7=line-mass-timing-smooth-v5'), 'Premium effect CSS must have a fresh v5 timing cache identity');
assert.ok(!premiumWrapper.includes('setTimeout('), 'Premium wrapper must not create gameplay timers');
assert.ok(!premiumWrapper.includes('setInterval('), 'Premium wrapper must not create gameplay timers');
assert.ok(!premiumWrapper.includes('requestAnimationFrame('), 'Premium wrapper must not create gameplay loops');

for (const forbiddenTimer of ['setTimeout(', 'setInterval(', 'requestAnimationFrame(']) {
  assert.ok(!wrapper.includes(forbiddenTimer), `Cosmetic wrapper must not create a second gameplay timer with ${forbiddenTimer}`);
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

assert.ok(!premiumCss.includes('data-mgw-reversi-fx="placement"'), 'Premium corrective must not turn Mass Flip into a recolored Placement pulse');
assert.ok(premiumCss.includes('.reversi-cell[data-mgw-reversi-fx="line"]'), 'Premium Line owner must target actual line-flipped cells');
assert.ok(premiumCss.includes('mgwRvPremiumLineFlip .48s'), 'Line must use the accepted premium half-turn with tightened timing');
assert.ok(premiumCss.includes('rotateY(92deg) scale(.95)'), 'Line must pass cleanly through the edge-on phase');
assert.ok(premiumCss.includes('rotateY(180deg) scale(1.045)'), 'Line must complete one visual half-turn, not spin 360 degrees');
assert.ok(premiumCss.includes('calc(var(--mgw-rv-fx-delay,320ms) - 170ms)'), 'Line must pre-roll enough to remove the perceived start lag against the real flip');
assert.ok(premiumCss.includes('mgwRvPremiumLineBeam'), 'Line must include the accepted top-down light pass');
assert.ok(premiumCss.includes('mgwRvPremiumLineFloor'), 'Line must include a soft cyan shadow/glow under the disc rather than a Placement-style ring');

assert.ok(premiumCss.includes('.reversi-cell[data-mgw-reversi-fx="mass-flip"]'), 'Premium Mass Flip owner must target actual flipped cells');
assert.ok(premiumCss.includes('mgwRvPremiumMassFlip .62s'), 'Mass Flip must keep its richer premium phase while finishing closer to the real flip cadence');
assert.ok(premiumCss.includes('translateY(-19%) rotateY(180deg) scale(1.14)'), 'Mass Flip must keep the accepted stronger lift/overshoot');
assert.ok(premiumCss.includes('mgwRvPremiumMassPlume'), 'Mass Flip must have a coordinated violet/cyan energy plume');
assert.ok(premiumCss.includes('mgwRvPremiumMassOrbit'), 'Mass Flip must have a segmented orbital energy sweep distinct from Placement');
assert.ok(premiumCss.includes('conic-gradient(from 218deg'), 'Mass Flip premium orbit must use segmented Store-language energy rather than a plain expanding circle');
assert.ok(premiumCss.includes('calc(var(--mgw-rv-fx-delay,320ms) - 210ms)'), 'Mass Flip must pre-roll enough to remove the perceived delay before the real disc transition');
assert.ok(premiumCss.includes('will-change:transform;'), 'Premium live discs must stay on transform-only compositing for smoother Telegram motion');
assert.ok(!premiumCss.includes('will-change:transform,filter'), 'Premium live discs must not force animated filter compositing on every flipped disc');
assert.ok(!premiumCss.includes('rotateY(360deg)'), 'Premium effects must not use the rejected full-spin motion');
assert.ok(!premiumCss.includes('position:fixed'), 'Premium Reversi effects must remain on real board cells/discs');

for (const token of [
  'radial-gradient(circle at 50% 46%,#1b1e22 0 54%,#080a0d 55% 70%,#434950 71% 77%,#111419 78% 100%)',
  'linear-gradient(145deg,#9aa1a7 0 8%,#383e43 26%,#0b0e11 52%,#6b737a 77%,#15191d)',
  'border:2px solid #00eaff',
  'border:2px solid #c45cff',
]) {
  assert.ok(storeCss.includes(token), `Accepted Store must contain visual token: ${token}`);
  assert.ok(liveCss.includes(token), `Live Reversi must reuse Store visual token: ${token}`);
}
assert.ok(storeCss.includes('rotateY(78deg) scale(.96)'), 'Accepted Store Line preview must retain its edge-on motion phase');
assert.ok(storeCss.includes('translateY(-13%) rotateY(180deg) scale(1.10)'), 'Accepted Store Mass Flip preview must retain its lift/overshoot phase');

assert.ok(heightFit.includes('height:calc(var(--tg-viewport-stable-height,100dvh))'), 'Reversi screen must use Telegram stable viewport height like accepted Checkers');
assert.ok(heightFit.includes('max-height:calc(var(--tg-viewport-stable-height,100dvh))'), 'Reversi screen must be bounded to Telegram stable viewport height');
assert.ok(heightFit.includes('.board-wrap{'), 'Reversi must use the board-wrap as its internal scroll owner');
assert.ok(heightFit.includes('overflow-y:auto!important'), 'Reversi board area must scroll internally when vertical space is short');
assert.ok(heightFit.includes('.board.reversi-surface,\n.game-board-screen[data-game-type="reversi"] .reversi-panel,\n.game-board-screen[data-game-type="reversi"] .reversi-board{\n  width:100%!important;'), 'Reversi live board must stay full width like accepted Checkers');
assert.ok(heightFit.includes('#leaveGame{'), 'Reversi viewport owner must explicitly own the leave/menu button placement');
assert.ok(heightFit.includes('position:static!important'), 'Leave/menu button must remain in the accepted visible flex flow');
assert.ok(!heightFit.includes('calc(100dvh - 285px)'), 'Short Telegram viewports must scroll instead of narrowing the Reversi board');
assert.ok(!heightFit.includes('calc(100dvh - 275px)'), 'Very short Telegram viewports must not narrow the Reversi board');
assert.ok(heightFit.includes('var(--tg-content-safe-area-inset-bottom'), 'Reversi viewport owner must respect Telegram bottom safe area');

for (const size of ['6','8','10']) {
  assert.ok(baseRenderer.includes(size), `Accepted base renderer must retain ${size}x${size} support`);
}
assert.ok(baseRenderer.includes('last_flipped_cells'), 'Accepted base renderer must remain the authoritative flip owner');
assert.ok(baseRenderer.includes('animateSingleFlip(container, cell, finalBoard[cell]'), 'Accepted base renderer must keep real per-disc flipping');
assert.ok(baseRenderer.includes('firstFlipDelay = 320'), 'Accepted real move sequencing must remain unchanged');
assert.ok(baseRenderer.includes('flipStep = 150'), 'Accepted real flip cadence must remain unchanged');
assert.ok(baseCss.includes('.reversi-cell.flip-out .reversi-disc'), 'Accepted base flip-out primitive must remain available');
assert.ok(baseCss.includes('.reversi-cell.flip-in .reversi-disc'), 'Accepted base flip-in primitive must remain available');

assert.ok(manifest.includes("'./assets/js/games/reversi/renderer.js?v=66' => './assets/js/games/reversi/renderer-cosmetics-premium-v4.js?v=2&mvp19_7=line-mass-premium-v5&timing=smooth-transform-only-v1&parent=live-parity-v3&footer=fullwidth-scroll-v2'"), 'Active import map must publish the smoother Reversi premium v5 owner');
assert.ok(launch.includes('/app/v110.php?v=1135'), 'Telegram launch must force the fresh smoother Reversi premium asset chain');

console.log('MVP-19.7 Reversi premium v5 contract passed: Line and Mass Flip start earlier, finish tighter, and keep transform-only disc motion on authoritative real flips.');