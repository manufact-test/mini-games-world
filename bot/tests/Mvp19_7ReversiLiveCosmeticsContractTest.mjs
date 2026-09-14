import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const wrapper = read('app/assets/js/games/reversi/renderer-cosmetics-v1.js');
const premiumWrapper = read('app/assets/js/games/reversi/renderer-cosmetics-premium-v4.js');
const liveCss = read('app/assets/css/games/reversi/live-cosmetics-v1.css');
const premiumCss = read('app/assets/css/games/reversi/live-effects-premium-v4.css');
const heightFit = read('app/assets/css/games/reversi/telegram-height-fit-v1.css');
const baseRenderer = read('app/assets/js/games/reversi/renderer.js');
const baseCss = read('app/assets/css/games/reversi/game.css');
const storeCss = read('app/assets/css/games/reversi/store-cosmetics-v1.css');
const manifest = read('app/runtime/client/version-manifest.php');
const launch = read('bot/helpers/WebAppLaunchUrl.php');

assert.ok(wrapper.includes("from './renderer.js?v=66&base=mvp14r-accepted'"), 'Accepted Reversi renderer must remain the gameplay owner');
assert.ok(wrapper.includes('renderBaseReversiSurface(args);'), 'Cosmetic wrapper must delegate to the accepted renderer first');
assert.ok(wrapper.indexOf('renderBaseReversiSurface(args);') < wrapper.indexOf('decorateLiveReversi({ game, me, container });'), 'Cosmetics must decorate only after authoritative render');

for (const slot of ['game_reversi_theme','game_reversi_elements','game_reversi_effect']) {
  assert.ok(wrapper.includes(slot), `Live wrapper must read ${slot}`);
}
for (const id of ['game-reversi-effect-placement','game-reversi-effect-line','game-reversi-effect-mass-flip']) {
  assert.ok(wrapper.includes(id), `Live wrapper must support ${id}`);
}
assert.ok(wrapper.includes('game?.last_move?.cell'), 'Effects must use the real placed cell');
assert.ok(wrapper.includes('game?.last_flipped_cells'), 'Effects must use real flipped cells');
assert.ok(wrapper.includes('const REAL_FIRST_FLIP_DELAY_MS = 320;'), 'Paid visuals must preserve accepted first-flip cadence');
assert.ok(wrapper.includes('const REAL_FLIP_STEP_MS = 150;'), 'Paid visuals must preserve accepted per-disc cadence');
assert.ok(wrapper.includes('container.dataset.reversiBlackPieces = pieces;'), 'Selected pieces SKU must style black discs');
assert.ok(wrapper.includes('container.dataset.reversiWhitePieces = pieces;'), 'Selected pieces SKU must style white discs');
assert.ok(!wrapper.includes('innerHTML ='), 'Cosmetic wrapper must not rebuild the authoritative board');
for (const forbidden of ['setTimeout(', 'setInterval(', 'requestAnimationFrame(']) {
  assert.ok(!wrapper.includes(forbidden), `Cosmetic wrapper must not create a second gameplay timer: ${forbidden}`);
}

assert.ok(premiumWrapper.includes('ensurePremiumReversiEffectStyles();'), 'Premium wrapper must load the Line/Mass visual layer');
assert.ok(premiumWrapper.includes('live-effects-premium-v4.css?v=4&mvp19_7=single-transform-owner-v8'), 'Premium CSS must use the v8 single-transform cache identity');
assert.ok(!premiumWrapper.includes('ensureTailSettleStyles'), 'Rejected tail-settle layer must no longer be active');
assert.ok(!premiumWrapper.includes('live-effects-tail-settle-v6.css'), 'Rejected tail-settle CSS must no longer be loaded');
for (const forbidden of ['setTimeout(', 'setInterval(', 'requestAnimationFrame(']) {
  assert.ok(!premiumWrapper.includes(forbidden), `Premium wrapper must remain timer-free: ${forbidden}`);
}

