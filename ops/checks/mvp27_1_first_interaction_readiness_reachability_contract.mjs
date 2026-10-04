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
    else if (entry.isFile() && ['.js','.php','.html'].includes(path.extname(entry.name))) out.push(normalized(full));
  }
  return out;
}

function refsTo(needle) {
  return collectSources('app')
    .filter(file => file !== 'app/assets/js/first-interaction-readiness.js')
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const index = read('app/index.html');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2.js');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const reconnectMain = read('app/assets/js/main-v110-reconnect-v174.js');
const main110 = read('app/assets/js/main-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const legacyMain = read('app/assets/js/main.js');
const historicalReadiness = read('app/assets/js/first-interaction-readiness.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must retain the legacy main entry anchor before canonical replacement');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy regression/main pair with one canonical bootstrap');
assert.ok(bootstrap.includes("await import('./app-bootstrap-v2-core.js"),
  'Canonical bootstrap wrapper must delegate to app-bootstrap-v2-core');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest, /'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/,
  'Manifest must retain the accepted reconnect main owner');
assert.ok(reconnectMain.includes("import './main-v110.js"), 'Reconnect owner must delegate to main-v110');
assert.ok(main110.includes("import './main-v110-handoff-shell.js"), 'main-v110 must delegate to factual handoff shell');

for (const [label,source] of [
  ['index', index],
  ['v110 entry', v110],
  ['version manifest', manifest],
  ['bootstrap wrapper', bootstrap],
  ['bootstrap core', bootstrapCore],
  ['reconnect main', reconnectMain],
  ['main-v110', main110],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', cleanV110],
  ['v110 handoff shell', handoff],
]) {
  assert.ok(!source.includes('first-interaction-readiness.js'),
    `${label} must not load the historical first-interaction readiness module`);
  assert.ok(!source.includes('initFirstInteractionReadinessEarly'),
    `${label} must not initialize the historical readiness module`);
  assert.ok(!source.includes('warmFirstInteractionData'),
    `${label} must not call the historical readiness module`);
}

assert.ok(legacyMain.includes("from './first-interaction-readiness.js?v=d1'"),
  'Legacy main.js must retain readiness import as forensic evidence');
assert.ok(legacyMain.includes('initFirstInteractionReadinessEarly();'),
  'Legacy main.js must retain readiness initialization as forensic evidence');
assert.deepEqual(refsTo('first-interaction-readiness.js'), ['app/assets/js/main.js'],
  'first-interaction-readiness.js must stay confined to stripped legacy main.js');
assert.deepEqual(refsTo('initFirstInteractionReadinessEarly'), ['app/assets/js/main.js'],
  'initFirstInteractionReadinessEarly must stay confined to stripped legacy main.js');
assert.deepEqual(refsTo('warmFirstInteractionData'), [],
  'warmFirstInteractionData must have no external app consumer');

assert.equal(countCyrillicLines(historicalReadiness), 30,
  'First-interaction readiness Cyrillic evidence count changed; re-prove classification before changing debt');
assert.ok(audit.includes("'app/assets/js/first-interaction-readiness.js'"),
  'Hardcoded-text audit must classify the proven shadowed readiness source');

assert.ok(Number(baseline.scanned_files) <= 737, 'readiness classification successor must not restore orphan runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2305, 'readiness classification successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 652, 'readiness classification successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 first-interaction readiness reachability: OK — source is confined to stripped legacy main.js and absent from factual v110 ownership.');
