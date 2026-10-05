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
const legacyHistory = read('app/assets/js/production-v102-history-controller.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

const historicalEntries = [
  'app/assets/js/production-clean-entry-v102.js',
  'app/assets/js/production-clean-entry-v103.js',
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
  'app/assets/js/production-clean-entry-v109.js',
].map(path => [path, read(path)]);

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
  'Accepted wrapper must retain the v110 polish chain');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted v110 polish owner must retain production-clean-entry-v110');

for (const [label,source] of [
  ['version manifest',manifest],
  ['bootstrap core',bootstrapCore],
  ['v110 clean wrapper',cleanWrapper],
  ['v110 clean polish',cleanPolish],
  ['v110 clean entry',cleanV110],
  ['v110 handoff shell',handoff],
]) {
  assert.ok(!source.includes('production-v102-history-controller.js'),
    `${label} must not import or map the historical v102 history controller`);
  assert.ok(!source.includes('initV102HistoryController'),
    `${label} must not initialize the historical v102 history controller`);
}

for (const [path,source] of historicalEntries) {
  assert.ok(source.includes("production-v102-history-controller.js?v=102"),
    `${path} must retain the historical v102 history-controller import as forensic evidence`);
  assert.ok(source.includes('initV102HistoryController();'),
    `${path} must retain historical v102 history-controller initialization evidence`);
}

assert.equal(countCyrillicLines(legacyHistory), 33,
  'Historical v102 history-controller Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v102-history-controller.js'"),
  'Hardcoded-text audit must classify the proven shadowed v102 history controller');

assert.ok(Number(baseline.scanned_files) <= 752, 'v102 history classification successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2572, 'v102 history classification successor total debt must not exceed the accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 919, 'v102 history classification successor client debt must not exceed the accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v102 history-controller reachability: OK — historical clean-entry lineages retain it, while factual v110 ownership does not load it.');
