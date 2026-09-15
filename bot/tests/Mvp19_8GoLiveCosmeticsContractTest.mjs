import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV7Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v7.css', 'utf8');
const captureV9Css = fs.readFileSync('app/assets/css/games/go/live-capture-overlay-v9.css', 'utf8');
const captureV10Css = fs.readFileSync('app/assets/css/games/go/live-capture-direct-v10.css', 'utf8');
const storeCss = fs.readFileSync('app/assets/css/games/go/store-cosmetics-v1.css', 'utf8');
const rulesAlignmentCss = fs.readFileSync('app/assets/css/games/go/rules-alignment-v1.css', 'utf8');
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
assert.ok(live.includes("point.dataset.mgwGoFx = 'placement'"), 'Accepted placement effect must remain attached to the authoritative last-move point');

/* Effect 2 — direct real-stone presentation on the authoritative capture-out trigger. */
assert.ok(live.includes('applyGroupCapturePresentation({'), 'Group Capture must use the direct presentation marker owner');
assert.ok(live.includes('game?.last_captured_cells'), 'Group Capture must prefer authoritative captured-cell data');
assert.ok(live.includes('removedBoardCells(previousBoard, currentBoard, size)'), 'Group Capture must retain board-diff fallback for skipped polling frames');
assert.ok(live.includes("point.dataset.mgwGoFx = 'group-capture-v10'"), 'Captured intersections must be marked before base capture-out fires');
assert.ok(live.includes("ghost.className = `go-stone ${color} mgw-go-capture-ghost-v10`"), 'Skipped-poll fallback may rebuild only a presentation ghost');
assert.ok(live.includes("point.classList.add('mgw-go-capture-fallback-v10')"), 'Skipped-poll fallback must have one isolated visual trigger');
assert.ok(!live.includes('mountStableCaptureOverlay'), 'Rejected body overlay owner must stay removed');
assert.ok(!live.includes('document.body.appendChild(overlay)'), 'Effect 2 must not depend on a viewport overlay');
assert.ok(!live.includes('activeCaptureOverlayKeys'), 'Effect 2 must not create a second timer/dedup owner');
assert.ok(!live.includes('setTimeout(() =>'), 'Effect 2 presentation owner must not schedule its own capture timing');
assert.ok(!live.includes('mgwGoPaidCaptureSourceV9'), 'Current renderer must not use the rejected v9 source-hiding marker');

assert.ok(captureV10Css.includes('.go-point[data-mgw-go-fx="group-capture-v10"].capture-out .go-stone'), 'V10 must animate the real captured stone on base capture-out');
assert.ok(captureV10Css.includes('animation:mgw-go-v10-capture-implode .27s'), 'V10 real-stone implode must finish before the frozen board rerender');
assert.ok(captureV10Css.includes('.capture-out::before'), 'V10 must render the approved corona on the real captured intersection');
assert.ok(captureV10Css.includes('.capture-out::after'), 'V10 must render the approved particles on the real captured intersection');
assert.ok(!captureV10Css.includes('opacity:0!important;\n  animation:none!important'), 'V10 must never instantly suppress the real captured stone');
assert.ok(!captureV10Css.includes('position:fixed!important'), 'V10 must not reintroduce the rejected body overlay');

/* Stale v9 clients must also regain a visible real-stone capture instead of disappearing. */
assert.ok(captureV9Css.includes('.go-point[data-mgw-go-paid-capture-source-v9="1"].capture-out .go-stone'), 'V9 compatibility layer must target the previously emitted source marker');
assert.ok(captureV9Css.includes('animation:mgw-go-v9-compat-implode .27s'), 'V9 compatibility must animate the real stone');
assert.ok(captureV9Css.includes('.mgw-go-capture-overlay-v9{\n  display:none!important'), 'Rejected v9 body overlay must be neutralized');
assert.ok(!captureV9Css.includes('animation:none!important;\n  opacity:0!important'), 'V9 compatibility must not repeat the instant-disappear rule');

for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(captureV10Css.includes(token), `Direct live capture must reuse approved Store token: ${token}`);
  assert.ok(captureV9Css.includes(token), `Stale-client compatibility must reuse approved Store token: ${token}`);
}

