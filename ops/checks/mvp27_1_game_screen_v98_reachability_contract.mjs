import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/screens/game-screen-v98.js';
const UI_OWNER='app/assets/js/production-v98-ui-owner.js';
const HISTORICAL_ENTRY='app/assets/js/production-regression-fix-entry-v98.js';
const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

function collectSources(dir,out=[]){
  for(const item of fs.readdirSync(dir,{withFileTypes:true})){
    const full=path.join(dir,item.name);
    if(item.isDirectory()) collectSources(full,out);
    else if(item.isFile()&&['.js','.php','.html'].includes(path.extname(item.name))) out.push(normalized(full));
  }
  return out;
}
const allSources=collectSources('app').sort();
function refsTo(needle,excluded=[]){
  const skip=new Set(excluded);
  return allSources.filter(file=>!skip.has(file)).filter(file=>read(file).includes(needle)).sort();
}

const launch=read('bot/helpers/WebAppLaunchUrl.php');
const v98=read('app/v98.php');
const v99=read('app/v99.php');
const v110=read('app/v110.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const uiOwner=read(UI_OWNER);
const historicalEntry=read(HISTORICAL_ENTRY);
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');

assert.deepEqual(refsTo('screens/game-screen-v98.js',[TARGET]),[UI_OWNER],
  'game-screen-v98 direct owner set changed');
assert.ok(uiOwner.includes("from './screens/game-screen-v98.js?v=98'"),
  'Historical v98 UI owner must retain game-screen-v98 import evidence');
assert.ok(uiOwner.includes('startGamePolling'),
  'Historical v98 UI owner must retain game-screen-v98 polling usage evidence');

assert.deepEqual(refsTo('production-v98-ui-owner.js',[UI_OWNER]),[HISTORICAL_ENTRY],
  'v98 UI owner must remain confined to historical v98 regression entry');
assert.ok(historicalEntry.includes("from './production-v98-ui-owner.js?v=98'"),
  'Historical v98 regression entry must retain v98 UI owner import evidence');
assert.deepEqual(refsTo('production-regression-fix-entry-v98.js',[HISTORICAL_ENTRY]),['app/v98.php'],
  'v98 regression entry must remain confined to historical v98 page');
assert.ok(v98.includes("./assets/js/production-regression-fix-entry-v98.js?v=98"),
  'Historical v98 page must retain v98 regression entry');
assert.ok(v99.includes("./assets/js/production-clean-entry-v99.js?v=99"),
  'Historical v99 page must retain successor clean entry');
assert.ok(!v99.includes('production-regression-fix-entry-v98.js'),
  'v99 must not revive v98 regression ownership');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain canonical bootstrap replacement');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match=>match[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);

for(const [label,source] of [
  ['version manifest',manifest],
  ['bootstrap core',bootstrap],
  ['factual v110 clean entry',clean110],
  ['factual v110 handoff',handoff],
]){
  assert.ok(!source.includes('screens/game-screen-v98.js'),label+' must not load historical game-screen-v98');
  assert.ok(!source.includes('game-screen-v98.js'),label+' must not load historical game-screen-v98');
}

assert.equal(countCyrillicLines(target),3,'Historical game-screen-v98 Cyrillic evidence count changed');
assert.ok(target.includes('сек'));
assert.ok(target.includes("'вы'")&&target.includes("'соперник'"));
assert.ok(target.includes("'Не удалось выполнить действие.'"));
assert.ok(audit.includes("'app/assets/js/screens/game-screen-v98.js'"),
  'Audit must classify historical game-screen-v98');

assert.ok(Number(baseline.scanned_files)<=693);
assert.ok(Number(baseline.cyrillic_lines_total)<=1698);
assert.ok(Number(baseline.by_scope?.client)<=45);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 game-screen-v98 reachability: OK — sole direct owner is historical v98 UI owner, itself confined to historical v98 regression/page lineage; factual Telegram v110 does not load it.');
