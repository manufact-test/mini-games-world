import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV5Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v5.css', 'utf8');
const storeCss = fs.readFileSync('app/assets/css/games/go/store-cosmetics-v1.css', 'utf8');
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
assert.ok(live.includes("point.dataset.mgwGoFx = 'placement'"), 'Placement must attach to the authoritative last-move point');
assert.ok(live.includes("point.dataset.mgwGoFx = 'group-capture'"), 'Group capture must attach to authoritative captured cells');
assert.ok(live.includes('if (captured.length <= 0) return;'), 'Group capture must use authoritative captured-cell presence as its gate');
assert.ok(!live.includes('capturedCount'), 'Group capture must not depend on the optional last_move.captured counter');
assert.ok(live.includes('game?.last_captured_cells'), 'Live group capture must use authoritative captured-cell data');
assert.ok(live.includes('marker.className = `mgw-go-live-territory'), 'Temporary territory review must create the approved Store-style square markers');
assert.ok(live.includes("marker.style.setProperty('--go-x', x)"), 'Territory square x coordinate must come from a real Go intersection');
assert.ok(live.includes("marker.style.setProperty('--go-y', y)"), 'Territory square y coordinate must come from a real Go intersection');
assert.ok(!live.includes("board.dataset.mgwGoSeal = 'MGW'"), 'Rejected MG/MGW seal must not return');
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

/* Effect 1 was manually accepted and v5 must not touch it. */
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Accepted placement burst must remain present');
assert.ok(!correctiveV5Css.includes('data-mgw-go-fx="placement"'), 'Store-parity v5 must not alter accepted Effect 1');

/* Effect 2 must use the Store VOID CAPTURE language and start on real capture-out. */
assert.ok(correctiveV5Css.includes('.go-point[data-mgw-go-fx="group-capture"].capture-out::before'), 'Capture corona must start exactly when the captured stone starts leaving');
assert.ok(correctiveV5Css.includes('.go-point[data-mgw-go-fx="group-capture"].capture-out .go-stone'), 'Captured stone must receive the Store implode motion');
for (const token of [
  'conic-gradient(from 0deg,#66efff 0 8%,transparent 9% 27%,#d36cff 28% 36%,transparent 37% 57%,#7fffd4 58% 66%,transparent 67% 86%,#fff 87% 93%,transparent 94% 100%)',
  '13px 0 #67efff,-13px 0 #d06dff,0 13px #7fffd4,0 -13px #fff3a0',
]) {
  assert.ok(storeCss.includes(token), `Approved Store capture token missing: ${token}`);
  assert.ok(correctiveV5Css.includes(token), `Live capture must reuse approved Store token: ${token}`);
}

/* Effect 3 must mirror the already-approved Store preview, not invent a new animation. */
for (const token of [
  'width:7.6%',
  'background:rgba(73,231,255,.34)',
  'border:1px solid #54ecff',
  'background:rgba(202,100,255,.27)',
  'border:1px solid #d66cff',
  'radial-gradient(circle,rgba(95,239,255,.20) 0 16%,rgba(180,105,255,.13) 31%,rgba(255,214,117,.07) 45%,transparent 68%)',
  'border-color:rgba(111,239,255,.54)',
  'border-color:rgba(218,117,255,.46)',
]) {
  assert.ok(storeCss.includes(token), `Approved Store territory token missing: ${token}`);
  assert.ok(correctiveV5Css.includes(token), `Live territory effect must reuse approved Store token: ${token}`);
}
assert.ok(correctiveV5Css.includes('@keyframes mgw-go-v5-store-territory-stamp'), 'Live territory squares must keep the Store stamp motion');
assert.ok(correctiveV5Css.includes('34%{opacity:1;transform:translate(-50%,-50%) scale(1.2) rotate(6deg)}'), 'Live territory stamp must preserve Store overshoot/rotation');
assert.ok(correctiveV5Css.includes('43%,72%{opacity:1;transform:translate(-50%,-50%) scale(1) rotate(0)}'), 'Live territory stamp must preserve Store hold phase');
assert.ok(!correctiveV5Css.includes('MGW'), 'Effect 3 must not reintroduce a central MGW mark');

/* Keep Checkers-style bounded scrolling and the already accepted full-width Go column. */
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
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .board.go-surface,'), 'Go width corrective must keep the board in the shared full-width play column');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .go-pass-button,'), 'Go pass button must keep the board width');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] #leaveGame{'), 'Go leave button must keep the same column width');
assert.ok(!exitFitCss.includes('clamp('), 'Rejected width clamp must not return');

assert.ok(v110.includes("$goEffectsV5Target = './assets/css/games/go/live-effects-corrective-v5.css?v=1&mvp19_8=store-effect-parity-v5';"), 'v110 must publish the Store-parity v5 stylesheet');
assert.ok(v110.includes('data-mgw-go-live-effects-v5="mvp19-8-store-effect-parity-v5"'), 'v110 must expose the unique Go v5 effect marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=store-effect-parity-v5';"), 'v110 must force a fresh Go renderer identity for manual review');
assert.ok(v110.includes("'go_store_effect_parity' => $goEffectsV5Target"), 'v110 rendered-target guard must require the Store-parity stylesheet');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds a cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1145, 'Telegram launch must publish the fresh Store-parity Go effect cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live contract passed: Effect 1 untouched; capture and territory visuals mirror the approved Store language; gameplay remains frozen.');
