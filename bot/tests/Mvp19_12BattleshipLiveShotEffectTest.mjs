import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const live = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer-cosmetics-v1.js'), 'utf8');
const liveCss = fs.readFileSync(path.join(root, 'app/assets/css/games/battleship/live-cosmetics-v1.css'), 'utf8');
const base = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer.js'), 'utf8');
const gameScreen = fs.readFileSync(path.join(root, 'app/assets/js/screens/game-screen-v102.js'), 'utf8');
const manifest = fs.readFileSync(path.join(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = fs.readFileSync(path.join(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const entry = fs.readFileSync(path.join(root, 'app/v110.php'), 'utf8');

assert.ok(live.includes("const EFFECT_SLOT = 'game_battleship_effect'"), 'Shot must use the canonical Battleship effect slot');
assert.ok(live.includes("const SHOT_ID = 'game-battleship-effect-shot'"), 'Shot must use the catalog Shot item id');
assert.ok(live.includes("const HIT_ID = 'game-battleship-effect-hit'") && live.includes("const DESTROY_ID = 'game-battleship-effect-destroy'"), 'Shot acceptance must coexist with Hit/Destroy review ids without changing Shot ownership');

assert.ok(!live.includes("container.addEventListener('click', handler, true)"), 'Local Shot must not run from an independent raw capture click');
assert.ok(live.includes("document.addEventListener('mgw:battleship-fire-queued'"), 'Local Shot must listen only to the canonical queued-fire event');
assert.ok(gameScreen.includes("document.dispatchEvent(new CustomEvent('mgw:battleship-fire-queued'"), 'Game action owner must publish queued fire after accepting the action');
assert.ok(gameScreen.indexOf("item.queue.push({ action:clone(action) });") < gameScreen.indexOf("new CustomEvent('mgw:battleship-fire-queued'"), 'Queued-fire event must be emitted only after the action entered the client queue');
assert.ok(live.includes("context.viewerEffect !== SHOT_ID"), 'Queued Shot must still require the equipped Plasma Shot effect');
assert.ok(live.includes("ownerId !== context.myId"), 'Queued Shot must belong to the local viewer');

assert.ok(live.includes("game?.last_shooter_id") && live.includes("game?.last_shot"), 'Remote/authoritative Shot must follow the canonical shot owner and target');
assert.ok(live.includes("source:'local-fire'") && live.includes("source:'authoritative-shot'"), 'Shot runtime must distinguish local pre-result and authoritative remote paths');
const shotStart = live.indexOf('function ensureShotQueueListener');
const impactStart = live.indexOf('function maybePlayResultEffect');
assert.ok(shotStart >= 0 && impactStart > shotStart, 'Shot and result-effect owners must remain separately bounded');
const shotOwner = live.slice(shotStart, impactStart);
assert.ok(!shotOwner.includes('last_result'), 'Shot owner must never inspect result before deciding its visual');
assert.ok(!live.includes('enemy_board') && !live.includes('my_board'), 'Shot presentation must not inspect hidden board payloads');
assert.ok(!live.includes('onAction'), 'Shot presentation must not replace or call the gameplay action owner');
assert.ok(base.includes("onAction?.({ type:'fire', cell:Number(button.dataset.battleshipCell) })"), 'Base renderer must retain the canonical fire action');

for (const token of [
  'mgw-bs-live-shot-fx',
  'mgw-bs-live-shot-reticle',
  'mgw-bs-live-shot-tracer',
  'mgw-bs-live-shot-bolt',
  'mgw-bs-live-shot-ping',
  'plasma-lock-v1',
]) {
  assert.ok(live.includes(token) || liveCss.includes(token), `LIVE Shot must publish visual token ${token}`);
}

assert.ok(liveCss.includes('rgba(126,248,255,.96)') && liveCss.includes('#f2feff'), 'Shot must stay in cyan/white pre-result language');
assert.ok(liveCss.includes('@media (prefers-reduced-motion:reduce)'), 'Shot must provide reduced-motion handling');
assert.ok(live.includes("duration:560") && live.includes("duration:540"), 'Shot tracer/bolt must remain sub-second but slower and readable');
assert.ok(live.includes("translateX(${distance}px) scale(1.12)") && live.includes("offset:.82"), 'LIVE bolt must visibly reach the exact target before fading instead of disappearing short');
assert.ok(live.includes("globalThis.setTimeout(cleanup, 900)"), 'Shot overlay must have a bounded cleanup fallback');

assert.ok(
  manifest.includes("renderer-cosmetics-v1.js?v=4&mvp19_12=live-maps-fleets-v4&frame=full-v1&neon_fleet=tube-v4&base=v60-shot-miss-no-impact"),
  'Shot manual review must preserve the accepted Battleship manifest baseline'
);
assert.ok(entry.includes("$battleshipRendererImportKey = './assets/js/games/battleship/renderer.js?v=56'") && entry.includes("$imports[$battleshipRendererImportKey] .= '&live_effects=accepted-three-v4&fire=queue-gap-v2&shot_motion=readable-v2&hit=preview-parity-v2';"), 'Active v110 runtime must cache-bust the accepted three-effect module with queued-fire ownership');
assert.ok(launch.includes('/app/v110.php?v=1233&'), 'Shot must preserve the accepted shared Telegram route version');
assert.ok(launch.includes('battleship_shot=live-v2') && launch.includes('battleship_impacts=live-v2') && launch.includes('battleship_fire=queue-gap-v2'), 'Telegram route must preserve accepted effects and publish reliable-fire identity');

console.log('Battleship LIVE Shot effect contract passed.');
