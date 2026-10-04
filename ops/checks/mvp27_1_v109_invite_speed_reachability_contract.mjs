import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const historicalV108Entry = read('app/assets/js/production-clean-entry-v108.js');
const historicalV109Entry = read('app/assets/js/production-clean-entry-v109.js');
const legacyV109 = read('app/assets/js/production-v109-invite-speed.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.match(manifest, /'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/,
  'Manifest must retain the accepted v110 clean-entry wrapper');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must retain the v110 polish chain');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted v110 polish owner must retain production-clean-entry-v110');
assert.ok(handoff.includes("from './games/game-invites-v110.js"),
  'Factual handoff shell must retain canonical game-invites-v110 ownership');

for (const [label,source] of [
  ['version manifest', manifest],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['v110 handoff shell', handoff],
  ['canonical game invites', activeInvites],
  ['historical v108 clean entry', historicalV108Entry],
]) {
  assert.ok(!source.includes('production-v109-invite-speed.js'),
    `${label} must not import the historical v109 invite-speed owner`);
  assert.ok(!source.includes('initV109InviteSpeed'),
    `${label} must not initialize the historical v109 invite-speed owner`);
}

assert.ok(historicalV109Entry.includes("production-v109-invite-speed.js?v=109"),
  'Historical v109 clean entry must retain the v109 invite-speed import as forensic evidence');
assert.ok(historicalV109Entry.includes('initV109InviteSpeed();'),
  'Historical v109 clean entry must retain v109 invite-speed initialization evidence');

assert.equal(countCyrillicLines(legacyV109), 34,
  'Historical v109 invite-speed Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v109-invite-speed.js'"),
  'Hardcoded-text audit must classify the proven shadowed v109 invite-speed owner');

assert.ok(Number(baseline.scanned_files) <= 754, 'v109 classification successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2639, 'v109 classification successor total debt must not exceed the accepted v109 ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 986, 'v109 classification successor client debt must not exceed the accepted v109 ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v109 invite-speed reachability: OK — v109 invite-speed is confined to the historical v109 clean-entry and absent from factual v110 ownership.');
