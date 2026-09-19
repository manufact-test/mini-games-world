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
  'impact-flash-v2',
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
  'critical-sink-v3',
]) {
  assert.ok(live.includes(token) || liveCss.includes(token), `Destroy must publish visual token ${token}`);
}

assert.ok(liveCss.includes('rgba(255,214,101,.9)') && liveCss.includes('rgba(255,178,55,.94)'), 'Hit must stay compact amber/yellow instead of reusing cyan Shot language');
assert.ok(liveCss.includes('rgba(255,86,69,.92)') && liveCss.includes('rgba(239,53,60,.88)'), 'Destroy must use a heavier red/orange wreck language');
assert.ok(live.includes("duration:980") && live.includes("duration:1120") && live.includes("duration:900") && live.includes("duration:1080"), 'Hit must expose the slower readable core/ring/flare/streak phases');
assert.ok(live.includes("globalThis.setTimeout(cleanup, 1450)"), 'Hit must remain bounded while giving streaks time to read');
assert.ok(live.includes("for (let index = 0; index < 6; index += 1)"), 'LIVE Hit must retain all six preview-parity streaks');
assert.ok(liveCss.includes("height:21px") && liveCss.includes("z-index:4") && liveCss.includes("#fffdf0"), 'LIVE Hit streaks must be visibly longer, brighter and layered above the core');
assert.ok(live.includes("duration:1500") && live.includes("duration:1650") && live.includes("duration:1780") && live.includes("duration:1900") && live.includes("duration:1550"), 'Destroy must expose slower readable fire, shockwave, smoke and debris phases');
assert.ok(live.includes("globalThis.setTimeout(cleanup, 2250)"), 'Destroy must remain bounded while keeping smoke/fire readable');
assert.ok(live.includes("const visualY = y + Math.max(2, Math.min(5, cellHeight * .12))"), 'LIVE Destroy must apply the small optical downshift from the actual sunk-ship center');
assert.ok(liveCss.includes('.mgw-bs-live-destroy-flash{\n  z-index:6;'), 'Destroy fire core must render above every wreck layer');
assert.ok(liveCss.includes('.mgw-bs-live-destroy-wreck{\n  z-index:2;'), 'Destroy wreck must remain below the bright fire core');
assert.ok(liveCss.includes('.mgw-bs-live-destroy-shard{\n  z-index:5;'), 'Destroy debris must remain readable without covering the fire core');

assert.ok(entry.includes("$imports[$battleshipRendererImportKey] .= '&live_effects=accepted-three-v6&fire=direct-result-v4&shot_motion=readable-v2&hit=preview-parity-v2&destroy=fire-layer-v3';"), 'Active v110 runtime must publish the accepted three-effect queued-fire module');
assert.ok(launch.includes('battleship_shot=live-v2') && launch.includes('battleship_impacts=live-v2') && launch.includes('battleship_destroy=live-v3') && launch.includes('battleship_fire=direct-result-v4'), 'Telegram route must publish all accepted effects plus reliable-fire identity');

console.log('Battleship LIVE Hit/Destroy effect contract passed.');
