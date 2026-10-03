import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const cyrillic = /[\u0400-\u04FF]/;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v120 = read('app/v120.php');
const config = read('app/runtime/server/RuntimeConfig.php');
const guard = read('app/runtime/server/RuntimeEnvironmentGuard.php');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Factual Telegram launch must remain on v110');
assert.ok(v120.includes('v120 failed production acceptance and must never execute again'), 'Rejected v120 tombstone marker missing');
assert.ok(v120.includes("header('Location: ' . $target, true, 302);"), 'Rejected v120 must redirect instead of booting clean runtime');
assert.ok(!v120.includes('runtime/index.php') && !v120.includes('runtime/api.php'), 'v120 tombstone must not enter clean runtime');

assert.ok(config.includes("if ($this->environment !== 'staging')"), 'Clean runtime constructor must remain staging-only');
assert.ok(config.includes("getenv('MGW_CLEAN_RUNTIME_ENV')"), 'Clean runtime must require its dedicated environment configuration');
assert.ok(config.includes("getenv('MGW_CLEAN_ALLOWED_HOSTS')"), 'Clean runtime must require an explicit host allowlist');
assert.ok(guard.includes("Clean runtime is disabled outside staging."), 'Clean runtime environment guard must reject non-staging use');
assert.ok(guard.includes('!in_array($requestHost, $config->allowedHosts, true)'), 'Clean runtime host allowlist guard missing');

let cleanRuntimeCyrillic = 0;
const walk = dir => {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.php')) {
      for (const line of read(full).split(/\r?\n/)) if (cyrillic.test(line)) cleanRuntimeCyrillic++;
    }
  }
};
walk('app/runtime/server');
assert.equal(cleanRuntimeCyrillic, 25, 'Expected classified clean-runtime Cyrillic evidence changed');

assert.ok(audit.includes("'app/runtime/server/'"), 'Player-facing debt audit must exclude the proven staging-only clean runtime server');
assert.ok(Number(baseline.cyrillic_lines_total) <= 3049, 'Classified total debt must not regress above accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 1396, 'Client debt must not regress above accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Active client-entry debt must be zero after classification');

console.log('MVP-27.1 client-entry debt classification: OK — 25 staging-only clean-runtime Cyrillic lines are proven outside the factual Telegram player graph.');
