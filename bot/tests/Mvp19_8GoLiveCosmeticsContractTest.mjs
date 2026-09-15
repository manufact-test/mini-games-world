import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV6Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v6.css', 'utf8');
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
assert.ok(live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'Group capture must attach to the actual captured point');
assert.ok(live.includes('game?.last_captured_cells'), 'Live group capture must still prefer authoritative captured-cell data');
assert.ok(live.includes('removedBoardCells(previousBoard, currentBoard, size)'), 'Live capture must have a board-diff fallback when polling skips the capture frame');
assert.ok(live.includes("ghost.className = `go-stone ${color} mgw-go-capture-ghost`"), 'Skipped capture frames must recreate a visual-only ghost stone for the outgoing animation');
assert.ok(live.includes("point.classList.add('mgw-go-capture-fallback')"), 'Fallback capture must be explicitly isolated from gameplay');
assert.ok(live.includes("livePoint.classList.add('mgw-go-capture-paid-active')"), 'Paid capture effect must be synchronized to the actual capture window');
assert.ok(!live.includes('capturedCount'), 'Group capture must not depend on the optional last_move.captured counter');

/* Effect 3 manual QA must show the same square markers beside the played stone, not on every stone. */
assert.ok(live.includes('nearbyTerritoryPreviewCells(container, size, placedCell, tone)'), 'Effect 3 QA must derive a bounded nearby marker set');
assert.ok(live.includes('if (cells.length >= 3) return;'), 'Effect 3 QA must cap the preview at three nearby squares');
assert.ok(live.includes("if (!(point instanceof HTMLElement) || point.querySelector('.go-stone')) return;"), 'Effect 3 QA markers must only use empty intersections');
assert.ok(live.includes("marker.className = `mgw-go-live-territory ${tone}`"), 'Effect 3 QA must reuse the approved Store square marker class');
assert.ok(live.includes("marker.style.setProperty('--go-x', x)"), 'Territory square x coordinate must come from a real Go intersection');
assert.ok(live.includes("marker.style.setProperty('--go-y', y)"), 'Territory square y coordinate must come from a real Go intersection');
assert.ok(!live.includes("board.dataset.mgwGoSeal = 'MGW'"), 'Rejected MG/MGW seal must not return');
assert.ok(!live.includes("board.dataset.mgwGoFx = 'territory-finish'"), 'Live territory effect must not reactivate the rejected board-wide centre bloom owner');
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

/* Effect 1 was manually accepted and v6 must not touch it. */
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Accepted placement burst must remain present');
assert.ok(!correctiveV6Css.includes('data-mgw-go-fx="placement"'), 'Final v6 corrective must not alter accepted Effect 1');

/* Effect 2 must use the approved Store VOID CAPTURE language. */
assert.ok(correctiveV6Css.includes('.go-point[data-mgw-go-fx="group-capture"].mgw-go-capture-paid-active::before'), 'Capture corona must have a guaranteed paid-active trigger');
assert.ok(correctiveV6Css.includes('.go-point[data-mgw-go-fx="group-capture"].mgw-go-capture-paid-active .go-stone'), 'Captured stone must receive the Store implode motion');
for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(correctiveV6Css.includes(token), `Live capture must reuse approved Store token: ${token}`);
}

/* Effect 3 uses only the approved Store square appearance and explicitly suppresses centre waves. */
for (const token of [
  'width:7.6%',
  'background:rgba(73,231,255,.34)',
  'border:1px solid #54ecff',
  'background:rgba(202,100,255,.27)',
  'border:1px solid #d66cff',
]) {
  assert.ok(storeCss.includes(token), `Approved Store territory token missing: ${token}`);
  assert.ok(correctiveV6Css.includes(token), `Live territory effect must reuse approved Store token: ${token}`);
}
assert.ok(correctiveV6Css.includes('@keyframes mgw-go-v6-store-territory-stamp'), 'Live territory squares must keep the Store stamp motion');
assert.ok(correctiveV6Css.includes('34%{opacity:1;transform:translate(-50%,-50%) scale(1.2) rotate(6deg)}'), 'Live territory stamp must preserve Store overshoot/rotation');
assert.ok(correctiveV6Css.includes('43%,72%{opacity:1;transform:translate(-50%,-50%) scale(1) rotate(0)}'), 'Live territory stamp must preserve Store hold phase');
assert.ok(correctiveV6Css.includes('.go-board[data-mgw-go-territory-qa="placement"]::before'), 'Final corrective must explicitly own the old QA centre pseudo-element');
assert.ok(correctiveV6Css.includes('content:none!important'), 'Old centre bloom/seal must be suppressed');
assert.ok(!correctiveV6Css.includes('radial-gradient(circle,rgba(95,239,255,.20)'), 'Final Effect 3 must not emit the rejected centre wave');
assert.ok(!correctiveV6Css.includes('MGW'), 'Effect 3 must not reintroduce a central MGW mark');

/* The already accepted Go field geometry must remain untouched. */
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

/* Rules screenshots: forbidden and ko markers must sit on the exact intersection centre. */
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

assert.ok(v110.includes("$goEffectsV6Target = './assets/css/games/go/live-effects-corrective-v6.css?v=1&mvp19_8=final-capture-adjacent-territory-v6';"), 'v110 must publish the final Go effects v6 stylesheet');
assert.ok(v110.includes("$goRulesAlignmentTarget = './assets/css/games/go/rules-alignment-v1.css?v=1&mvp19_8=rule-marker-center-v1';"), 'v110 must publish the Go rules marker alignment stylesheet');
assert.ok(v110.includes('data-mgw-go-live-effects-v6="mvp19-8-final-capture-adjacent-territory-v6"'), 'v110 must expose the unique Go v6 effect marker');
assert.ok(v110.includes('data-mgw-go-rules-alignment="mvp19-8-rule-marker-center-v1"'), 'v110 must expose the Go rules alignment marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=live-final-corrective-v6';"), 'v110 must force a fresh Go renderer identity for manual review');
assert.ok(v110.includes("'go_live_final_effects' => $goEffectsV6Target"), 'v110 rendered-target guard must require the final Go v6 stylesheet');
assert.ok(v110.includes("'go_rules_marker_alignment' => $goRulesAlignmentTarget"), 'v110 rendered-target guard must require the rules alignment stylesheet');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds a cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1146, 'Telegram launch must publish the final Go corrective cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live final corrective contract passed: field untouched; Effect 1 accepted/untouched; capture fallback visible; Effect 3 uses three adjacent Store squares; Go rules markers centered.');
