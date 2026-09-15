import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV7Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v7.css', 'utf8');
const captureV9Css = fs.readFileSync('app/assets/css/games/go/live-capture-overlay-v9.css', 'utf8');
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

/* Effect 2 — stable viewport overlay survives gameBoard.innerHTML replacement. */
assert.ok(live.includes('applyGroupCaptureOverlay({'), 'Group Capture must use the isolated presentation overlay owner');
assert.ok(live.includes('game?.last_captured_cells'), 'Group Capture must prefer authoritative captured-cell data');
assert.ok(live.includes('removedBoardCells(previousBoard, currentBoard, size)'), 'Group Capture must retain board-diff fallback for skipped polling frames');
assert.ok(live.includes('const activeCaptureOverlayKeys = new Set();'), 'Capture overlays must be deduplicated per move');
assert.ok(live.includes("point.dataset.mgwGoPaidCaptureSourceV9 = '1'"), 'Real source stone must be marked only for visual hiding at base capture-out');
assert.ok(live.includes("overlay.className = 'mgw-go-capture-overlay-v9 go-surface'"), 'Effect 2 must mount a stable viewport overlay rather than a child of the replaceable Go board');
assert.ok(live.includes("document.body.appendChild(overlay)"), 'Capture overlay must live outside gameBoard.innerHTML so base rerenders cannot delete it');
assert.ok(live.includes("overlay.dataset.goStones = stoneVariant"), 'Stable overlay must preserve the equipped Go stone skin');
assert.ok(live.includes("overlay.innerHTML = `<span class=\"go-stone ${color} mgw-go-capture-overlay-stone-v9\""), 'Overlay must contain a visual copy using the actual captured stone color');
assert.ok(live.includes('const pointRect = point.getBoundingClientRect();'), 'Overlay coordinates must come from the real captured intersection');
assert.ok(live.includes('const stoneRect = sourceStone instanceof HTMLElement ? sourceStone.getBoundingClientRect() : null;'), 'Overlay should use the real stone screen rectangle when available');
assert.ok(live.includes('315 + Math.min(snapshot.index, 10) * 38'), 'Stable overlay must begin immediately around the frozen 330ms capture window');
assert.ok(!live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'V9 must not re-arm the legacy group-capture CSS owner');
assert.ok(!live.includes('mgw-go-capture-paid-active'), 'Rejected paid-active second-stage trigger must not return');
assert.ok(!live.includes('scheduledCaptureFx'), 'Rejected duplicate delayed owner must not return');
assert.ok(!live.includes('capturedCount'), 'Capture presentation must not depend on optional last_move.captured');

assert.ok(captureV9Css.includes('.go-point[data-mgw-go-paid-capture-source-v9="1"].capture-out .go-stone'), 'Real source stone must be hidden at authoritative capture-out');
assert.ok(captureV9Css.includes('.mgw-go-capture-overlay-v9{'), 'Stable capture overlay CSS must exist');
assert.ok(captureV9Css.includes('position:fixed!important'), 'Effect 2 overlay must be viewport-stable across gameBoard rerenders');
assert.ok(captureV9Css.includes('z-index:2147483000!important'), 'Stable capture overlay must remain visibly above the game renderer');
assert.ok(captureV9Css.includes('.mgw-go-capture-overlay-v9::before'), 'Stable overlay must render the approved corona');
assert.ok(captureV9Css.includes('.mgw-go-capture-overlay-v9::after'), 'Stable overlay must render approved particles');
assert.ok(captureV9Css.includes('.mgw-go-capture-overlay-v9 .mgw-go-capture-overlay-stone-v9'), 'Stable overlay stone must visibly implode');
for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(captureV9Css.includes(token), `Stable live overlay must reuse approved Store token: ${token}`);
}
assert.ok(captureV9Css.includes('@media (prefers-reduced-motion:reduce)'), 'V9 must explicitly handle reduced-motion clients');
assert.ok(captureV9Css.includes('mgw-go-v9-reduced-stone'), 'Reduced-motion mode must still show a visible capture fade instead of hiding Effect 2 entirely');
assert.ok(!captureV9Css.includes('animation:none!important;\n    opacity:0!important;'), 'V9 must not repeat the v8 rule that made the paid effect completely invisible');

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
assert.ok(!captureV9Css.includes('data-mgw-go-fx="placement"'), 'V9 must not alter accepted Effect 1');
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
assert.ok(v110.includes("$goCaptureOverlayV9Target = './assets/css/games/go/live-capture-overlay-v9.css?v=1&mvp19_8=stable-capture-overlay-v9';"), 'v110 must publish stable capture overlay v9');
assert.ok(v110.includes('data-mgw-go-live-capture-overlay-v9="mvp19-8-stable-capture-overlay-v9"'), 'v110 must expose the unique stable capture overlay v9 marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=effect2-stable-overlay-v9';"), 'v110 must force a fresh Go renderer identity for v9');
assert.ok(v110.includes("'go_capture_overlay_v9' => $goCaptureOverlayV9Target"), 'v110 rendered-target guard must require stable capture overlay v9');
assert.ok(v110.includes("'go_rules_marker_alignment' => $goRulesAlignmentTarget"), 'v110 must preserve the pending rules alignment corrective');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds the cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1149, 'Telegram launch must publish stable Go Effect 2 overlay v9');

assert.ok(base.includes('const captureStart = 330;'), 'Frozen base capture moment must remain 330ms');
assert.ok(base.includes("container.querySelector(`[data-go-cell=\"${cell}\"]`)?.classList.add('capture-out');"), 'Frozen base renderer must remain the authoritative capture timing owner');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live v9 contract passed: Effect 2 survives base DOM replacement in a stable viewport overlay; Effect 1/3/field unchanged; gameplay frozen.');
