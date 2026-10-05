import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const CANDIDATE = 'app/assets/js/screens/notification-window-owner-v119.js';
const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collectJs(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectJs(full, out);
    else if (entry.isFile() && entry.name.endsWith('.js')) out.push(normalized(full));
  }
  return out;
}

const jsFiles = collectJs('app/assets/js').sort();
function externalRefs(needle) {
  return jsFiles
    .filter(file => file !== CANDIDATE && read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const manifest = read('app/runtime/client/version-manifest.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeNotifications = read('app/assets/js/screens/notifications-screen-v110r13.js');
const candidate = read(CANDIDATE);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain canonical bootstrap replacement');
assert.ok(!v110.includes('notification-window-owner-v119.js'),
  'Factual v110 entry must not load legacy notification-window owner');
assert.ok(!manifest.includes('notification-window-owner-v119.js'),
  'Version manifest must not map legacy notification-window owner');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(handoff.includes("./screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Factual v110 handoff must retain localized r13 notification ownership');
assert.ok(manifest.includes("./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Version manifest must retain localized r13 notification mapping');
assert.ok(activeNotifications.includes("from '@mgw/i18n'"),
  'Active r13 notifications owner must retain canonical localization dependency');
assert.equal(countCyrillicLines(activeNotifications), 0,
  'Active r13 notifications owner must remain free of hardcoded Cyrillic player copy');

for (const [label, source] of [
  ['bootstrap core', bootstrapCore],
  ['factual v110 clean entry', cleanV110],
  ['factual v110 handoff', handoff],
  ['active r13 notifications', activeNotifications],
]) {
  assert.ok(!source.includes('notification-window-owner-v119.js'),
    `${label} must not import/load legacy v119 notification-window owner`);
}

assert.equal(countCyrillicLines(candidate), 18,
  'Notification-window v119 Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(candidate.includes('initNotificationWindowOwner();'),
  'Legacy v119 owner must retain self-initialization evidence');
assert.ok(candidate.includes('function initNotificationWindowOwner()'),
  'Legacy v119 owner must retain implementation evidence');

assert.deepEqual(externalRefs('notification-window-owner-v119.js'), [],
  'Legacy notification-window v119 acquired an external app-JS owner');

assert.ok(audit.includes("'app/assets/js/screens/notification-window-owner-v119.js'"),
  'Hardcoded-text audit must classify notification-window-owner-v119');

assert.ok(Number(baseline.scanned_files) <= 724, 'notification-window successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2007, 'notification-window successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 354, 'notification-window successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 notification-window-owner-v119 reachability: OK — legacy v119 owner has no external app-JS owner and is absent from factual localized v110 notification ownership.');
