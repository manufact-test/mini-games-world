import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const live = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer-cosmetics-v1.js'), 'utf8');
const liveCss = fs.readFileSync(path.join(root, 'app/assets/css/games/battleship/live-cosmetics-v1.css'), 'utf8');
const base = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer.js'), 'utf8');
const entry = fs.readFileSync(path.join(root, 'app/v110.php'), 'utf8');
const launch = fs.readFileSync(path.join(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

assert.ok(live.includes("const HIT_ID = 'game-battleship-effect-hit'"), 'Hit must use the catalog Hit item id');
assert.ok(live.includes("const DESTROY_ID = 'game-battleship-effect-destroy'"), 'Destroy must use the catalog Destroy item id');
assert.ok(live.includes("const EFFECT_IDS = new Set([SHOT_ID, HIT_ID, DESTROY_ID])"), 'All three Battleship effects must share the canonical effect slot');

assert.ok(live.includes("const result = String(game?.last_result || '')"), 'Result effects must wait for the authoritative shot result');
assert.ok(live.includes("result === 'hit' ? HIT_ID : (result === 'sunk' ? DESTROY_ID : '')"), 'Hit and Destroy must map only to their matching authoritative result');
assert.ok(live.includes("effectForPlayer(gameId, shooter, me, game) !== requiredEffect"), 'Result animation must belong to the actual shooter cosmetic');
assert.ok(live.includes("targetCell.classList.remove('shot-impact')"), 'Paid Hit/Destroy must suppress only the base cell-scale flash to keep one visual owner');
assert.ok(live.includes("const cell = Number(game?.last_shot)"), 'Result effects must anchor to the canonical last-shot cell');
assert.ok(live.includes("source:'local-fire'") && live.includes("source:'authoritative-shot'"), 'Accepted Shot paths must remain intact');

assert.ok(live.includes("function connectedVisibleSunkCells"), 'Destroy must resolve the visible sunk ship geometry');
assert.ok(live.includes(".battleship-cell[data-cell-state=\"sunk\"]"), 'Destroy geometry must use already-rendered public sunk cells');
assert.ok(live.includes("if (row > 0) neighbors.push(cell - 10)") && live.includes("if (col < 9) neighbors.push(cell + 1)"), 'Destroy must follow only orthogonally connected sunk cells');
assert.ok(!live.includes('enemy_board') && !live.includes('my_board'), 'Impact effects must never inspect hidden board payloads');
assert.ok(!live.includes('onAction'), 'Impact effects must never replace or invoke gameplay actions');

assert.ok(base.includes("const delay = result === 'miss' ? 900 : 1250"), 'Accepted 900/1250 result presentation timing must remain owned by the base renderer');
assert.ok(base.includes("if (result === 'hit') return { text:'Попадание! Стреляйте ещё'"), 'Accepted Hit notice semantics must remain unchanged');
assert.ok(base.includes("if (result === 'sunk') return { text:'Корабль потоплен! Стреляйте ещё'"), 'Accepted Destroy notice semantics must remain unchanged');

for (const token of [
  'mgw-bs-live-hit-fx',
  'mgw-bs-live-hit-core',
  'mgw-bs-live-hit-ring',
  'mgw-bs-live-hit-flare',
  'mgw-bs-live-hit-spark',
  'impact-flash-v1',
]) {
  assert.ok(live.includes(token) || liveCss.includes(token), `Hit must publish visual token ${token}`);
}

for (const token of [
  'mgw-bs-live-destroy-fx',
  'mgw-bs-live-destroy-flash',
  'mgw-bs-live-destroy-ring',
  'mgw-bs-live-destroy-wreck',
  'mgw-bs-live-destroy-smoke',
  'mgw-bs-live-destroy-shard',
  'critical-sink-v1',
]) {
  assert.ok(live.includes(token) || liveCss.includes(token), `Destroy must publish visual token ${token}`);
}

assert.ok(liveCss.includes('rgba(255,214,101,.9)') && liveCss.includes('rgba(255,178,55,.94)'), 'Hit must stay compact amber/yellow instead of reusing cyan Shot language');
assert.ok(liveCss.includes('rgba(255,86,69,.92)') && liveCss.includes('rgba(239,53,60,.88)'), 'Destroy must use a heavier red/orange wreck language');
assert.ok(live.includes("duration:720") && live.includes("duration:820") && live.includes("globalThis.setTimeout(cleanup, 1050)"), 'Hit must remain a compact bounded impact');
assert.ok(live.includes("duration:1050") && live.includes("duration:1180") && live.includes("globalThis.setTimeout(cleanup, 1500)"), 'Destroy must remain visibly heavier but bounded');

assert.ok(entry.includes("$imports[$battleshipRendererImportKey] .= '&live_effects=accepted-three-v2&fire=queued-v1';"), 'Active v110 runtime must publish the accepted three-effect queued-fire module');
assert.ok(launch.includes('battleship_shot=live-v1') && launch.includes('battleship_impacts=live-v1') && launch.includes('battleship_fire=queued-reconcile-v1'), 'Telegram route must publish all accepted effects plus reliable-fire identity');

console.log('Battleship LIVE Hit/Destroy effect contract passed.');
