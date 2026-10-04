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
const index = read('app/index.html');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const phaseEntry = read('app/assets/js/phase-b-current-entry.js');
const phaseRuntime = read('app/assets/js/phase-b-current-runtime.js');
const phaseScreen = read('app/assets/js/screens/game-screen-phase-b-current.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy top-level pair with one canonical bootstrap');
assert.ok(v110.includes("substr_count($html, '<script type=\"module\" src=\"') !== 1"),
  'v110 must continue enforcing one rendered top-level module bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);

for (const [label, source] of [
  ['index',index],
  ['v110 entry',v110],
  ['version manifest',manifest],
  ['bootstrap core',bootstrapCore],
  ['v110 clean wrapper',cleanWrapper],
  ['v110 clean polish',cleanPolish],
  ['v110 clean entry',cleanV110],
  ['v110 handoff shell',handoff],
]) {
  assert.ok(!source.includes('phase-b-current-entry.js'), `${label} must not load the historical Phase-B entry`);
  assert.ok(!source.includes('phase-b-current-runtime.js'), `${label} must not load the historical Phase-B runtime`);
  assert.ok(!source.includes('game-screen-phase-b-current.js'), `${label} must not load the historical Phase-B game screen`);
}

assert.deepEqual(refsTo('phase-b-current-entry.js'), [],
  'No app JS owner may import the historical Phase-B top-level entry');
assert.deepEqual(refsTo('phase-b-current-runtime.js'), ['app/assets/js/phase-b-current-entry.js'],
  'Phase-B runtime must be referenced only by its historical top-level entry');
assert.deepEqual(refsTo('game-screen-phase-b-current.js'), ['app/assets/js/phase-b-current-runtime.js'],
  'Phase-B game screen must be referenced only by the historical Phase-B runtime');

assert.ok(phaseEntry.includes("import { initPhaseBCurrentRuntime } from './phase-b-current-runtime.js"),
  'Historical Phase-B entry must retain runtime linkage as forensic evidence');
assert.ok(phaseEntry.includes('initPhaseBCurrentRuntime();'),
  'Historical Phase-B entry must retain runtime initialization evidence');
assert.ok(phaseRuntime.includes("from './screens/game-screen-phase-b-current.js"),
  'Historical Phase-B runtime must retain game-screen linkage as forensic evidence');

assert.equal(countCyrillicLines(phaseEntry), 0, 'Phase-B entry Cyrillic count changed');
assert.equal(countCyrillicLines(phaseRuntime), 17, 'Phase-B runtime Cyrillic count changed; re-prove classification');
assert.equal(countCyrillicLines(phaseScreen), 32, 'Phase-B screen Cyrillic count changed; re-prove classification');

for (const file of [
  'app/assets/js/phase-b-current-entry.js',
  'app/assets/js/phase-b-current-runtime.js',
  'app/assets/js/screens/game-screen-phase-b-current.js',
]) {
  assert.ok(audit.includes(`'${file}'`), `Hardcoded-text audit must classify ${file}`);
}

assert.ok(Number(baseline.scanned_files) <= 749, 'Phase-B classification successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2523, 'Phase-B classification successor total debt must not exceed the accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 870, 'Phase-B classification successor client debt must not exceed the accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 Phase-B current reachability: OK — entry/runtime/game-screen form a closed historical lineage absent from factual v110 ownership.');