/* Effect 3 — accepted canonical finish-only trigger. */
assert.ok(!live.includes('applyTerritoryQaPreview'), 'Accepted Effect 3 must not trigger on ordinary placement');
assert.ok(!live.includes('nearbyTerritoryPreviewCells'), 'Temporary nearby-placement QA logic must stay removed');
assert.ok(live.includes("String(game?.status || '') === 'finished'"), 'Canonical territory finish must activate only on a finished game');
assert.ok(live.includes('game?.final_score'), 'Canonical territory finish must require authoritative final territory data');
assert.ok(live.includes("point.dataset.mgwGoTerritoryFx = '1'"), 'Canonical final territory cells must receive the accepted stamp presentation');
assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-territory-fx="1"] .go-territory-marker'), 'V7 must retain the accepted final territory square animation');
assert.ok(correctiveV7Css.includes('background:rgba(73,231,255,.34)'), 'Black-side territory must keep the accepted cyan Store language');
assert.ok(correctiveV7Css.includes('background:rgba(202,100,255,.27)'), 'White-side territory must keep the accepted purple Store language');
assert.ok(!correctiveV7Css.includes('MGW'), 'Effect 3 must not reintroduce a central MGW mark');

assert.ok(!live.includes('gameAction('), 'Presentation owner must not create a second gameplay action owner');
assert.ok(!live.includes('api.'), 'Presentation owner must not mutate economy/store/runtime APIs');

for (const theme of ['wood','dark','stone','neon']) {
  assert.ok(css.includes(`[data-go-theme="${theme}"]`), `Live board theme ${theme} must exist`);
}
for (const stones of ['classic','marble','glass','neon']) {
  assert.ok(css.includes(`[data-go-stones="${stones}"]`), `Live stone set ${stones} must exist`);
}

/* Effect 1 and accepted field geometry remain untouched. */
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Accepted placement burst must remain present');
assert.ok(!captureV10Css.includes('data-mgw-go-fx="placement"'), 'V10 must not alter accepted Effect 1');
for (const rule of [
  'height:100dvh!important',
  'max-height:100dvh!important',
  'min-height:0!important',
  'overflow:hidden!important',
  'overflow-y:auto!important',
  'touch-action:pan-y',
]) {
  assert.ok(checkersFit.includes(rule), `Accepted Checkers fit is missing expected rule: ${rule}`);
  assert.ok(exitFitCss.includes(rule), `Go fit must retain accepted Checkers viewport rule: ${rule}`);
}
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .board.go-surface,'), 'Accepted Go full-width board must remain');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .go-pass-button,'), 'Accepted Go full-width pass button must remain');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] #leaveGame{'), 'Accepted Go leave button width must remain');
assert.ok(!exitFitCss.includes('clamp('), 'Rejected width clamp must not return');

for (const token of [
  '.go-rule-point em.forbidden,',
  '.go-rule-point em.ko{',
  'position:absolute!important',
  'left:50%!important',
  'top:50%!important',
  'transform:translate(-50%,-50%)!important',
]) {
  assert.ok(rulesAlignmentCss.includes(token), `Missing Go rules marker-centering token: ${token}`);
}

assert.ok(v110.includes("$goEffectsV7Target = './assets/css/games/go/live-effects-corrective-v7.css?v=1&mvp19_8=effect2-single-pass-territory-final-v7';"), 'v110 must keep v7 for accepted final territory presentation');
assert.ok(v110.includes("$goCaptureOverlayV9Target = './assets/css/games/go/live-capture-overlay-v9.css?v=1&mvp19_8=stable-capture-overlay-v9';"), 'v110 still publishes v9 so stale clients receive the compatibility correction');
assert.ok(v110.includes("'go_rules_marker_alignment' => $goRulesAlignmentTarget"), 'v110 must preserve the pending rules alignment corrective');
assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1150, 'Telegram launch must publish the direct-capture corrective');

assert.ok(base.includes('const captureStart = 330;'), 'Frozen base capture moment must remain 330ms');
assert.ok(base.includes("container.querySelector(`[data-go-cell=\"${cell}\"]`)?.classList.add('capture-out');"), 'Frozen base renderer must remain the authoritative capture timing owner');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live v10 contract passed: Effect 2 runs on the real captured stone from authoritative capture-out; stale v9 no longer hides stones; gameplay frozen.');