assert.ok(!premiumCss.includes('mgwRvPremiumLineFlip'), 'Line premium layer must not own the real disc transform');
assert.ok(!premiumCss.includes('mgwRvPremiumMassFlip'), 'Mass Flip premium layer must not own the real disc transform');
assert.ok(!premiumCss.includes('backface-visibility:visible!important'), 'Premium layer must not override base disc flip geometry');
assert.ok(!premiumCss.includes('transform-style:preserve-3d!important'), 'Premium layer must not install a second 3D transform owner');
assert.ok(premiumCss.includes('mgwRvPremiumLineSurface'), 'Line must retain its cyan surface light');
assert.ok(premiumCss.includes('mgwRvPremiumLineBeam'), 'Line must retain its travelling light beam');
assert.ok(premiumCss.includes('mgwRvPremiumLineFloor'), 'Line must retain its cyan floor glow');
assert.ok(premiumCss.includes('mgwRvPremiumMassSurface'), 'Mass Flip must retain its violet/cyan surface light');
assert.ok(premiumCss.includes('mgwRvPremiumMassPlume'), 'Mass Flip must retain its energy plume');
assert.ok(premiumCss.includes('mgwRvPremiumMassOrbit'), 'Mass Flip must retain its segmented orbit');
assert.ok(premiumCss.includes('conic-gradient(from 218deg'), 'Mass Flip must remain visually distinct from Placement');
assert.ok(!premiumCss.includes('data-mgw-reversi-fx="placement"'), 'Premium Line/Mass layer must not recolor Placement');

assert.ok(baseRenderer.includes('animateSingleFlip(container, cell, finalBoard[cell]'), 'Base renderer must remain the sole real-disc flip owner');
assert.ok(baseRenderer.includes("cellElement.classList.add('flip-out')"), 'Base renderer must keep flip-out phase');
assert.ok(baseRenderer.includes("cellElement.classList.add('flip-in')"), 'Base renderer must keep flip-in phase');
assert.ok(baseRenderer.includes('}, 105);'), 'Base real-disc midpoint timing must remain 105ms');
assert.ok(baseRenderer.includes("schedule(() => cellElement.classList.remove('flip-in'), 255);"), 'Base real-disc completion timing must remain 255ms');
assert.ok(baseCss.includes('.reversi-cell.flip-out .reversi-disc{animation:reversi-flip-out .11s ease-in forwards}'), 'Base CSS must own flip-out transform');
assert.ok(baseCss.includes('.reversi-cell.flip-in .reversi-disc{animation:reversi-flip-in .15s ease-out forwards}'), 'Base CSS must own flip-in transform');

for (const variant of ['green','dark','marble','neon']) {
  assert.ok(liveCss.includes(`data-reversi-theme=\"${variant}\"`), `Live board must support ${variant}`);
}
for (const variant of ['classic','marble','metal','neon']) {
  assert.ok(liveCss.includes(`data-reversi-black-pieces=\"${variant}\"`), `Live black pieces must support ${variant}`);
  assert.ok(liveCss.includes(`data-reversi-white-pieces=\"${variant}\"`), `Live white pieces must support ${variant}`);
}
assert.ok(liveCss.includes('.reversi-cell[data-mgw-reversi-fx="placement"].placed-fresh .reversi-disc::before'), 'Placement must remain on the real placed disc');

for (const token of [
  'radial-gradient(circle at 50% 46%,#1b1e22 0 54%,#080a0d 55% 70%,#434950 71% 77%,#111419 78% 100%)',
  'linear-gradient(145deg,#9aa1a7 0 8%,#383e43 26%,#0b0e11 52%,#6b737a 77%,#15191d)',
  'border:2px solid #00eaff',
  'border:2px solid #c45cff',
]) {
  assert.ok(storeCss.includes(token), `Store must retain accepted token: ${token}`);
  assert.ok(liveCss.includes(token), `Live must reuse Store token: ${token}`);
}

assert.ok(heightFit.includes('height:calc(var(--tg-viewport-stable-height,100dvh))'), 'Reversi must stay bounded to Telegram stable height');
assert.ok(heightFit.includes('overflow-y:auto!important'), 'Short Telegram viewport must scroll internally');
assert.ok(heightFit.includes('width:100%!important'), 'Reversi board must stay full width');
assert.ok(heightFit.includes('#leaveGame{'), 'Leave/menu button must remain explicitly owned');

assert.ok(manifest.includes("'./assets/js/games/reversi/renderer.js?v=66' => './assets/js/games/reversi/renderer-cosmetics-premium-v4.js?v=4&mvp19_7=line-mass-premium-v8&motion=single-transform-owner-v1&parent=live-parity-v3&footer=fullwidth-scroll-v2'"), 'Active import map must publish the single-transform Reversi owner');
assert.ok(launch.includes('/app/v110.php?v=1137'), 'Telegram launch must force the v8 Reversi asset chain');

console.log('MVP-19.7 Reversi v8 contract passed: base 255ms flip is the sole real-disc transform owner; paid Line/Mass layers are decorative only.');
