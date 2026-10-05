import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const CANDIDATE = 'app/assets/js/production-cross-game-coordinator.js';
const LEGACY_ENTRY = 'app/assets/js/production-regression-fix-entry.js';
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
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const activeGameScreen = read('app/assets/js/screens/game-screen-v102-safe.js');
const candidate = read(CANDIDATE);
const legacyEntry = read(LEGACY_ENTRY);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'v110 legacy entry anchor must retain regression-entry evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must strip the legacy regression/main entry anchor in favor of canonical bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(manifest.includes("./assets/js/games/game-invites-v110.js?v=1150"),
  'Manifest must retain canonical v110 invite ownership');
assert.ok(manifest.includes("./assets/js/screens/game-screen-v102-safe.js?v=105"),
  'Manifest must retain canonical game-screen-v102-safe ownership');

for (const [label, source] of [
  ['version manifest', manifest],
  ['bootstrap core', bootstrapCore],
  ['factual v110 clean entry', cleanV110],
  ['factual v110 handoff', handoff],
  ['canonical game invites', activeInvites],
  ['canonical game screen', activeGameScreen],
]) {
  assert.ok(!source.includes('production-cross-game-coordinator.js'),
    `${label} must not import/map legacy cross-game coordinator`);
  assert.ok(!source.includes('initCrossGameCoordinator'),
    `${label} must not initialize legacy cross-game coordinator`);
  assert.ok(!source.includes('scheduleCrossGameCoordinatorAfterMain'),
    `${label} must not schedule legacy cross-game coordinator`);
}

assert.equal(countCyrillicLines(candidate), 19,
  'Cross-game coordinator Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(candidate.includes("export function initCrossGameCoordinator()"),
  'Historical initCrossGameCoordinator export must remain present as evidence');
assert.ok(candidate.includes("export function scheduleCrossGameCoordinatorAfterMain()"),
  'Historical scheduleCrossGameCoordinatorAfterMain export must remain present as evidence');
assert.ok(candidate.includes("from './production-cross-game-optimistic.js?v=96'"),
  'Historical coordinator must retain its optimistic child edge as evidence');

assert.ok(legacyEntry.includes("from './production-cross-game-coordinator.js?v=96'"),
  'Legacy regression entry must retain coordinator import evidence');
assert.ok(legacyEntry.includes('initCrossGameCoordinator();'),
  'Legacy regression entry must retain coordinator init evidence');
assert.ok(legacyEntry.includes('scheduleCrossGameCoordinatorAfterMain();'),
  'Legacy regression entry must retain coordinator scheduling evidence');

assert.deepEqual(externalRefs('production-cross-game-coordinator.js'), [LEGACY_ENTRY],
  'Cross-game coordinator must remain confined to stripped legacy regression entry');
assert.deepEqual(externalRefs('initCrossGameCoordinator'), [LEGACY_ENTRY],
  'initCrossGameCoordinator must remain confined to stripped legacy regression entry');
assert.deepEqual(externalRefs('scheduleCrossGameCoordinatorAfterMain'), [LEGACY_ENTRY],
  'scheduleCrossGameCoordinatorAfterMain must remain confined to stripped legacy regression entry');

assert.ok(audit.includes("'app/assets/js/production-cross-game-coordinator.js'"),
  'Hardcoded-text audit must classify cross-game coordinator');

assert.equal(countCyrillicLines(activeInvites), 0,
  'Canonical game-invites-v110 must remain free of hardcoded Cyrillic player copy');
assert.equal(countCyrillicLines(activeGameScreen), 0,
  'Canonical game-screen-v102-safe must remain free of hardcoded Cyrillic player copy');

assert.ok(Number(baseline.scanned_files) <= 725, 'cross-game successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2025, 'cross-game successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 372, 'cross-game successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 cross-game coordinator reachability: OK — coordinator is confined to stripped legacy production-regression-fix-entry.js and absent from factual canonical v110 ownership.');
