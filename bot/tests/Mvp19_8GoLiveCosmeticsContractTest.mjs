import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV7Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v7.css', 'utf8');
const captureV8Css = fs.readFileSync('app/assets/css/games/go/live-capture-overlay-v8.css', 'utf8');
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

/* Effect 2 — independent overlay, not a disappearing live stone. */
assert.ok(live.includes('applyGroupCaptureOverlay({'), 'Group Capture must use the independent presentation overlay owner');
assert.ok(live.includes('game?.last_captured_cells'), 'Group Capture must prefer authoritative captured-cell data');
assert.ok(live.includes('removedBoardCells(previousBoard, currentBoard, size)'), 'Group Capture must retain board-diff fallback for skipped polling frames');
assert.ok(live.includes('const activeCaptureOverlayKeys = new Set();'), 'Capture overlays must be deduplicated per move');
assert.ok(live.includes("point.dataset.mgwGoPaidCaptureSource = '1'"), 'The real captured stone must be marked only so base capture-out can hide it under the overlay');
assert.ok(live.includes("overlay.className = 'mgw-go-capture-overlay-v8'"), 'A dedicated visual overlay must be created on the captured intersection');
assert.ok(live.includes("overlay.dataset.mgwGoCaptureOverlay = '1'"), 'Capture overlay must be presentation-only and explicitly identifiable');
assert.ok(live.includes("overlay.innerHTML = `<span class=\"go-stone ${color} mgw-go-capture-overlay-stone\""), 'Overlay must contain a visual copy using the real captured stone color');
assert.ok(live.includes('animatedByBase ? `${330 + Math.min(index, 10) * 38}ms`'), 'Overlay must align to the frozen base renderer capture timestamp');
assert.ok(!live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'V8 must not re-arm the legacy immediate group-capture CSS owner');
assert.ok(!live.includes('mgw-go-capture-paid-active'), 'Rejected second-stage paid-active trigger must not return');
assert.ok(!live.includes('scheduledCaptureFx'), 'Rejected delayed second owner must not return');
assert.ok(!live.includes('capturedCount'), 'Capture presentation must not depend on optional last_move.captured');

assert.ok(captureV8Css.includes('.go-point[data-mgw-go-paid-capture-source="1"].capture-out .go-stone'), 'Real source stone must be hidden at the authoritative capture-out moment');
assert.ok(captureV8Css.includes('.mgw-go-capture-overlay-v8::before'), 'Independent overlay must render the capture corona');
assert.ok(captureV8Css.includes('.mgw-go-capture-overlay-v8::after'), 'Independent overlay must render capture particles');
assert.ok(captureV8Css.includes('.mgw-go-capture-overlay-v8 .mgw-go-capture-overlay-stone'), 'Independent overlay stone must visibly implode');
for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(captureV8Css.includes(token), `Independent live overlay must reuse approved Store token: ${token}`);
}
assert.ok(captureV8Css.includes('animation:mgw-go-v8-capture-implode .28s'), 'Overlay implode must finish before the frozen base renderer replaces the animated board');
assert.ok(captureV8Css.includes('animation:mgw-go-v8-capture-corona .28s'), 'Overlay corona must finish inside the base capture window');
assert.ok(captureV8Css.includes('animation:mgw-go-v8-capture-particles .28s'), 'Overlay particles must finish inside the base capture window');

/* Effect 3 — accepted and restored to canonical finish-only trigger. */
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
assert.ok(!captureV8Css.includes('data-mgw-go-fx="placement"'), 'V8 must not alter accepted Effect 1');
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

/* Rules marker alignment remains pending manual acceptance and unchanged here. */
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
assert.ok(v110.includes("$goCaptureOverlayV8Target = './assets/css/games/go/live-capture-overlay-v8.css?v=1&mvp19_8=capture-overlay-v8';"), 'v110 must publish the independent capture overlay v8');
assert.ok(v110.includes('data-mgw-go-live-capture-overlay-v8="mvp19-8-capture-overlay-v8"'), 'v110 must expose the unique capture overlay v8 marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=effect2-overlay-v8';"), 'v110 must force a fresh Go renderer identity for v8');
assert.ok(v110.includes("'go_capture_overlay_v8' => $goCaptureOverlayV8Target"), 'v110 rendered-target guard must require capture overlay v8');
assert.ok(v110.includes("'go_rules_marker_alignment' => $goRulesAlignmentTarget"), 'v110 must preserve the pending rules alignment corrective');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds the cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1148, 'Telegram launch must publish the fresh Go v8 capture overlay cache identity');

assert.ok(base.includes('const captureStart = 330;'), 'Frozen base capture moment must remain 330ms');
assert.ok(base.includes("container.querySelector(`[data-go-cell=\"${cell}\"]`)?.classList.add('capture-out');"), 'Frozen base renderer must remain the authoritative capture timing owner');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live v8 contract passed: Effect 2 uses one independent captured-stone overlay; Effect 1/3/field unchanged; gameplay owner frozen.');
