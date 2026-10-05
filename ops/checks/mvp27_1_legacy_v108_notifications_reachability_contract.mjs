import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/production-v108-notifications.js';
const HISTORICAL_OWNER = 'app/assets/js/production-clean-entry-v108.js';

const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collectSources(dir, out = []) {
  for (const item of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, item.name);
    if (item.isDirectory()) collectSources(full, out);
    else if (item.isFile() && ['.js','.php','.html'].includes(path.extname(item.name))) out.push(normalized(full));
  }
  return out;
}
const allSources = collectSources('app').sort();
function refsTo(needle, excluded = []) {
  const skip = new Set(excluded);
  return allSources.filter(file => !skip.has(file)).filter(file => read(file).includes(needle)).sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v108 = read('app/v108.php');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const historicalOwner = read(HISTORICAL_OWNER);
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeNotifications = read('app/assets/js/screens/notifications-screen-v110r13.js');
const target = read(TARGET);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v108.includes('./assets/js/production-clean-entry-v105-fast-notifications.js?v=1051'),
  'Historical v108 page must retain its accepted emergency-rollback clean entry');
assert.ok(!v108.includes('production-clean-entry-v108.js'),
  'Historical v108 page must not revive abandoned clean-entry-v108');
assert.deepEqual(refsTo('production-clean-entry-v108.js', [HISTORICAL_OWNER]), [],
  'production-clean-entry-v108 must remain ownerless historical source');

assert.ok(historicalOwner.includes("import { initV108Notifications } from './production-v108-notifications.js?v=108';"),
  'Historical clean-entry-v108 must retain v108 notifications import evidence');
assert.ok(historicalOwner.includes('initV108Notifications();'),
  'Historical clean-entry-v108 must retain v108 notifications initialization evidence');
assert.deepEqual(refsTo('production-v108-notifications.js', [TARGET]), [HISTORICAL_OWNER],
  'v108 notifications owner set changed; every owner must be historically classified before debt exclusion');
assert.deepEqual(refsTo('initV108Notifications', [TARGET]), [HISTORICAL_OWNER],
  'v108 notifications initializer owner set changed');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry scripts with canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(manifest.includes("'@mgw/clean-entry' => './assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js"),
  'Manifest must retain factual v110 clean-entry ownership');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must retain v110 polish delegation');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted clean-entry polish must retain v110 base delegation');
assert.ok(handoff.includes("./screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Factual v110 handoff must retain r13 notification-screen ownership');
assert.ok(manifest.includes("./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Version manifest must retain active r13 notification mapping');
assert.ok(activeNotifications.includes("from '@mgw/i18n'"),
  'Active r13 notifications owner must retain canonical localization dependency');
assert.equal(countCyrillicLines(activeNotifications), 0,
  'Active r13 notifications owner must remain free of hardcoded Cyrillic player copy');

for (const [label, source] of [
  ['manifest', manifest],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', clean110],
  ['v110 handoff', handoff],
  ['active r13 notifications', activeNotifications],
]) {
  assert.ok(!source.includes('production-v108-notifications.js'),
    label + ' must not load historical v108 notifications owner');
  assert.ok(!source.includes('initV108Notifications'),
    label + ' must not initialize historical v108 notifications owner');
}

assert.equal(countCyrillicLines(target), 11, 'Historical v108 notifications Cyrillic evidence count changed');
assert.ok(audit.includes("'app/assets/js/production-v108-notifications.js'"),
  'Audit must classify historical v108 notifications owner');

assert.ok(Number(baseline.scanned_files) <= 711, 'v108 notifications successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1828, 'v108 notifications successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 175, 'v108 notifications successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy v108 notifications reachability: OK — v108 notifications are confined to ownerless historical clean-entry-v108, while factual v110 retains localized r13 notification ownership.');
