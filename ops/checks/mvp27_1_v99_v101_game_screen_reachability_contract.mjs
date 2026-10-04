import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;
const normalized = p => p.split(path.sep).join('/');

function collectSources(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectSources(full, out);
    else if (entry.isFile() && (entry.name.endsWith('.js') || entry.name.endsWith('.php'))) out.push(normalized(full));
  }
  return out;
}

function refsTo(needle) {
  return collectSources('app')
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const index = read('app/index.html');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');

const v99Page = read('app/v99.php');
const v100Page = read('app/v100.php');
const v101Page = read('app/v101.php');
const main99 = read('app/assets/js/main-v99.js');
const main100 = read('app/assets/js/main-v100.js');
const main101 = read('app/assets/js/main-v101.js');
const screen99 = read('app/assets/js/screens/game-screen-v99.js');
const screen100Safe = read('app/assets/js/screens/game-screen-v100-safe.js');
const screen100 = read('app/assets/js/screens/game-screen-v100.js');

const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy top-level pair with one canonical bootstrap');
assert.ok(v110.includes("substr_count($html, '<script type=\\\"module\\\" src=\\\"') !== 1"),
  'v110 must continue enforcing one rendered top-level module bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);

const legacyNames = [
  'main-v99.js',
  'main-v100.js',
  'main-v101.js',
  'game-screen-v99.js',
  'game-screen-v100-safe.js',
  'game-screen-v100.js',
];
for (const [label, source] of [
  ['index', index],
  ['v110 entry', v110],
  ['version manifest', manifest],
  ['bootstrap core', bootstrapCore],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['v110 handoff shell', handoff],
]) {
  for (const legacyName of legacyNames) {
    assert.ok(!source.includes(legacyName), `${label} must not load historical ${legacyName}`);
  }
}

assert.ok(v99Page.includes('./assets/js/main-v99.js?v=99'), 'Historical v99 page must retain main-v99 ownership evidence');
assert.ok(v100Page.includes('./assets/js/main-v100.js?v=100'), 'Historical v100 page must retain main-v100 ownership evidence');
assert.ok(v101Page.includes('./assets/js/main-v101.js?v=101'), 'Historical v101 page must retain main-v101 ownership evidence');

assert.ok(main99.includes("./screens/game-screen-v99.js?v=99"), 'Historical main-v99 must retain game-screen-v99 linkage');
assert.ok(main100.includes("./screens/game-screen-v100-safe.js?v=100"), 'Historical main-v100 must retain v100-safe linkage');
assert.ok(main101.includes("./screens/game-screen-v100-safe.js?v=100"), 'Historical main-v101 must retain v100-safe linkage');
assert.ok(screen100Safe.includes("./game-screen-v100.js?v=100"), 'Historical v100-safe wrapper must retain game-screen-v100 linkage');

assert.deepEqual(refsTo('main-v99.js'), ['app/v99.php'], 'main-v99 must be owned only by historical v99 page');
assert.deepEqual(refsTo('main-v100.js'), ['app/v100.php'], 'main-v100 must be owned only by historical v100 page');
assert.deepEqual(refsTo('main-v101.js'), ['app/v101.php'], 'main-v101 must be owned only by historical v101 page');
assert.deepEqual(refsTo('game-screen-v99.js'), ['app/assets/js/main-v99.js'], 'game-screen-v99 must be owned only by historical main-v99');
assert.deepEqual(
  refsTo('game-screen-v100-safe.js'),
  ['app/assets/js/main-v100.js', 'app/assets/js/main-v101.js'],
  'v100-safe wrapper must be owned only by historical v100/v101 mains'
);
assert.deepEqual(
  refsTo('game-screen-v100.js'),
  ['app/assets/js/screens/game-screen-v100-safe.js'],
  'game-screen-v100 must be owned only by the historical v100-safe wrapper'
);

assert.equal(countCyrillicLines(main99), 1, 'main-v99 Cyrillic count changed');
assert.equal(countCyrillicLines(main100), 1, 'main-v100 Cyrillic count changed');
assert.equal(countCyrillicLines(main101), 1, 'main-v101 Cyrillic count changed');
assert.equal(countCyrillicLines(screen99), 32, 'game-screen-v99 Cyrillic count changed');
assert.equal(countCyrillicLines(screen100Safe), 0, 'game-screen-v100-safe Cyrillic count changed');
assert.equal(countCyrillicLines(screen100), 32, 'game-screen-v100 Cyrillic count changed');

for (const file of [
  'app/assets/js/main-v99.js',
  'app/assets/js/main-v100.js',
  'app/assets/js/main-v101.js',
  'app/assets/js/screens/game-screen-v99.js',
  'app/assets/js/screens/game-screen-v100-safe.js',
  'app/assets/js/screens/game-screen-v100.js',
]) {
  assert.ok(audit.includes(`'${file}'`), `Hardcoded-text audit must classify ${file}`);
}

assert.ok(Number(baseline.scanned_files) <= 743, 'V99-V101 classification successor must not restore historical files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2456, 'V99-V101 classification successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 803, 'V99-V101 classification successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v99-v101 game-screen reachability: OK — historical pages own a closed legacy main/game-screen lineage absent from factual v110 ownership.');
