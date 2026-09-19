import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const gameScreen = fs.readFileSync(path.join(root, 'app/assets/js/screens/game-screen-v102.js'), 'utf8');
const live = fs.readFileSync(path.join(root, 'app/assets/js/games/battleship/renderer-cosmetics-v1.js'), 'utf8');
const gameCss = fs.readFileSync(path.join(root, 'app/assets/css/games/battleship/game.css'), 'utf8');
const mainCss = fs.readFileSync(path.join(root, 'app/assets/css/main.css'), 'utf8');
const entry = fs.readFileSync(path.join(root, 'app/v110.php'), 'utf8');
const launch = fs.readFileSync(path.join(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');
const backend = fs.readFileSync(path.join(root, 'bot/games/battleship/BattleshipService.php'), 'utf8');

const submitStart = gameScreen.indexOf('function submitAction');
const drainStart = gameScreen.indexOf('async function drainActions');
assert.ok(submitStart >= 0 && drainStart > submitStart, 'Fire reliability test must locate the canonical action owner');
const submit = gameScreen.slice(submitStart, drainStart);

assert.ok(submit.includes("const localBattleshipFire = type === 'battleship'"), 'Battleship fire must have an explicit local queue guard');
assert.ok(submit.includes("if ((localBattleshipSetup || localBattleshipFire) && !optimistic) return false;"), 'A stale/invalid Battleship fire must not enter the queue');
assert.ok(submit.includes("item.queue.some(entry => String(entry?.action?.type || '') === 'fire')"), 'Only one Battleship fire may be pending at a time');
assert.ok(submit.includes("item.queue.push({ action:clone(action) });"), 'Valid fire must use the canonical action queue');
assert.ok(submit.includes("new CustomEvent('mgw:battleship-fire-queued'"), 'Accepted queued fire must publish the presentation event');
assert.ok(
  submit.indexOf("item.queue.push({ action:clone(action) });") < submit.indexOf("new CustomEvent('mgw:battleship-fire-queued'"),
  'Shot presentation event must happen only after the fire action entered the queue'
);

assert.ok(live.includes("document.addEventListener('mgw:battleship-fire-queued'"), 'Plasma Shot must subscribe to the queued-fire event');
assert.ok(!live.includes("container.addEventListener('click', handler, true)"), 'Plasma Shot must not have an independent raw capture-click owner');
assert.ok(live.includes("context.viewerEffect !== SHOT_ID"), 'Queued-fire presentation must still require Plasma Shot to be equipped');
assert.ok(live.includes("source:'local-fire'"), 'Queued local fire must retain the accepted Plasma Shot animation');
assert.ok(!live.includes('enemy_board') && !live.includes('my_board'), 'Cosmetic presentation must remain blind to hidden Battleship boards');

const reconcileStart = gameScreen.indexOf('async function reconcileBattleshipFireFailure');
const renderStart = gameScreen.indexOf('function renderGame', reconcileStart);
assert.ok(reconcileStart >= 0 && renderStart > reconcileStart, 'Fire response reconciliation owner must exist');
const reconcile = gameScreen.slice(reconcileStart, renderStart);
assert.ok(reconcile.includes('await api.gameState(gameId)'), 'Lost fire responses must reconcile against fresh authoritative game state');
assert.ok(reconcile.includes("['miss','hit','sunk'].includes(cellState) ? 'committed'"), 'A resolved public enemy cell must prove that the server committed the shot');
assert.ok(!reconcile.includes('api.gameAction('), 'Reconciliation must never blindly retry fire and risk a duplicate shot');

const drain = gameScreen.slice(drainStart, reconcileStart);
assert.ok(drain.includes('await reconcileBattleshipFireFailure(gameId, item, queued.action)'), 'Action failure must reconcile Battleship fire before rollback');
assert.ok(drain.indexOf('await reconcileBattleshipFireFailure') < drain.indexOf('item.queue.length = 0'), 'Reconciliation must happen before clearing/restoring the queue');

assert.ok(gameCss.includes('#gameBoard[data-game-type="battleship"].mgw-action-pending .battleship-cell.interactive{pointer-events:none}'), 'Battleship board must reject extra taps while a fire is pending');
assert.ok(gameCss.includes('.battleship-cell.mgw-pending-shot::before'), 'Pending fire must have a visible bounded marker');
assert.ok(mainCss.includes("./games/battleship/game.css?v=57&fire=pending-lock-v1"), 'Main CSS must publish the pending-fire lock');

assert.ok(backend.includes("if ((string)(\$game['turn'] ?? '') !== \$shooterId) throw new RuntimeException('Сейчас не ваш ход.');"), 'Backend turn ownership must stay authoritative');
assert.ok(backend.includes("if (isset(\$shots[\$cell])) throw new RuntimeException('Вы уже стреляли в эту клетку.');"), 'Backend duplicate-shot protection must stay authoritative');

assert.ok(entry.includes("\$battleshipGameScreenImportKey = './assets/js/screens/game-screen-v102.js?v=102'"), 'Active v110 must cache-bust the game-screen fire owner');
assert.ok(entry.includes("&battleship_fire=queued-reconcile-v1"), 'Active v110 must publish queued-fire reconciliation module identity');
assert.ok(entry.includes("&live_effects=accepted-three-v3&fire=queued-v1&shot_motion=readable-v2"), 'Active v110 must publish the accepted effect renderer with queued-fire ownership');
assert.ok(entry.includes("&battleship_fire=pending-lock-v1"), 'Active v110 must refresh the main CSS pending-fire owner');
assert.ok(launch.includes('battleship_fire=queued-reconcile-v1'), 'Telegram launch must expose the reliable-fire build identity');

console.log('Battleship fire reliability contract passed.');
