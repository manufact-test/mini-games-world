import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/screens/profile-screen.js';
const ACTIVE = 'app/assets/js/screens/profile-screen-v110.js';
const HISTORICAL_OWNERS = [
  'app/assets/js/main-v100.js',
  'app/assets/js/main-v101.js',
  'app/assets/js/main-v102.js',
  'app/assets/js/main-v103.js',
  'app/assets/js/main-v104.js',
  'app/assets/js/main-v105.js',
  'app/assets/js/main-v99.js',
  'app/assets/js/main.js',
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
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const mainReconnect = read('app/assets/js/main-v110-reconnect-v174.js');
const main110 = read('app/assets/js/main-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const legacy = read(TARGET);
const active = read(ACTIVE);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must retain the stripped legacy main anchor as forensic evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy main with canonical bootstrap');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest, /'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/,
  'Manifest must retain accepted v110 main ownership');
assert.ok(mainReconnect.includes("import './main-v110.js"), 'Reconnect owner must delegate to main-v110');
assert.ok(main110.includes("import './main-v110-handoff-shell.js"), 'main-v110 must delegate to factual handoff');
assert.ok(handoff.includes("import { initProfileScreen } from './screens/profile-screen-v110.js?v=1109';"),
  'Factual handoff must own the v110 Profile screen');
assert.ok(handoff.includes('initProfileScreen();'), 'Factual handoff must initialize Profile');
const directProfileAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1109'\s*=>\s*'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=(\d+)([^']*)'/);
const wrappedProfileAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1109'\s*=>\s*'\.\/assets\/js\/profile\/mgw-profile-chess-layout-v2\.js\?v=(\d+)([^']*)'/);
const profileBaseAlias = manifest.match(/'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=1126&profile_base=accepted-game-cosmetics'\s*=>\s*'\.\/assets\/js\/screens\/profile-screen-v110\.js\?v=(\d+)([^']*)'/);
const directProfileOk = directProfileAlias
  && Number(directProfileAlias[1]) >= 1142
  && directProfileAlias[2].includes('mvp27_1=profile-localized-v1');
const wrappedProfileOk = wrappedProfileAlias
  && Number(wrappedProfileAlias[1]) >= 44
  && wrappedProfileAlias[2].includes('mvp27_1=profile-chain-localized-v1')
  && profileBaseAlias
  && Number(profileBaseAlias[1]) >= 1142
  && profileBaseAlias[2].includes('mvp27_1=profile-localized-v1');
assert.ok(Boolean(directProfileOk || wrappedProfileOk),
  'Manifest must retain localized factual Profile owner or a newer wrapped successor');

for (const [label, source] of [
  ['manifest', manifest],
  ['factual clean entry', cleanV110],
  ['reconnect main', mainReconnect],
  ['main-v110', main110],
  ['factual handoff', handoff],
]) {
  assert.ok(!source.includes("./screens/profile-screen.js"), label + ' must not load legacy Profile screen');
}

assert.deepEqual(refsTo('screens/profile-screen.js', [TARGET]), HISTORICAL_OWNERS,
  'Legacy Profile screen owner set changed; every direct owner must be historically classified');

for (const version of [99,100,101,102,103,104]) {
  const owner = 'app/assets/js/main-v' + version + '.js';
  const page = 'app/v' + version + '.php';
  const source = read(owner);
  assert.ok(source.includes("from './screens/profile-screen.js?v=92'"),
    owner + ' must retain legacy Profile import evidence');
  assert.ok(source.includes('initProfileScreen();'),
    owner + ' must retain legacy Profile init evidence');
  assert.deepEqual(refsTo('main-v' + version + '.js', [owner]), [page],
    owner + ' must stay confined to its historical versioned page');
}

const main105 = read('app/assets/js/main-v105.js');
assert.ok(main105.includes("from './screens/profile-screen.js?v=92'") && main105.includes('initProfileScreen();'),
  'main-v105 must retain legacy Profile ownership evidence');
assert.deepEqual(
  refsTo('main-v105.js', ['app/assets/js/main-v105.js']),
  [
    'app/assets/js/main-v106.js',
    'app/assets/js/main-v107.js',
    'app/assets/js/main-v108.js',
    'app/assets/js/main-v109.js',
    'app/v105.php',
    'app/v108.php',
  ],
  'main-v105 historical successor set changed'
);
for (const version of [106,107,108,109]) {
  const wrapper = 'app/assets/js/main-v' + version + '.js';
  assert.ok(read(wrapper).includes("import './main-v105.js?v=105';"),
    wrapper + ' must remain a zero-copy wrapper over historical main-v105');
}
assert.deepEqual(refsTo('main-v106.js', ['app/assets/js/main-v106.js']), ['app/v106.php']);
assert.deepEqual(refsTo('main-v107.js', ['app/assets/js/main-v107.js']), ['app/v107.php']);
assert.deepEqual(refsTo('main-v108.js', ['app/assets/js/main-v108.js']), []);
assert.deepEqual(refsTo('main-v109.js', ['app/assets/js/main-v109.js']), ['app/v109.php']);
assert.ok(read('app/v108.php').includes("./assets/js/main-v105.js?v=105"),
  'Historical v108 page must retain direct main-v105 ownership evidence');

const strippedMain = read('app/assets/js/main.js');
assert.ok(strippedMain.includes("from './screens/profile-screen.js?v=93-wallet-15-3'") && strippedMain.includes('initProfileScreen();'),
  'Stripped main.js must retain legacy Profile ownership evidence');

assert.ok(active.includes("from '@mgw/i18n'"), 'Active Profile owner must retain canonical i18n');
assert.equal(countCyrillicLines(active), 0, 'Active Profile owner must remain free of hardcoded Cyrillic');
assert.equal(countCyrillicLines(legacy), 11, 'Legacy Profile screen Cyrillic evidence count changed');
assert.ok(legacy.includes('gold_shop_available') && legacy.includes('старого магазина'),
  'Legacy Profile evidence must remain untouched rather than rewritten');
assert.ok(audit.includes("'app/assets/js/screens/profile-screen.js'"),
  'Audit must classify legacy Profile screen');

assert.ok(Number(baseline.scanned_files) <= 710, 'Profile-screen successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1806, 'Profile-screen successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 153, 'Profile-screen successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy Profile screen reachability: OK — old Gold-era Profile is confined to stripped/historical mains; factual v110 retains localized profile-screen-v110 ownership.');
