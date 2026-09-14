import fs from 'node:fs';
import assert from 'node:assert/strict';

const live = fs.readFileSync('app/assets/js/games/go/renderer-cosmetics-v1.js', 'utf8');
const css = fs.readFileSync('app/assets/css/games/go/live-cosmetics-v1.css', 'utf8');
const correctiveCss = fs.readFileSync('app/assets/css/games/go/live-effects-corrective-v2.css', 'utf8');
const manifest = fs.readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const launch = fs.readFileSync('bot/helpers/WebAppLaunchUrl.php', 'utf8');
const base = fs.readFileSync('app/assets/js/games/go/renderer.js', 'utf8');

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
assert.ok(live.includes("board.dataset.mgwGoFx = 'territory-finish'"), 'Territory finish must decorate the Go board');
assert.ok(live.includes("board.dataset.mgwGoSeal = 'MGW'"), 'Territory finish must expose the central MGW seal requested in manual review');
assert.ok(live.includes('applyTerritoryQaPreview'), 'Territory finish must expose the temporary placement QA preview requested for manual review');
assert.ok(live.includes("board.dataset.mgwGoTerritoryQa = 'placement'"), 'Temporary territory QA trigger must be explicitly isolated for later removal');
assert.ok(live.includes('game?.last_captured_cells'), 'Live group capture must use authoritative captured-cell data');
assert.ok(live.includes("String(game?.status || '') === 'finished'"), 'Canonical territory finish must still activate on a finished game');
assert.ok(live.includes('game?.final_score'), 'Canonical territory finish must still require authoritative final territory data');
assert.ok(live.includes('live-effects-corrective-v2.css?v=2&mvp19_8=live-effects-corrective-v2'), 'Live owner must publish the corrective stylesheet with a fresh cache identity');
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
  'mgw-go-live-mgw-seal',
]) {
  assert.ok(css.includes(`@keyframes ${fx}`), `Missing Go live animation ${fx}`);
}
for (const fx of [
  'mgw-go-live-placement-burst-v2',
  'mgw-go-live-placement-seal-v2',
  'mgw-go-live-territory-qa-stamp',
  'mgw-go-live-territory-bloom-qa',
  'mgw-go-live-mgw-seal-qa',
]) {
  assert.ok(correctiveCss.includes(`@keyframes ${fx}`), `Missing Go live corrective animation ${fx}`);
}
assert.ok(correctiveCss.includes('26px 0 #67efff'), 'Placement corrective must include an outward particle burst rather than stone motion only');
assert.ok(correctiveCss.includes('[data-mgw-go-territory-fx="qa"]'), 'Temporary territory QA stamps must be visible on ordinary placement');
assert.ok(css.includes('content:attr(data-mgw-go-seal)'), 'Central live seal must be rendered from the MGW marker');
assert.ok(correctiveCss.includes('@media (prefers-reduced-motion:reduce)'), 'Go live corrective effects must retain reduced-motion handling');

assert.ok(
  manifest.includes("'./assets/js/games/go/renderer.js?v=70' => './assets/js/games/go/renderer-cosmetics-v1.js?v=2&mvp19_8=live-effects-corrective-v2&fx=placement-burst-capture-guard-territory-qa-v2'"),
  'Active v110 import graph must route Go through the fresh corrective cache identity',
);
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
assert.ok(launchMatch && Number(launchMatch[1]) >= 1141, 'Telegram launch must publish the fresh Go live corrective cache identity');

assert.ok(base.includes('function shouldAnimateMove('), 'Accepted Go move animation sequencing must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'cell', cell });"), 'Accepted Go move action owner must remain in the frozen base renderer');
assert.ok(base.includes("onAction?.({ type:'pass' });"), 'Accepted Go pass action owner must remain in the frozen base renderer');

console.log('MVP-19.8 Go Live corrective contract passed: placement burst parity, capture-only trigger, temporary territory QA preview.');
