<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/app/assets/js/production-v101-invite-sync-dedupe.js');
$models = file_get_contents($root . '/app/assets/js/production-v101-speed-models.js');
if (!is_string($source) || !is_string($models)) throw new RuntimeException('Cannot read v101 invite sync sources.');

$tempDir = sys_get_temp_dir() . '/mgw_v101_invite_sync_' . bin2hex(random_bytes(6));
mkdir($tempDir, 0700, true);
file_put_contents($tempDir . '/dedupe.mjs', str_replace(
    "'./production-v101-speed-models.js?v=101'",
    "'./models.mjs'",
    $source
));
file_put_contents($tempDir . '/models.mjs', $models);
file_put_contents($tempDir . '/test.mjs', <<<'JS'
globalThis.window = {
  location:{ origin:'https://mgw.test', href:'https://mgw.test/app/v101.php?v=101' },
};

let calls = 0;
window.fetch = async (input, init = {}) => {
  calls++;
  const body = JSON.parse(String(init.body || '{}'));
  await new Promise(resolve => setTimeout(resolve, 20));
  return new Response(JSON.stringify({ ok:true, call:calls, token:body.token || '', action:body.action || '' }), {
    status:200,
    headers:{'Content-Type':'application/json'},
  });
};

globalThis.fetch = (...args) => window.fetch(...args);
const { initV101InviteSyncDedupe } = await import('./dedupe.mjs');
initV101InviteSyncDedupe();

let assertions = 0;
function assert(condition, message){
  assertions++;
  if (!condition) throw new Error(message);
}
function request(action, token = ''){
  return window.fetch('https://mgw.test/bot/invites.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({ initData:'same-user', sessionId:'session-1', action, token }),
  }).then(response => response.json());
}

const [first, second] = await Promise.all([request('sync'), request('sync')]);
assert(calls === 1, 'Concurrent identical invite sync reads must share one underlying request.');
assert(first.ok === true && second.ok === true && first.call === second.call, 'Every deduplicated consumer must receive an independent equivalent response.');

await Promise.all([request('sync', 'one'), request('sync', 'two')]);
assert(calls === 3, 'Different tracked invite tokens must not be deduplicated together.');

await Promise.all([request('accept', 'one'), request('accept', 'one')]);
assert(calls === 5, 'Mutating invite actions must always pass through independently.');

console.log(`ProductionV101InviteSyncDedupeRuntimeTest: ${assertions} assertions passed`);
JS);

$output = [];
$exitCode = 0;
exec('node ' . escapeshellarg($tempDir . '/test.mjs') . ' 2>&1', $output, $exitCode);
foreach ([$tempDir . '/test.mjs', $tempDir . '/dedupe.mjs', $tempDir . '/models.mjs'] as $file) @unlink($file);
@rmdir($tempDir);
if ($exitCode !== 0) {
    throw new RuntimeException("V101 invite sync dedupe test failed:\n" . implode("\n", $output));
}
fwrite(STDOUT, implode("\n", $output) . "\n");
