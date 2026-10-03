import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const v120 = read('app/v120.php');
const manifest = read('app/runtime/client/version-manifest.php');
const mainShell = read('app/assets/js/main-v110-handoff-shell.js');
const main110 = read('app/assets/js/main-v110.js');
const cleanEntry = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const rejectedController = read('app/assets/js/games/invite-controller-v120.js');
const legacyInvites = read('app/assets/js/games/game-invites.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes("'@mgw/clean-entry'") && v110.includes("'@mgw/main'"), 'v110 bootstrap owners missing');
assert.ok(v120.includes('v120 failed production acceptance and must never execute again'), 'v120 rejection marker missing');
assert.ok(v120.includes("header('Location: ' . $target, true, 302);"), 'v120 must redirect instead of booting rejected controller');

assert.ok(manifest.includes('game-invites-v110.js'), 'Active manifest must retain the v110 invite owner');
assert.ok(!manifest.includes("'./assets/js/games/game-invites.js"), 'Legacy game-invites.js must not be a manifest owner');
assert.ok(!manifest.includes('invite-controller-v120.js'), 'Rejected v120 controller must not be a manifest owner');

for (const [label,source] of [
  ['main-v110 shell', mainShell],
  ['main-v110 compatibility entry', main110],
  ['clean entry', cleanEntry],
  ['active invite owner', activeInvites],
]) {
  assert.ok(!source.includes('invite-controller-v120.js'), `${label} must not import rejected v120 controller`);
  assert.ok(!source.includes('./games/game-invites.js') && !source.includes('./game-invites.js'),
    `${label} must not import superseded game-invites.js`);
}
assert.ok(mainShell.includes('./games/game-invites-v110.js'), 'Factual main shell must import v110 invite owner');

assert.equal(countCyrillicLines(rejectedController), 101, 'Rejected v120 controller evidence count changed');
assert.equal(countCyrillicLines(legacyInvites), 91, 'Superseded game-invites evidence count changed');
assert.ok(audit.includes("'app/assets/js/games/invite-controller-v120.js'"), 'Audit must classify rejected v120 invite owner');
assert.ok(audit.includes("'app/assets/js/games/game-invites.js'"), 'Audit must classify superseded invite owner');

assert.equal(Number(baseline.cyrillic_lines_total), 2857, 'Invite classification total debt baseline changed unexpectedly');
assert.equal(Number(baseline.by_scope?.client), 1204, 'Invite classification client debt baseline changed unexpectedly');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must stay unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must stay zero');

console.log('MVP-27.1 invite-owner debt classification: OK — 192 Cyrillic lines belong to two proven shadowed/rejected invite owners, not the factual v110 player graph.');
