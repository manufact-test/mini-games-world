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
const reconnectMain = read('app/assets/js/main-v110-reconnect-v174.js');
const main110 = read('app/assets/js/main-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');

const historicalEntries = new Map([
  ['app/assets/js/production-clean-entry-v104.js', read('app/assets/js/production-clean-entry-v104.js')],
  ['app/assets/js/production-clean-entry-v105-fast-notifications.js', read('app/assets/js/production-clean-entry-v105-fast-notifications.js')],
  ['app/assets/js/production-clean-entry-v105.js', read('app/assets/js/production-clean-entry-v105.js')],
  ['app/assets/js/production-clean-entry-v107.js', read('app/assets/js/production-clean-entry-v107.js')],
  ['app/assets/js/production-clean-entry-v108.js', read('app/assets/js/production-clean-entry-v108.js')],
  ['app/assets/js/production-clean-entry-v109.js', read('app/assets/js/production-clean-entry-v109.js')],
]);
const historicalV106Entry = read('app/assets/js/production-clean-entry-v106.js');
const legacyV104 = read('app/assets/js/production-v104-result-instant.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.match(manifest, /'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/,
  'Manifest must retain accepted v110 clean-entry ownership');
assert.match(manifest, /'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/,
  'Manifest must retain accepted v110 main ownership');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must retain v110 polish chain');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted polish owner must retain production-clean-entry-v110');
assert.ok(reconnectMain.includes("import './main-v110.js"), 'Reconnect owner must delegate to main-v110');
assert.ok(main110.includes("import './main-v110-handoff-shell.js"), 'main-v110 must delegate to factual handoff shell');

for (const [label,source] of [
  ['version manifest', manifest],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['reconnect main', reconnectMain],
  ['main-v110', main110],
  ['v110 handoff shell', handoff],
  ['historical v106 clean entry', historicalV106Entry],
]) {
  assert.ok(!source.includes('production-v104-result-instant.js'),
    `${label} must not import the historical v104 result-instant owner`);
  assert.ok(!source.includes('initV104ResultInstant'),
    `${label} must not initialize the historical v104 result-instant owner`);
}

for (const [label,source] of historicalEntries) {
  assert.ok(source.includes("production-v104-result-instant.js?v=104"),
    `${label} must retain v104 result-instant import as forensic evidence`);
  assert.ok(source.includes('initV104ResultInstant();'),
    `${label} must retain v104 result-instant initialization evidence`);
}

assert.deepEqual(
  refsTo('production-v104-result-instant.js'),
  [
    'app/assets/js/production-clean-entry-v104.js',
    'app/assets/js/production-clean-entry-v105-fast-notifications.js',
    'app/assets/js/production-clean-entry-v105.js',
    'app/assets/js/production-clean-entry-v107.js',
    'app/assets/js/production-clean-entry-v108.js',
    'app/assets/js/production-clean-entry-v109.js',
  ],
  'v104 result-instant must stay confined to the proven historical clean-entry owners'
);

assert.equal(countCyrillicLines(legacyV104), 22,
  'Historical v104 result-instant Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v104-result-instant.js'"),
  'Hardcoded-text audit must classify the proven shadowed v104 result-instant owner');

assert.ok(Number(baseline.scanned_files) <= 735, 'v104 result successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2258, 'v104 result successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 605, 'v104 result successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 v104 result-instant reachability: OK — historical result owner is absent from factual v110 ownership.');
