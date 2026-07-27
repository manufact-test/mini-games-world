<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v101-speed-models.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v101 speed models.');

$tempDir = sys_get_temp_dir() . '/mgw_v101_speed_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Cannot create v101 speed test directory.');
}
$module = $tempDir . '/models.mjs';
$test = $tempDir . '/test.mjs';
file_put_contents($module, $source);
file_put_contents($test, <<<'JS'
import {
  cacheDisposition,
  mergeNotificationSnapshot,
  optimisticReadNotifications,
  requestPriority,
  inviteContextKey,
  stableHash,
} from './models.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

assert(cacheDisposition(100, 500, 1000) === 'fresh', 'Fresh cache must render immediately.');
assert(cacheDisposition(700, 500, 1000) === 'stale', 'Stale cache must render while refreshing.');
assert(cacheDisposition(1500, 500, 1000) === 'miss', 'Expired cache must wait for current data.');

const merged = mergeNotificationSnapshot(
  { items:[{id:'old', title:'Old'}, {id:'same', title:'Before'}], unread_count:1 },
  { id:'same', title:'After' },
  2,
);
assert(merged.items.length === 2 && merged.items[0].title === 'After', 'A live invite event must replace the cached duplicate at the front.');
assert(merged.unread_count === 2, 'Live unread count must update the cached notification sheet.');

const read = optimisticReadNotifications(merged);
assert(read.unread_count === 0 && read.items.every(item => item.read === true), 'Opening cached notifications must mark the local sheet read immediately.');
assert(merged.unread_count === 2, 'Optimistic read must not mutate the source snapshot.');

assert(requestPriority('/bot/api.php', 'game_action') === 'high', 'Game actions must outrank background reads.');
assert(requestPriority('/bot/api.php', 'stats') === 'low', 'Stats must remain a low-priority background read.');
assert(requestPriority('/bot/notifications.php', '', true) === 'high', 'Explicit notification opening must be high priority.');

assert(inviteContextKey({gameType:'go', room:'gold', boardSize:13, bet:50}) === 'go:gold:13:50', 'Prepared shares must be keyed by exact visible conditions.');
assert(stableHash('same-user') === stableHash('same-user') && stableHash('same-user') !== stableHash('other-user'), 'Cache scope hash must be deterministic and account-specific.');

console.log(`ProductionV101SpeedModelsRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($test) . ' 2>&1', $output, $exitCode);
@unlink($test);
@unlink($module);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V101 speed model test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
