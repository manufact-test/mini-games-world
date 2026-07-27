<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v101-poll-tuning.js');
if (!is_string($source)) throw new RuntimeException('Cannot read v101 poll tuning source.');

$tempDir = sys_get_temp_dir() . '/mgw_v101_poll_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
file_put_contents($tempDir . '/poll.mjs', str_replace("'./config.js?v=38'", "'./config.mjs'", $source));
file_put_contents($tempDir . '/config.mjs', "export const APP_CONFIG = { searchIntervalMs:2500, gameIntervalMs:1500 };\n");
file_put_contents($tempDir . '/test.mjs', <<<'JS'
import { APP_CONFIG } from './config.mjs';
import { initV101PollTuning } from './poll.mjs';

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}

initV101PollTuning();
assert(APP_CONFIG.searchIntervalMs === 900, 'Matchmaking polling must reduce the former 2.5 second window to 0.9 seconds.');
assert(APP_CONFIG.gameIntervalMs === 800, 'Active-game polling must reduce the former 1.5 second window to 0.8 seconds.');

APP_CONFIG.searchIntervalMs = 500;
APP_CONFIG.gameIntervalMs = 600;
initV101PollTuning();
assert(APP_CONFIG.searchIntervalMs === 500 && APP_CONFIG.gameIntervalMs === 600, 'Repeated initialization must not overwrite an already faster runtime.');

console.log(`ProductionV101PollTuningRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach ([$tempDir . '/test.mjs', $tempDir . '/poll.mjs', $tempDir . '/config.mjs'] as $file) @unlink($file);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V101 poll tuning test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
