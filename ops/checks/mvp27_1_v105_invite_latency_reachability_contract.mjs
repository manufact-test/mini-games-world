import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const historicalV105Entry = read('app/assets/js/production-clean-entry-v105.js');
const historicalV107Entry = read('app/assets/js/production-clean-entry-v107.js');
const historicalV108Entry = read('app/assets/js/production-clean-entry-v108.js');
const historicalV109Entry = read('app/assets/js/production-clean-entry-v109.js');
const legacyV105 = read('app/assets/js/production-v105-invite-latency.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain the single canonical bootstrap replacement');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest, /'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/,
  'Manifest must retain the accepted v110 clean-entry wrapper');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must delegate to the v110 polish owner');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted v110 polish owner must delegate to production-clean-entry-v110');
assert.ok(handoff.includes("from './games/game-invites-v110.js"),
  'Factual handoff shell must retain canonical game-invites-v110 ownership');

for (const [label,source] of [
  ['version manifest', manifest],
  ['bootstrap core', bootstrapCore],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['v110 handoff shell', handoff],
  ['canonical game invites', activeInvites],
]) {
  assert.ok(!source.includes('production-v105-invite-latency.js'),
    `${label} must not import or map the historical v105 invite-latency owner`);
  assert.ok(!source.includes('initV105InviteLatency'),
    `${label} must not initialize the historical v105 invite-latency owner`);
}

for (const [label,source] of [
  ['historical v105 clean entry', historicalV105Entry],
  ['historical v107 clean entry', historicalV107Entry],
  ['historical v108 clean entry', historicalV108Entry],
  ['historical v109 clean entry', historicalV109Entry],
]) {
  assert.ok(source.includes("production-v105-invite-latency.js?v=105"),
    `${label} must retain the historical v105 import as forensic evidence`);
  assert.ok(source.includes('initV105InviteLatency();'),
    `${label} must retain historical v105 initialization evidence`);
}

assert.equal(countCyrillicLines(legacyV105), 38,
  'Historical v105 invite-latency Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v105-invite-latency.js'"),
  'Hardcoded-text audit must classify the proven shadowed v105 invite owner');

assert.equal(Number(baseline.scanned_files), 756, 'v105 classification must remove exactly one scanned file');
assert.equal(Number(baseline.cyrillic_lines_total), 2707, 'v105 classification total debt must ratchet by exactly 38');
assert.equal(Number(baseline.by_scope?.client), 1054, 'v105 classification client debt must ratchet by exactly 38');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v105 invite-latency reachability: OK — the owner belongs to historical v105-v109 clean-entry lineages and is absent from factual v110 ownership.');
