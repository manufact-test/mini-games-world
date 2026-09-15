import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV7Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v7.css', 'utf8');
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

/* Effect 2 — one trigger only. */
assert.ok(live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'Group capture must attach to the actual captured point');
assert.ok(live.includes('game?.last_captured_cells'), 'Live group capture must still prefer authoritative captured-cell data');
assert.ok(live.includes('removedBoardCells(previousBoard, currentBoard, size)'), 'Live capture must keep the board-diff fallback when polling skips the capture frame');
assert.ok(live.includes("ghost.className = `go-stone ${color} mgw-go-capture-ghost`"), 'Skipped capture frames must recreate a visual-only ghost stone for the outgoing animation');
assert.ok(live.includes("point.classList.add('mgw-go-capture-fallback')"), 'Fallback capture must be explicitly isolated from gameplay');
assert.ok(!live.includes('scheduledCaptureFx'), 'Paid capture must not schedule a second delayed animation owner');
assert.ok(!live.includes('mgw-go-capture-paid-active'), 'Paid capture must not use the rejected second-stage paid-active trigger');
assert.ok(!live.includes('capturedCount'), 'Group capture must not depend on the optional last_move.captured counter');

assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-fx="group-capture"]::before,'), 'V7 must explicitly suppress the legacy immediate capture corona');
assert.ok(correctiveV7Css.includes('animation:none!important'), 'Legacy immediate capture animation must be disabled before the real capture moment');
assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-fx="group-capture"].capture-out::before,'), 'Paid capture corona must start from the real base capture-out class');
assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-fx="group-capture"].capture-out .go-stone,'), 'Captured stone must run exactly one paid implode on capture-out');
assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-fx="group-capture"].mgw-go-capture-fallback::before'), 'Skipped-poll fallback must have one isolated trigger');
for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(correctiveV7Css.includes(token), `Live capture must reuse approved Store token: ${token}`);
}

/* Effect 3 — visually accepted; QA trigger removed and canonical finish restored. */
assert.ok(!live.includes('applyTerritoryQaPreview'), 'Accepted Effect 3 must no longer trigger on ordinary placement');
assert.ok(!live.includes('nearbyTerritoryPreviewCells'), 'Accepted Effect 3 must remove temporary nearby-placement QA logic');
assert.ok(!live.includes('dataMgwGoTerritoryQaMarker'), 'Accepted Effect 3 must not create temporary QA markers');
assert.ok(!live.includes("board.dataset.mgwGoFx = 'territory-finish'"), 'Territory finish must not reactivate the rejected board-wide centre bloom owner');
assert.ok(live.includes("String(game?.status || '') === 'finished'"), 'Canonical territory finish must activate only on a finished game');
assert.ok(live.includes('game?.final_score'), 'Canonical territory finish must require authoritative final territory data');
assert.ok(live.includes("point.dataset.mgwGoTerritoryFx = '1'"), 'Canonical final territory cells must receive the accepted stamp presentation');
assert.ok(correctiveV7Css.includes('.go-point[data-mgw-go-territory-fx="1"] .go-territory-marker'), 'V7 must retain the accepted final territory square animation');
assert.ok(correctiveV7Css.includes('background:rgba(73,231,255,.34)'), 'Black-side territory must keep the accepted cyan Store language');
assert.ok(correctiveV7Css.includes('background:rgba(202,100,255,.27)'), 'White-side territory must keep the accepted purple Store language');
assert.ok(correctiveV7Css.includes('@keyframes mgw-go-v7-store-territory-marker'), 'Accepted territory stamp motion must remain available on final score');
assert.ok(correctiveV7Css.includes('.go-board[data-mgw-go-fx="territory-finish"]::before,'), 'V7 must suppress any stale old centre bloom/seal owner');
assert.ok(!correctiveV7Css.includes('MGW'), 'Effect 3 must never reintroduce a central MGW mark');

assert.ok(!live.includes('gameAction('), 'Presentation owner must not create a second gameplay action owner');
assert.ok(!live.includes('api.'), 'Presentation owner must not mutate economy/store/runtime APIs');

for (const theme of ['wood','dark','stone','neon']) {
  assert.ok(css.includes(`[data-go-theme="${theme}"]`), `Live board theme ${theme} must exist`);
}
for (const stones of ['classic','marble','glass','neon']) {
  assert.ok(css.includes(`[data-go-stones="${stones}"]`), `Live stone set ${stones} must exist`);
}

/* Effect 1 was manually accepted and v7 must not touch it. */
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Accepted placement burst must remain present');
assert.ok(!correctiveV7Css.includes('data-mgw-go-fx="placement"'), 'V7 must not alter accepted Effect 1');

/* The accepted Go field geometry must remain untouched. */
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

/* Rules marker alignment remains pending manual acceptance. */
for (const token of [
  '.go-rule-point em.forbidden,',
  '.go-rule-point em.ko{',
  'position:absolute!important',
  'left:50%!important',
  'top:50%!important',
  'transform:translate(-50%,-50%)!important',
  'align-items:center!important',
  'justify-content:center!important',
]) {
  assert.ok(rulesAlignmentCss.includes(token), `Missing Go rules marker-centering token: ${token}`);
}

assert.ok(v110.includes("$goEffectsV7Target = './assets/css/games/go/live-effects-corrective-v7.css?v=1&mvp19_8=effect2-single-pass-territory-final-v7';"), 'v110 must publish the Go v7 stylesheet');
assert.ok(v110.includes("$goRulesAlignmentTarget = './assets/css/games/go/rules-alignment-v1.css?v=1&mvp19_8=rule-marker-center-v1';"), 'v110 must keep the Go rules marker alignment stylesheet');
assert.ok(v110.includes('data-mgw-go-live-effects-v7="mvp19-8-effect2-single-pass-territory-final-v7"'), 'v110 must expose the unique Go v7 marker');
assert.ok(v110.includes('data-mgw-go-rules-alignment="mvp19-8-rule-marker-center-v1"'), 'v110 must expose the Go rules alignment marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=effect2-single-pass-territory-final-v7';"), 'v110 must force a fresh Go renderer identity');
assert.ok(v110.includes("'go_effect2_single_pass_territory_final' => $goEffectsV7Target"), 'v110 rendered-target guard must require Go v7');
assert.ok(v110.includes("'go_rules_marker_alignment' => $goRulesAlignmentTarget"), 'v110 rendered-target guard must require the rules alignment stylesheet');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds a cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1147, 'Telegram launch must publish the Go v7 cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live v7 contract passed: field and Effect 1 untouched; Effect 2 single-pass on capture-out; Effect 3 canonical finish-only; rules alignment preserved.');
