import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const ENTRY = 'app/assets/js/games/checkers/entry.js';
const META = 'app/assets/js/games/checkers/meta.js';
const HISTORICAL_OWNERS = [
  'app/assets/js/main-v100.js',
  'app/assets/js/main-v101.js',
  'app/assets/js/main-v102.js',
  'app/assets/js/main-v103.js',
  'app/assets/js/main-v104.js',
  'app/assets/js/main-v105.js',
  'app/assets/js/main-v120-invite-controller-shell.js',
  'app/assets/js/main-v99.js',
  'app/assets/js/main.js',
];
const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;
function collectSources(dir,out=[]){for(const item of fs.readdirSync(dir,{withFileTypes:true})){const full=path.join(dir,item.name);if(item.isDirectory())collectSources(full,out);else if(item.isFile()&&['.js','.php','.html'].includes(path.extname(item.name)))out.push(normalized(full));}return out;}
const allSources=collectSources('app').sort();
function refsTo(needle,excluded=[]){const skip=new Set(excluded);return allSources.filter(file=>!skip.has(file)).filter(file=>read(file).includes(needle)).sort();}

const launch=read('bot/helpers/WebAppLaunchUrl.php');
const v110=read('app/v110.php');
const v120=read('app/v120.php');
const bootstrapCore=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const cleanV110=read('app/assets/js/production-clean-entry-v110.js');
const mainReconnect=read('app/assets/js/main-v110-reconnect-v174.js');
const main110=read('app/assets/js/main-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const main120=read('app/assets/js/main-v120.js');
const gameCardCopy=read('app/assets/js/games/game-card-copy.js');
const unifiedLauncher=read('app/assets/js/games/unified-game-launcher.js');
const entry=read(ENTRY);
const meta=read(META);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"));
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'));
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'));
assert.deepEqual([...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/);
assert.ok(mainReconnect.includes("import './main-v110.js"));
assert.ok(main110.includes("import './main-v110-handoff-shell.js"));

for(const [label,source] of [['manifest',manifest],['clean',cleanV110],['reconnect',mainReconnect],['main-v110',main110],['handoff',handoff]]){
  assert.ok(!source.includes('games/checkers/entry.js'),label+' must not load legacy Checkers entry');
  assert.ok(!source.includes('initCheckersEntry'),label+' must not initialize legacy Checkers entry');
}
assert.deepEqual(refsTo('games/checkers/entry.js',[ENTRY]),HISTORICAL_OWNERS);
assert.deepEqual(refsTo('initCheckersEntry',[ENTRY]),HISTORICAL_OWNERS);

for(const version of [99,100,101,102,103,104]){
  const owner='app/assets/js/main-v'+version+'.js';
  const page='app/v'+version+'.php';
  assert.ok(read(owner).includes("from './games/checkers/entry.js?v=74'"));
  assert.ok(read(owner).includes('initCheckersEntry();'));
  assert.deepEqual(refsTo('main-v'+version+'.js',[owner]),[page]);
}
const main105=read('app/assets/js/main-v105.js');
assert.ok(main105.includes("from './games/checkers/entry.js?v=74'")&&main105.includes('initCheckersEntry();'));
assert.deepEqual(refsTo('main-v105.js',['app/assets/js/main-v105.js']),[
  'app/assets/js/main-v106.js','app/assets/js/main-v107.js','app/assets/js/main-v108.js','app/assets/js/main-v109.js','app/v105.php','app/v108.php'
]);
for(const version of [106,107,108,109]) assert.ok(read('app/assets/js/main-v'+version+'.js').includes("import './main-v105.js?v=105';"));
assert.deepEqual(refsTo('main-v106.js',['app/assets/js/main-v106.js']),['app/v106.php']);
assert.deepEqual(refsTo('main-v107.js',['app/assets/js/main-v107.js']),['app/v107.php']);
assert.deepEqual(refsTo('main-v108.js',['app/assets/js/main-v108.js']),[]);
assert.deepEqual(refsTo('main-v109.js',['app/assets/js/main-v109.js']),['app/v109.php']);
assert.ok(read('app/v108.php').includes("./assets/js/main-v105.js?v=105"));
assert.ok(read('app/assets/js/main.js').includes("from './games/checkers/entry.js?v=74'")&&read('app/assets/js/main.js').includes('initCheckersEntry();'));

assert.ok(main120.includes("import './main-v120-invite-controller-shell.js?v=1200';"));
assert.deepEqual(refsTo('main-v120-invite-controller-shell.js',['app/assets/js/main-v120-invite-controller-shell.js']),['app/assets/js/main-v120.js']);
assert.deepEqual(refsTo('main-v120.js',['app/assets/js/main-v120.js']),[]);
assert.ok(v120.includes("v120 failed production acceptance and must never execute again"));
assert.ok(v120.includes("$target = '/app/v110.php?v=1123';"));

assert.deepEqual(refsTo('CHECKERS_META',[META]),['app/assets/js/games/game-card-copy.js']);
assert.ok(gameCardCopy.includes("import { CHECKERS_META } from './checkers/meta.js?v=58&mvp27_1=localized-meta-v1';")&&gameCardCopy.includes("from '@mgw/i18n'"));
assert.ok(unifiedLauncher.includes("checkers:Object.freeze({ defaultSize:8, options:() => [{ value:8, label:'8×8' }] })")&&unifiedLauncher.includes("from '@mgw/i18n'"));
assert.ok(handoff.includes("import { initGameCardCopy } from './games/game-card-copy.js")&&handoff.includes("import { initUnifiedGameLauncher } from './games/unified-game-launcher.js"));

assert.equal(countCyrillicLines(entry),8);
assert.equal(countCyrillicLines(meta),0);
assert.ok(audit.includes("'app/assets/js/games/checkers/entry.js'"));
assert.ok(!audit.includes("'app/assets/js/games/checkers/meta.js'"));
assert.ok(Number(baseline.scanned_files)<=705);
assert.ok(Number(baseline.cyrillic_lines_total)<=1760);
assert.ok(Number(baseline.by_scope?.client)<=107);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);
console.log('MVP-27.1 legacy Checkers entry reachability: OK — entry owners are historical/rejected; factual v110 retains localized unified setup and active Checkers meta consumer.');
