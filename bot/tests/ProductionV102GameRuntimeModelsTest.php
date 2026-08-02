<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/screens/game-screen-v102.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v102 game runtime.');

$tempDir = sys_get_temp_dir() . '/mgw_v102_game_models_' . bin2hex(random_bytes(6));
mkdir($tempDir . '/components', 0700, true);
mkdir($tempDir . '/telegram', 0700, true);
mkdir($tempDir . '/games', 0700, true);

$replacements = [
    "'../state.js?v=27'" => "'./state.mjs'",
    "'../api/client.js?v=47'" => "'./api.mjs'",
    "'../components/toast.js?v=41'" => "'./components/toast.mjs'",
    "'../components/sheet.js?v=68'" => "'./components/sheet.mjs'",
    "'../router.js?v=27'" => "'./router.mjs'",
    "'../ui.js?v=89'" => "'./ui.mjs'",
    "'../config.js?v=38'" => "'./config.mjs'",
    "'../telegram/telegram-app.js?v=27'" => "'./telegram/telegram-app.mjs'",
    "'../games/game-router-v102.js?v=102'" => "'./games/router.mjs'",
    "'../production-v97-models.js?v=97'" => "'./v97.mjs'",
    "'../production-v99-models.js?v=99'" => "'./v99.mjs'",
    "'../production-v100-optimistic-models.js?v=102'" => "'./optimistic.mjs'",
];
$source = str_replace(array_keys($replacements), array_values($replacements), $source);
$source .= "\nexport const __v102GameTestHooks = { buildOptimisticSurrender, coalesceReplaceableAction };\n";
file_put_contents($tempDir . '/game.mjs', $source);
file_put_contents($tempDir . '/state.mjs', "export const state = { timers:{}, user:null, activeGame:null };\n");
file_put_contents($tempDir . '/api.mjs', "export const api = {};\n");
file_put_contents($tempDir . '/components/toast.mjs', "export const toast = () => null;\n");
file_put_contents($tempDir . '/components/sheet.mjs', "export const openSheet = () => null; export const closeSheet = () => null;\n");
file_put_contents($tempDir . '/router.mjs', "export const showScreen = () => null;\n");
file_put_contents($tempDir . '/ui.mjs', "export const clearTimer = () => null; export const renderBalances = () => null;\n");
file_put_contents($tempDir . '/config.mjs', "export const APP_CONFIG = { gameIntervalMs:800, matchBet:10 };\n");
file_put_contents($tempDir . '/telegram/telegram-app.mjs', "export const haptic = () => null;\n");
file_put_contents($tempDir . '/games/router.mjs', <<<'JS'
export const gameMetaText = () => '';
export const gameStatusText = () => '';
export const gameTypeOf = game => String(game?.game_type || 'tictactoe');
export const playerMarkText = () => '';
export const renderGameSurface = () => null;
JS);
file_put_contents($tempDir . '/v97.mjs', "export const gameSurfaceFingerprint = () => 'fp';\n");
file_put_contents($tempDir . '/v99.mjs', "export const pollResultIsCurrent = () => true;\n");
file_put_contents($tempDir . '/optimistic.mjs', <<<'JS'
export const buildV100OptimisticGame = () => null;
export const invalidateInFlightPoll = () => true;
export const pendingSurfaceDescriptor = () => null;
JS);

file_put_contents($tempDir . '/test.mjs', <<<'JS'
globalThis.window = {
  __MGW_V100_GAME_RUNTIME__:{ initialized:false, games:new Map(), pointerHoldUntil:0, resultOpened:new Set(), weeklyNotified:new Set() },
  setTimeout, clearTimeout, setInterval, clearInterval, requestAnimationFrame:callback => callback(),
};
globalThis.document = { visibilityState:'visible' };

let assertions = 0;
function assert(condition, message){ assertions++; if (!condition) throw new Error(message); }

const { __v102GameTestHooks } = await import('./game.mjs');
const { buildOptimisticSurrender, coalesceReplaceableAction } = __v102GameTestHooks;

const source = {
  id:'g1', status:'active', game_type:'battleship', winner_id:null, loser_id:null,
  players:[{id:'me',name:'Я'},{id:'other',name:'Соперник'}],
};
const surrendered = buildOptimisticSurrender(source, 'me');
assert(source.status === 'active' && source.winner_id === null, 'Optimistic surrender must not mutate the authoritative source object.');
assert(surrendered.status === 'finished', 'Optimistic surrender must react immediately with a finished local state.');
assert(surrendered.winner_id === 'other' && surrendered.loser_id === 'me', 'Voluntary surrender must assign the opponent as winner and viewer as loser.');
assert(surrendered.finish_reason === 'player_left' && surrendered.time_left === 0, 'Voluntary surrender must use the existing player_left finish contract.');

const running = {
  running:true,
  queue:[
    {action:{type:'randomize_fleet',ships:['running']}},
    {action:{type:'randomize_fleet',ships:['old-pending']}},
  ],
};
coalesceReplaceableAction(running, {type:'randomize_fleet'}, 'battleship', {phase:'setup'});
assert(running.queue.length === 1 && running.queue[0].action.ships[0] === 'running', 'While a shuffle is running, only stale queued shuffles may be removed.');

const idle = {
  running:false,
  queue:[
    {action:{type:'randomize_fleet'}},
    {action:{type:'randomize_fleet'}},
  ],
};
coalesceReplaceableAction(idle, {type:'randomize_fleet'}, 'battleship', {phase:'setup'});
assert(idle.queue.length === 0, 'Before enqueueing the latest shuffle, every older pending shuffle must be removed.');

const unrelated = { running:false, queue:[{action:{type:'move'}}] };
coalesceReplaceableAction(unrelated, {type:'move'}, 'chess', {phase:'battle'});
assert(unrelated.queue.length === 1, 'Queue coalescing must not alter any non-Battleship action.');

console.log(`ProductionV102GameRuntimeModelsTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach (glob($tempDir . '/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/components/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/telegram/*.mjs') ?: [] as $file) @unlink($file);
foreach (glob($tempDir . '/games/*.mjs') ?: [] as $file) @unlink($file);
@rmdir($tempDir . '/components');
@rmdir($tempDir . '/telegram');
@rmdir($tempDir . '/games');
@rmdir($tempDir);
if ($exitCode !== 0) throw new RuntimeException("V102 game runtime model test failed:\n" . implode("\n", $output));
fwrite(STDOUT, implode("\n", $output) . "\n");
