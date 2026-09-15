import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const correctiveV4Css = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v4.css', 'utf8');
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
assert.ok(live.includes(".filter(point => point instanceof HTMLElement && point.querySelector('.go-stone'))"), 'Temporary territory QA cubes must be derived from actually occupied Go points');
assert.ok(live.includes("point.dataset.mgwGoTerritoryTone = stone.classList.contains('black') ? 'black' : 'white'"), 'Temporary cubes must inherit their real stone side');
assert.ok(!live.includes("board.dataset.mgwGoSeal = 'MGW'"), 'Rejected MG/MGW seal must not be emitted by the active Go renderer');
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
assert.ok(correctiveV4Css.includes('mgw-go-live-territory-cube-home-v4'), 'Effect 3 must restore the visible cube reconstruction animation');
assert.ok(correctiveV4Css.includes('translate(var(--mgw-go-cube-x,0),var(--mgw-go-cube-y,0))'), 'Territory cubes must move only relative to their own target point');
assert.ok(correctiveV4Css.includes('translate(0,0) scale(1)'), 'Territory cubes must reconstruct exactly on their target point');
assert.ok(correctiveV4Css.includes('.go-board[data-mgw-go-territory-qa="placement"]::before'), 'Effect 3 QA must explicitly suppress the board-wide wave');
assert.ok(correctiveV4Css.includes('.go-board[data-mgw-go-fx="territory-finish"]::after'), 'Effect 3 must explicitly suppress the rejected central seal layer');
assert.ok(correctiveV4Css.includes('content:none!important'), 'Rejected wave/logo layers must be removed by the v4 corrective');

/* Keep Checkers-style bounded scrolling, but never narrow the actual Go play column. */
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
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .board.go-surface,'), 'Go width corrective must bind the board to the shared full-width play column');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] .go-pass-button,'), 'Go pass button must share the board width');
assert.ok(exitFitCss.includes('.game-board-screen[data-game-type="go"] #leaveGame{'), 'Go leave button must share the same column width');
assert.ok(exitFitCss.includes('width:100%!important'), 'Go board and controls must use the full available content width');
assert.ok(!exitFitCss.includes('clamp('), 'Rejected short-viewport board-width clamp must not return');
assert.ok(!exitFitCss.includes('calc(100dvh - 245px)'), 'Height-derived board narrowing must not return');

assert.ok(v110.includes("$goExitFitTarget = './assets/css/games/go/live-exit-fit-v1.css?v=3&mvp19_8=full-width-scroll-v3';"), 'v110 must publish the fresh full-width Go viewport stylesheet');
assert.ok(v110.includes("$goEffectsV4Target = './assets/css/games/go/live-effects-corrective-v4.css?v=1&mvp19_8=territory-cubes-no-bloom-v4';"), 'v110 must publish the cube reconstruction corrective stylesheet');
assert.ok(v110.includes('data-mgw-go-live-effects-v4="mvp19-8-territory-cubes-no-bloom-v4"'), 'v110 must expose the unique Go v4 effect marker');
assert.ok(v110.includes("$imports[$goRendererImportKey] .= '&manual_review=territory-cubes-v4';"), 'v110 must force a fresh Go renderer identity for manual review');
assert.ok(v110.includes("'go_full_width_scroll' => $goExitFitTarget"), 'v110 rendered-target guard must require the full-width viewport stylesheet');
assert.ok(v110.includes("'go_territory_cubes_no_bloom' => $goEffectsV4Target"), 'v110 rendered-target guard must require the cube corrective stylesheet');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Version manifest must keep the accepted Go renderer owner; v110 only adds a cache identity suffix',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1144, 'Telegram launch must publish the fresh Go width/cubes corrective cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live corrective contract passed: full-width play column, cube reconstruction on real occupied points, no board wave or MG/MGW seal.');
