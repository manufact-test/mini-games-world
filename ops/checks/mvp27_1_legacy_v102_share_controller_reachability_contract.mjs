import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/production-v102-share-controller.js';
const DIRECT_OWNERS = [
  'app/assets/js/production-clean-entry-v102.js',
  'app/assets/js/production-clean-entry-v103.js',
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
];

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
  return allSources.filter(file => !skip.has(file)).filter(file => read(file).includes(needle)).sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrap = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanWrapper = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish = read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const target = read(TARGET);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.deepEqual(refsTo('production-v102-share-controller.js', [TARGET]), DIRECT_OWNERS,
  'v102 share-controller owner set changed; every owner must remain historically classified');
assert.deepEqual(refsTo('initV102ShareController', [TARGET]), DIRECT_OWNERS,
  'v102 share-controller initializer owner set changed');

for (const owner of DIRECT_OWNERS) {
  const source = read(owner);
  assert.ok(source.includes("from './production-v102-share-controller.js?v=102'"),
    owner + ' must retain historical v102 share-controller import evidence');
  assert.ok(source.includes('initV102ShareController();'),
    owner + ' must retain historical v102 share-controller initialization evidence');
}

assert.deepEqual(refsTo('production-clean-entry-v102.js', ['app/assets/js/production-clean-entry-v102.js']), ['app/v102.php'],
  'clean-entry-v102 must stay confined to historical v102 page');
assert.deepEqual(refsTo('production-clean-entry-v103.js', ['app/assets/js/production-clean-entry-v103.js']), ['app/v103.php'],
  'clean-entry-v103 must stay confined to historical v103 page');
assert.deepEqual(refsTo('production-clean-entry-v104.js', ['app/assets/js/production-clean-entry-v104.js']), ['app/v104.php'],
  'clean-entry-v104 must stay confined to historical v104 page');
assert.deepEqual(
  refsTo('production-clean-entry-v105.js', ['app/assets/js/production-clean-entry-v105.js']),
  ['app/assets/js/production-clean-entry-v106.js', 'app/v105.php'],
  'clean-entry-v105 must stay inside historical v105/v106 lineage'
);
assert.deepEqual(refsTo('production-clean-entry-v106.js', ['app/assets/js/production-clean-entry-v106.js']), ['app/v106.php'],
  'clean-entry-v106 wrapper must stay confined to historical v106 page');
assert.ok(read('app/assets/js/production-clean-entry-v106.js').includes("import './production-clean-entry-v105.js?v=105';"),
  'historical clean-entry-v106 must remain a wrapper over v105');
assert.deepEqual(
  refsTo('production-clean-entry-v105-fast-notifications.js', ['app/assets/js/production-clean-entry-v105-fast-notifications.js']),
  ['app/v108.php'],
  'fast-notifications clean entry must stay confined to historical v108 page'
);
assert.deepEqual(refsTo('production-clean-entry-v107.js', ['app/assets/js/production-clean-entry-v107.js']), ['app/v107.php'],
  'clean-entry-v107 must stay confined to historical v107 page');
assert.deepEqual(refsTo('production-clean-entry-v108.js', ['app/assets/js/production-clean-entry-v108.js']), [],
  'clean-entry-v108 must remain ownerless historical source');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry scripts with the canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(manifest.includes("'@mgw/clean-entry' => './assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js"),
  'Manifest must retain factual v110 clean-entry ownership');
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
  assert.ok(!source.includes('production-v102-share-controller.js'),
    label + ' must not load historical v102 share-controller');
  assert.ok(!source.includes('initV102ShareController'),
    label + ' must not initialize historical v102 share-controller');
}

assert.ok(handoff.includes("import { initGameInvites } from './games/game-invites-v110.js"),
  'Factual v110 handoff must retain canonical invite owner');
assert.ok(handoff.includes('initGameInvites();'),
  'Factual v110 handoff must initialize canonical invite owner');
assert.ok(activeInvites.includes("from '@mgw/i18n'"),
  'Factual game-invites-v110 must retain canonical localization ownership');
assert.equal(countCyrillicLines(activeInvites), 0,
  'Factual game-invites-v110 must remain free of hardcoded Cyrillic');

assert.equal(countCyrillicLines(target), 14, 'Historical v102 share-controller Cyrillic evidence count changed');
assert.ok(audit.includes("'app/assets/js/production-v102-share-controller.js'"),
  'Audit must classify historical v102 share-controller');

assert.ok(Number(baseline.scanned_files) <= 715, 'v102 share-controller successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1887, 'v102 share-controller successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 234, 'v102 share-controller successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy v102 share-controller reachability: OK — all direct owners resolve only through historical v102-v108 clean-entry lineages, while factual v110 retains localized game-invites ownership.');
