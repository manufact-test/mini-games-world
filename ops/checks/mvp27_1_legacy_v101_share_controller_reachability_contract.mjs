import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/production-v101-share-controller.js';
const HISTORICAL_OWNER = 'app/assets/js/production-clean-entry-v101.js';
const NEXT_REVISION = 'app/assets/js/production-v102-share-controller.js';

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
const v101 = read('app/v101.php');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const clean101 = read(HISTORICAL_OWNER);
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const target = read(TARGET);
const nextRevision = read(NEXT_REVISION);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v101.includes('./assets/js/production-clean-entry-v101.js?v=101'),
  'Historical v101 page must retain clean-entry-v101 ownership evidence');
assert.deepEqual(
  refsTo('production-clean-entry-v101.js', [HISTORICAL_OWNER]),
  ['app/v101.php'],
  'clean-entry-v101 must stay confined to the historical v101 page'
);

assert.ok(clean101.includes("import { initV101ShareController } from './production-v101-share-controller.js?v=101';"),
  'Historical clean-entry-v101 must retain the v101 share-controller import');
assert.ok(clean101.includes('initV101ShareController();'),
  'Historical clean-entry-v101 must retain the v101 share-controller initialization');
assert.deepEqual(
  refsTo('production-v101-share-controller.js', [TARGET]),
  [HISTORICAL_OWNER],
  'v101 share-controller owner set changed; every owner must be historically classified before debt exclusion'
);
assert.deepEqual(
  refsTo('initV101ShareController', [TARGET]),
  [HISTORICAL_OWNER],
  'v101 share-controller initializer owner set changed'
);

assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must retain the stripped legacy main anchor as forensic evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry scripts with the canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(manifest.includes("'@mgw/clean-entry' => './assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js"),
  'Manifest must retain accepted v110 clean-entry ownership');
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"),
  'Accepted clean-entry wrapper must retain v110 polish delegation');
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"),
  'Accepted clean-entry polish must retain v110 base delegation');

for (const [label, source] of [
  ['manifest', manifest],
  ['v110 clean wrapper', cleanWrapper],
  ['v110 clean polish', cleanPolish],
  ['v110 clean entry', clean110],
  ['v110 handoff', handoff],
]) {
  assert.ok(!source.includes('production-v101-share-controller.js'),
    label + ' must not load historical v101 share-controller');
  assert.ok(!source.includes('initV101ShareController'),
    label + ' must not initialize historical v101 share-controller');
}

assert.ok(handoff.includes("import { initGameInvites } from './games/game-invites-v110.js"),
  'Factual v110 handoff must retain canonical invite owner');
assert.ok(handoff.includes('initGameInvites();'),
  'Factual v110 handoff must initialize canonical invite owner');
assert.ok(activeInvites.includes("from '@mgw/i18n'"),
  'Factual game-invites-v110 must retain canonical localization ownership');
assert.equal(countCyrillicLines(activeInvites), 0,
  'Factual game-invites-v110 must remain free of hardcoded Cyrillic');

assert.equal(countCyrillicLines(target), 14, 'Historical v101 share-controller Cyrillic evidence count changed');
assert.equal(countCyrillicLines(nextRevision), 14, 'v102 share-controller debt evidence count changed');
assert.ok(audit.includes("'app/assets/js/production-v101-share-controller.js'"),
  'Audit must classify historical v101 share-controller');
assert.ok(Number(baseline.scanned_files) <= 716,
  'v101 share-controller successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1901,
  'v101 share-controller successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 248,
  'v101 share-controller successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy v101 share-controller reachability: OK — sole owner is historical clean-entry-v101, while factual v110 retains localized game-invites ownership.');
