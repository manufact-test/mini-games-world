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
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const historicalV97Entry = read('app/assets/js/production-regression-fix-entry-v97.js');
const historicalV98Entry = read('app/assets/js/production-regression-fix-entry-v98.js');
const legacyPreparedShare = read('app/assets/js/production-prepared-share-fix.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'v110 must retain the legacy regression entry anchor before canonical replacement');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy regression/main pair with one canonical bootstrap');
assert.ok(v110.includes("substr_count($html, '<script type=\"module\" src=\"') !== 1"),
  'v110 must continue enforcing exactly one rendered top-level module bootstrap');

assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest, /'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/,
  'Manifest must retain the accepted v110 clean-entry wrapper');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must retain the v110 polish chain');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted v110 polish owner must retain production-clean-entry-v110');

for (const [label, source] of [
  ['version manifest', manifest],
  ['bootstrap core', bootstrapCore],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['v110 handoff shell', handoff],
]) {
  assert.ok(!source.includes('production-prepared-share-fix.js'),
    `${label} must not import or map the historical prepared-share owner`);
  assert.ok(!source.includes('initPreparedShareFix'),
    `${label} must not initialize the historical prepared-share owner`);
}

for (const [label, source] of [
  ['historical v97 regression entry', historicalV97Entry],
  ['historical v98 regression entry', historicalV98Entry],
]) {
  assert.ok(source.includes("production-prepared-share-fix.js?v=93"),
    `${label} must retain the historical prepared-share import as forensic evidence`);
  assert.ok(source.includes('initPreparedShareFix();'),
    `${label} must retain prepared-share initialization evidence`);
}

assert.deepEqual(
  refsTo('production-prepared-share-fix.js'),
  ['app/assets/js/production-regression-fix-entry-v97.js', 'app/assets/js/production-regression-fix-entry-v98.js'],
  'prepared-share owner must remain confined to historical v97/v98 regression entries'
);

assert.equal(countCyrillicLines(legacyPreparedShare), 31,
  'Historical prepared-share Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-prepared-share-fix.js'"),
  'Hardcoded-text audit must classify the proven shadowed prepared-share owner');

assert.ok(Number(baseline.scanned_files) <= 738, 'prepared-share successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2335, 'prepared-share successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 682, 'prepared-share successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 prepared-share reachability: OK — legacy prepared-share owner is confined to historical v97/v98 regression entries and absent from factual v110 ownership.');
