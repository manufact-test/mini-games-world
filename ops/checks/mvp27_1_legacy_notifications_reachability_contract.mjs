import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

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

function refsTo(needle) {
  return collectJs('app/assets/js')
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const activeMain = read('app/assets/js/main-v110-handoff-shell.js');
const activeNotifications = read('app/assets/js/screens/notifications-screen-v110r13.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const historicalV109Clean = read('app/assets/js/production-clean-entry-v109.js');
const historicalMain = read('app/assets/js/main.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

const legacy = new Map([
  ['app/assets/js/production-v109-notifications.js', 21],
  ['app/assets/js/screens/notifications-screen.js', 24],
  ['app/assets/js/screens/notifications-screen-v99.js', 21],
  ['app/assets/js/screens/notifications-screen-v110-root.js', 25],
  ['app/assets/js/screens/notifications-screen-v110.js', 25],
  ['app/assets/js/screens/notifications-screen-v110r4.js', 25],
  ['app/assets/js/screens/notifications-screen-v110r5.js', 24],
  ['app/assets/js/screens/notifications-screen-v110r12.js', 27],
]);

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must keep stripping the legacy regression/main entry pair in favor of canonical bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(activeMain.includes("./screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Factual v110 handoff must retain r13 notification-screen ownership');
assert.ok(manifest.includes("./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Version manifest must retain active r13 notification mapping');
assert.ok(activeNotifications.includes("from '@mgw/i18n'"),
  'Active r13 notifications owner must retain canonical localization dependency');
assert.equal(countCyrillicLines(activeNotifications), 0,
  'Active r13 notifications owner must remain free of hardcoded Cyrillic player copy');

for (const [file, expectedCyrillic] of legacy) {
  const source = read(file);
  assert.equal(countCyrillicLines(source), expectedCyrillic,
    `${file} Cyrillic evidence count changed; re-prove legacy classification before changing debt`);
  assert.ok(audit.includes(`'${file}'`), `Hardcoded-text audit must classify ${file}`);
}

const activeOwners = new Map([
  ['version manifest', manifest],
  ['bootstrap core', bootstrapCore],
  ['factual v110 handoff', activeMain],
  ['factual v110 clean entry', cleanV110],
  ['active r13 notifications', activeNotifications],
]);
for (const [label, source] of activeOwners) {
  for (const file of legacy.keys()) {
    assert.ok(!source.includes(path.basename(file)),
      `${label} must not import/map historical notification owner ${file}`);
  }
}

assert.ok(historicalMain.includes("./screens/notifications-screen-v99.js?v=d1-bell-single-owner"),
  'Stripped legacy main.js must retain historical v99 notification ownership evidence');
assert.ok(historicalV109Clean.includes("./production-v109-notifications.js?v=109"),
  'Historical clean-entry-v109 must retain production-v109 notification ownership evidence');

const allowedHistoricalRoots = new Set([
  'app/assets/js/main.js',
  'app/assets/js/main-v99.js',
  'app/assets/js/main-v100.js',
  'app/assets/js/main-v101.js',
  'app/assets/js/main-v102.js',
  'app/assets/js/main-v103.js',
  'app/assets/js/main-v104.js',
  'app/assets/js/main-v105.js',
  'app/assets/js/main-v106.js',
  'app/assets/js/main-v107.js',
  'app/assets/js/main-v108.js',
  'app/assets/js/main-v109.js',
  'app/assets/js/production-clean-entry-v109.js',
]);
const legacyFiles = new Set(legacy.keys());

for (const file of legacy.keys()) {
  const refs = refsTo(path.basename(file));
  const unexpected = refs.filter(ref => !allowedHistoricalRoots.has(ref) && !legacyFiles.has(ref));
  assert.deepEqual(unexpected, [],
    `${file} has a non-historical app-JS owner: ${unexpected.join(', ')}`);
}

assert.deepEqual(
  refsTo('production-v109-notifications.js'),
  ['app/assets/js/production-clean-entry-v109.js'],
  'production-v109-notifications must remain confined to historical clean-entry-v109'
);
assert.deepEqual(
  refsTo('notifications-screen-v99.js'),
  ['app/assets/js/main.js'],
  'notifications-screen-v99 must remain confined to stripped legacy main.js'
);
assert.deepEqual(
  refsTo('notifications-screen.js'),
  [
    'app/assets/js/main-v100.js',
    'app/assets/js/main-v101.js',
    'app/assets/js/main-v102.js',
    'app/assets/js/main-v103.js',
    'app/assets/js/main-v104.js',
    'app/assets/js/main-v105.js',
    'app/assets/js/main-v99.js',
  ],
  'base legacy notifications screen must remain confined to historical main-v99..v105'
);

assert.ok(Number(baseline.scanned_files) <= 728, 'legacy notifications successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2088, 'legacy notifications successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 435, 'legacy notifications successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy notifications reachability: OK — active localized r13 owner is isolated from the historical notification lineage.');
