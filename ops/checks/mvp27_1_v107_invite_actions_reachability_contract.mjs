import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;
const normalized = p => p.split(path.sep).join('/');

function collectJs(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectJs(full, out);
    else if (entry.isFile() && entry.name.endsWith('.js')) out.push(normalized(full));
  }
  return out;
}

function refsTo(needle) {
  return collectJs('app/assets/js')
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const historicalV107Entry = read('app/assets/js/production-clean-entry-v107.js');
const historicalV108Entry = read('app/assets/js/production-clean-entry-v108.js');
const historicalV109Entry = read('app/assets/js/production-clean-entry-v109.js');
const legacyV107 = read('app/assets/js/production-v107-invite-actions.js');
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
  ['historical v109 clean entry', historicalV109Entry],
]) {
  assert.ok(!source.includes('production-v107-invite-actions.js'),
    `${label} must not import the historical v107 invite-actions owner`);
  assert.ok(!source.includes('initV107InviteActions'),
    `${label} must not initialize the historical v107 invite-actions owner`);
}

for (const [label,source] of [
  ['historical v107 clean entry', historicalV107Entry],
  ['historical v108 clean entry', historicalV108Entry],
]) {
  assert.ok(source.includes("production-v107-invite-actions.js?v=107"),
    `${label} must retain the v107 import as forensic evidence`);
  assert.ok(source.includes('initV107InviteActions();'),
    `${label} must retain v107 initialization evidence`);
}

assert.deepEqual(
  refsTo('production-v107-invite-actions.js'),
  ['app/assets/js/production-clean-entry-v107.js', 'app/assets/js/production-clean-entry-v108.js'],
  'v107 invite-actions must remain confined to historical v107/v108 clean-entry owners'
);

assert.equal(countCyrillicLines(legacyV107), 26,
  'Historical v107 invite-actions Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v107-invite-actions.js'"),
  'Hardcoded-text audit must classify the proven shadowed v107 invite owner');

assert.ok(Number(baseline.scanned_files) <= 739, 'v107 classification successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2366, 'v107 classification successor total debt must not exceed accepted v107 ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 713, 'v107 classification successor client debt must not exceed accepted v107 ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v107 invite-actions reachability: OK — v107/v108 historical owner is absent from factual v110 canonical invite ownership.');
