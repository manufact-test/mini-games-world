import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV3Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v3.css', 'utf8');
const exitFitCss = fs.readFileSync('app/assets/css/games/go/live-exit-fit-v1.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const v110 = fs.readFileSync('app/v110.php', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const base = fs.readFileSync('app/assets/js/games/go/renderer.js', 'utf8');
const checkersFit = fs.readFileSync('app/assets/css/games/checkers/telegram-height-fit-v1.css', 'utf8');

assert.ok(live.includes("from './renderer.js?v=70&base=mvp11-accepted'"), 'Live Go cosmetics must decorate the accepted renderer instead of replacing gameplay');
assert.ok(live.includes('renderBaseGoSurface(args);'), 'Base Go renderer must remain the rendering/gameplay owner');
assert.ok(live.includes('game_go_theme'), 'Live Go must read the canonical board equip slot');
assert.ok(live.includes('game_go_elements'), 'Live Go must read the canonical stones equip slot');
assert.ok(live.includes('game_go_effect'), 'Live Go must read the canonical effect equip slot');
assert.ok(live.includes("'game-go-effect-placement'"), 'Placement effect must be wired live');
assert.ok(live.includes("'game-go-effect-group-capture'"), 'Group-capture effect must be wired live');
assert.ok(live.includes("'game-go-effect-territory-finish'"), 'Territory-finish effect must be wired live');
assert.ok(live.includes("point.dataset.mgwGoFx = 'placement'"), 'Placement must attach to the authoritative last-move point');
assert.ok(live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'Group capture must attach to authoritative captured cells');
assert.ok(live.includes('capturedCount <= 0 || captured.length <= 0'), 'Group-capture cosmetic must stay silent on ordinary placement');
assert.ok(live.includes('applyTerritoryQaPreview'), 'Territory finish must retain the temporary placement QA trigger until manual approval');
assert.ok(live.includes("board.dataset.mgwGoTerritoryQa = 'placement'"), 'Temporary territory QA trigger must remain explicitly isolated for later removal');
assert.ok(live.includes('game?.last_captured_cells'), 'Live group capture must use authoritative captured-cell data');
assert.ok(live.includes("String(game?.status || '') === 'finished'"), 'Canonical territory finish must still activate on a finished game');
assert.ok(live.includes('game?.final_score'), 'Canonical territory finish must still require authoritative final territory data');
assert.ok(!live.includes('gameAction('), 'Presentation owner must not create a second gameplay action owner');
assert.ok(!live.includes('api.'), 'Presentation owner must not mutate economy/store/runtime APIs');

for (const theme of ['wood','dark','stone','neon']) {
  assert.ok(css.includes(`[data-go-theme="${theme}"]`), `Live board theme ${theme} must exist`);
}
for (const stones of ['classic','marble','glass','neon']) {
  assert.ok(css.includes(`[data-go-stones="${stones}"]`), `Live stone set ${stones} must exist`);
}
for (const fx of [
  'mgw-go-live-stonefall',
  'mgw-go-live-placement-seal',
  'mgw-go-live-capture-implode',
  'mgw-go-live-capture-corona',
  'mgw-go-live-capture-particles',
  'mgw-go-live-territory-bloom',
  'mgw-go-live-territory-stamp',
]) {
  assert.ok(css.includes(`@keyframes ${fx}`), `Missing Go live animation ${fx}`);
}
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Placement corrective v2 must retain its outward burst base');
assert.ok(correctiveV3Css.includes('32px 0 #67efff'), 'Placement corrective v3 must keep the live particle burst clearly visible');
assert.ok(correctiveV3Css.includes('.go-board[data-mgw-go-territory-qa="placement"] .go-stone::before'), 'Territory QA squares must be anchored to actual occupied Go intersections');
assert.ok(correctiveV3Css.includes('content:none!important'), 'Territory corrective must remove the giant central MG/MGW seal');
assert.ok(correctiveV3Css.includes('mgw-go-live-territory-lock-v3'), 'Temporary territory squares must reconstruct in place instead of travelling across the board');
assert.ok(correctiveV3Css.includes('mgw-go-live-territory-lock-marker-v3'), 'Canonical final territory markers must also resolve in place');
assert.ok(!correctiveV3Css.includes('translate(-50%,-50%) scale(1.78)'), 'Territory corrective must not reuse a travelling marker transform');

/* Go must use the same viewport ownership strategy already accepted for Checkers. */
for (const rule of [
  'height:100dvh!important',
  'max-height:100dvh!important',
  'min-height:0!important',
  'overflow:hidden!important',
  'overflow-y:auto!important',
  'touch-action:pan-y',
]) {
  assert.ok(checkersFit.includes(rule), `Accepted Checkers fit is missing expected rule: ${rule}`);
  assert.ok(exitFitCss.includes(rule), `Go fit must mirror accepted Checkers viewport rule: ${rule}`);
}
assert.ok(exitFitCss.includes('width:min(100%,clamp(300px,calc(100dvh - 245px),360px))!important'), 'Short Go viewport must use the accepted Checkers-style board cap rather than crush the board');
assert.ok(!exitFitCss.includes('calc(100dvh - 380px)'), 'Rejected tiny-board formula must not return');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] #leaveGame'), 'Go fit must preserve the existing leave control');
assert.ok(v110.includes("$goExitFitTarget = './assets/css/games/go/live-exit-fit-v1.css?v=2&mvp19_8=checkers-scroll-parity-v2';"), 'v110 must publish the fresh Checkers-parity Go viewport stylesheet');
assert.ok(v110.includes("$goEffectsV3Target = './assets/css/games/go/live-effects-corrective-v3.css?v=1&mvp19_8=territory-point-lock-no-logo-v3';"), 'v110 must publish the fresh territory point-lock corrective stylesheet');
assert.ok(v110.includes('data-mgw-go-live-effects-v3="mvp19-8-territory-point-lock-no-logo-v3"'), 'v110 must expose the unique Go territory v3 stylesheet marker');
assert.ok(v110.includes("'go_checkers_scroll_parity' => $goExitFitTarget"), 'v110 rendered-target guard must require the Go Checkers-parity viewport stylesheet');
assert.ok(v110.includes("'go_territory_point_lock_no_logo' => $goEffectsV3Target"), 'v110 rendered-target guard must require the no-logo territory corrective stylesheet');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Active v110 import graph must keep the accepted Go renderer identity',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1143, 'Telegram launch must publish the fresh Go layout/territory corrective cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live corrective contract passed: Checkers-parity scrolling, normal board size, point-locked territory squares, no central MGW logo.');
