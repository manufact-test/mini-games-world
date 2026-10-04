import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2.js');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const reconnectMain = read('app/assets/js/main-v110-reconnect-v174.js');
const main110 = read('app/assets/js/main-v110.js');
const handoffShell = read('app/assets/js/main-v110-handoff-shell.js');
const cleanEntry = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanEntryBase = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const legacyMain = read('app/assets/js/main.js');
const residual = read('app/assets/js/residual-ui-game-race-fix.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'v110 must still identify the stripped legacy regression entry');
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must still identify the stripped legacy main entry');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace both legacy entry tags with one canonical bootstrap tag');
assert.match(manifest, /'bootstrap'\s*=>\s*'\.\/assets\/js\/app-bootstrap-v2\.js[^']*'/,
  'Version manifest must retain app-bootstrap-v2 as the canonical bootstrap asset');

assert.ok(bootstrap.includes("await import('./app-bootstrap-v2-core.js"),
  'Canonical bootstrap wrapper must delegate to app-bootstrap-v2-core');
const coreImports = [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]);
assert.deepEqual(coreImports, ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only the canonical clean-entry and main owners');

assert.match(manifest, /'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/,
  'Manifest must retain the accepted clean-entry wrapper');
assert.match(manifest, /'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/,
  'Manifest must retain the accepted v110 reconnect main owner');
assert.ok(reconnectMain.includes("import './main-v110.js"), 'Reconnect owner must delegate to main-v110');
assert.ok(main110.includes("import './main-v110-handoff-shell.js"), 'main-v110 must delegate to the factual handoff shell');

for (const [label, source] of [
  ['bootstrap wrapper', bootstrap],
  ['bootstrap core', bootstrapCore],
  ['version manifest', manifest],
  ['reconnect main', reconnectMain],
  ['main-v110', main110],
  ['handoff shell', handoffShell],
  ['clean-entry wrapper', cleanEntry],
  ['clean-entry base', cleanEntryBase],
]) {
  assert.ok(!source.includes('residual-ui-game-race-fix.js'),
    `${label} must not import or map the legacy residual hotfix`);
}

assert.ok(legacyMain.includes("from './residual-ui-game-race-fix.js?v=91'"),
  'Historical main.js must retain the residual hotfix import as forensic evidence');
assert.ok(legacyMain.includes('initResidualUiGameRaceFixEarly();')
    && legacyMain.includes('initResidualUiGameRaceFixAfter();'),
  'Historical main.js must retain both residual initialization calls as forensic evidence');
assert.equal(countCyrillicLines(residual), 82,
  'Residual hotfix Cyrillic evidence count changed; reclassify reachability before changing debt');
assert.ok(audit.includes("'app/assets/js/residual-ui-game-race-fix.js'"),
  'Hardcoded-text audit must classify the proven shadowed residual owner');

assert.ok(Number(baseline.scanned_files) <= 757, 'Residual classification successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2745, 'Residual classification successor debt must not regress above the accepted #1964 total ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 1092, 'Residual classification successor client debt must not regress above the accepted #1964 ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 residual UI reachability: OK — legacy main.js is stripped by v110; residual-ui-game-race-fix.js is not reachable from the factual bootstrap/import graph.');
