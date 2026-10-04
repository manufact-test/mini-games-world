import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/production-v98-ui-owner.js';
const HISTORICAL_OWNER = 'app/assets/js/production-regression-fix-entry-v98.js';

const read = p => fs.readFileSync(p,'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collectSources(dir,out=[]){
  for(const item of fs.readdirSync(dir,{withFileTypes:true})){
    const full=path.join(dir,item.name);
    if(item.isDirectory()) collectSources(full,out);
    else if(item.isFile() && ['.js','.php','.html'].includes(path.extname(item.name))) out.push(normalized(full));
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
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const cleanWrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const historicalOwner=read(HISTORICAL_OWNER);
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.ok(v98.includes("./assets/js/production-regression-fix-entry-v98.js?v=98"),
  'Historical v98 page must retain v98 regression entry');
assert.ok(v99.includes("./assets/js/production-clean-entry-v99.js?v=99"),
  'Historical v99 page must already use the successor clean entry');
assert.ok(!v99.includes('production-regression-fix-entry-v98.js'),
  'v99 must not revive v98 regression ownership');

assert.ok(historicalOwner.includes("from './production-v98-ui-owner.js?v=98'"),
  'Historical v98 regression entry must retain v98 UI-owner import evidence');
assert.ok(historicalOwner.includes('initV98UiOwnerEarly();') && historicalOwner.includes('initV98UiOwnerAfter();'),
  'Historical v98 regression entry must retain both v98 UI-owner initialization phases');
assert.deepEqual(refsTo('production-v98-ui-owner.js',[TARGET]),[HISTORICAL_OWNER],
  'v98 UI owner set changed; every owner must be classified before debt exclusion');
assert.deepEqual(refsTo('initV98UiOwnerEarly',[TARGET]),[HISTORICAL_OWNER],
  'v98 UI early initializer owner set changed');
assert.deepEqual(refsTo('initV98UiOwnerAfter',[TARGET]),[HISTORICAL_OWNER],
  'v98 UI after initializer owner set changed');
assert.deepEqual(refsTo('production-regression-fix-entry-v98.js',[HISTORICAL_OWNER]),['app/v98.php'],
  'v98 regression entry must remain confined to historical v98 page');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry scripts with canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"));
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"));

for(const [label,source] of [
  ['manifest',manifest],
  ['bootstrap',bootstrap],
  ['v110 clean wrapper',cleanWrapper],
  ['v110 clean polish',cleanPolish],
  ['v110 clean entry',clean110],
  ['v110 handoff',handoff],
]){
  assert.ok(!source.includes('production-v98-ui-owner.js'),label+' must not load historical v98 UI owner');
  assert.ok(!source.includes('initV98UiOwnerEarly'),label+' must not initialize historical v98 UI owner early');
  assert.ok(!source.includes('initV98UiOwnerAfter'),label+' must not initialize historical v98 UI owner after');
}

assert.ok(clean110.includes("initV99SessionTransport();") && clean110.includes("initV99ExplicitLockGuard();"),
  'Factual v110 must retain successor session/lock ownership');
assert.ok(handoff.includes("initGameInvites();") && handoff.includes("initSearchScreen();") && handoff.includes("initGameScreen();"),
  'Factual v110 handoff must retain canonical invite/search/game ownership');

assert.equal(countCyrillicLines(target),7,
  'Historical v98 UI-owner Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(audit.includes("'app/assets/js/production-v98-ui-owner.js'"),
  'Audit must classify historical v98 UI owner');

assert.ok(Number(baseline.scanned_files)<=703);
assert.ok(Number(baseline.cyrillic_lines_total)<=1745);
assert.ok(Number(baseline.by_scope?.client)<=92);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 v98 UI-owner reachability: OK — owner is confined to historical v98 regression entry; factual v110 retains later canonical session/search/invite/game ownership.');
