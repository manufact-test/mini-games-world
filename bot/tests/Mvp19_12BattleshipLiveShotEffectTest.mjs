import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const live = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer-cosmetics-v1.js'), 'utf8');
const liveCss = fs.readFileSync(path.join(root, 'app/assets/css/games/battleship/live-cosmetics-v1.css'), 'utf8');
const base = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer.js'), 'utf8');
const manifest = fs.readFileSync(path.join(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = fs.readFileSync(path.join(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

assert.ok(live.includes("const EFFECT_SLOT = 'game_battleship_effect'"), 'Shot must use the canonical Battleship effect slot');
assert.ok(live.includes("const SHOT_ID = 'game-battleship-effect-shot'"), 'Shot must use the catalog Shot item id');
assert.ok(!live.includes('game-battleship-effect-hit') && !live.includes('game-battleship-effect-destroy'), 'Hit/Destroy must stay inactive before manual acceptance');

assert.ok(live.includes("container.addEventListener('click', handler, true)"), 'Local Shot must observe the legal fire click before the base bubble handler');
assert.ok(live.includes("String(target.dataset.cellState || '') !== 'unknown'"), 'Local Shot must only arm on unrevealed enemy cells');
assert.ok(live.includes("target.classList.contains('interactive')"), 'Local Shot must require the base renderer interactive state');
assert.ok(live.includes("String(game?.status || '') === 'active'"), 'Local Shot must require an active game');
assert.ok(live.includes("String(game?.turn || '') === myId"), 'Local Shot must require the viewer turn');

assert.ok(live.includes("game?.last_shooter_id") && live.includes("game?.last_shot"), 'Remote/authoritative Shot must follow the canonical shot owner and target');
assert.ok(live.includes("source:'local-fire'") && live.includes("source:'authoritative-shot'"), 'Shot runtime must distinguish local pre-result and authoritative remote paths');
assert.ok(!live.includes('last_result'), 'Shot effect must never inspect result before deciding its visual');
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
assert.ok(live.includes("duration:560") && live.includes("duration:420") && live.includes("duration:390"), 'Shot must remain a short sub-second event');
assert.ok(live.includes("globalThis.setTimeout(cleanup, 900)"), 'Shot overlay must have a bounded cleanup fallback');

assert.ok(
  manifest.includes("renderer-cosmetics-v1.js?v=5&mvp19_12=live-maps-fleets-v4&frame=full-v1&neon_fleet=tube-v4&shot=plasma-lock-v1&base=v60-shot-miss-no-impact"),
  'Active runtime manifest must publish the Shot wrapper'
);
assert.ok(launch.includes('/app/v110.php?v=1233&'), 'Shot must preserve the accepted shared Telegram route version');
assert.ok(launch.includes('battleship_shot=live-v1'), 'Telegram route must publish the LIVE Shot identity');

console.log('Battleship LIVE Shot effect contract passed.');
