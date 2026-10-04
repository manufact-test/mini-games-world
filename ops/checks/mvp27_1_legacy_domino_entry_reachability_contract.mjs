import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const ENTRY = 'app/assets/js/games/domino/entry.js';
const META = 'app/assets/js/games/domino/meta.js';
const LEGACY_MAIN = 'app/assets/js/main.js';

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
  return allSources
    .filter(file => !skip.has(file))
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const main110 = read('app/assets/js/main-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const legacyMain = read(LEGACY_MAIN);
const entry = read(ENTRY);
const meta = read(META);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must retain the legacy main anchor as forensic evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy main anchor with the canonical bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);

for (const [label, source] of [
  ['manifest', manifest],
  ['factual clean entry', cleanV110],
  ['main-v110', main110],
  ['factual handoff', handoff],
]) {
  assert.ok(!source.includes('games/domino/entry.js'), label + ' must not load legacy Domino entry');
  assert.ok(!source.includes('initDominoEntry'), label + ' must not initialize legacy Domino entry');
}

assert.ok(legacyMain.includes("from './games/domino/entry.js?v=74'"),
  'Legacy main.js must retain Domino entry import as forensic evidence');
assert.ok(legacyMain.includes('initDominoEntry();'),
  'Legacy main.js must retain Domino entry initialization as forensic evidence');
assert.deepEqual(refsTo('games/domino/entry.js', [ENTRY]), [LEGACY_MAIN],
  'Legacy Domino entry must stay confined to stripped main.js');
assert.deepEqual(refsTo('initDominoEntry', [ENTRY]), [LEGACY_MAIN],
  'Legacy Domino init symbol must stay confined to stripped main.js');

assert.ok(entry.includes("import { DOMINO_META } from './meta.js?v=72';"),
  'Legacy Domino entry must retain its meta child dependency');
assert.deepEqual(refsTo('DOMINO_META', [META]), [ENTRY],
  'DOMINO_META must stay confined to the legacy Domino entry child chain');

assert.equal(countCyrillicLines(entry), 12,
  'Legacy Domino entry Cyrillic evidence count changed; re-prove before changing debt');
assert.equal(countCyrillicLines(meta), 2,
  'Legacy Domino meta Cyrillic evidence count changed; re-prove before changing debt');

assert.ok(audit.includes("'app/assets/js/games/domino/entry.js'"),
  'Audit must classify legacy Domino entry');
assert.ok(audit.includes("'app/assets/js/games/domino/meta.js'"),
  'Audit must classify legacy Domino meta');

assert.ok(Number(baseline.scanned_files) <= 721, 'Domino-entry successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1978, 'Domino-entry successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 325, 'Domino-entry successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy Domino entry reachability: OK — entry is confined to stripped main.js and meta is confined to that legacy child chain.');
