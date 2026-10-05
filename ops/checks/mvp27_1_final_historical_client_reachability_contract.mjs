import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const TARGETS=Object.freeze({
  requestGuard:'app/assets/js/api/request-guard.js',
  main102:'app/assets/js/main-v102.js',
  main103:'app/assets/js/main-v103.js',
  main104:'app/assets/js/main-v104.js',
  main105:'app/assets/js/main-v105.js',
  main120Shell:'app/assets/js/main-v120-invite-controller-shell.js',
  invitePicker:'app/assets/js/production-v99-invite-picker-hold.js',
  bell115:'app/assets/js/screens/notification-bell-first-click-v115.js',
  search:'app/assets/js/screens/search-screen.js',
});
const ACTIVE_TARGETS=[
  'app/assets/js/commerce/mgw-purchase-feedback.js',
  'app/assets/js/production-v110-match-lifecycle.js',
  'app/assets/js/production-v99-explicit-lock-guard.js',
  'app/assets/js/profile/mgw-avatar-registry.js',
  'app/assets/js/profile/mgw-victory-effect-selector.js',
  'app/assets/js/session.js',
];

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
const v110=read('app/v110.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on factual v110');
assert.ok(launch.includes("// private const ENTRY_PATH = '/app/v120.php"),'v120 must remain explicitly postmortem-only');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),'v110 must replace legacy entry pair with canonical bootstrap');
assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match=>match[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(manifest.includes("'@mgw/main' => './assets/js/main-v110-reconnect-v174.js?v=2'"));
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);

assert.deepEqual(refsTo('api/request-guard.js',[TARGETS.requestGuard]),['app/assets/js/main.js']);
assert.deepEqual(refsTo('main-v102.js',[TARGETS.main102]),['app/v102.php']);
assert.deepEqual(refsTo('main-v103.js',[TARGETS.main103]),['app/v103.php']);
assert.deepEqual(refsTo('main-v104.js',[TARGETS.main104]),['app/v104.php']);
assert.deepEqual(refsTo('main-v105.js',[TARGETS.main105]),[
  'app/assets/js/main-v106.js',
  'app/assets/js/main-v107.js',
  'app/assets/js/main-v108.js',
  'app/assets/js/main-v109.js',
  'app/v105.php',
  'app/v108.php',
]);
assert.deepEqual(refsTo('main-v120-invite-controller-shell.js',[TARGETS.main120Shell]),['app/assets/js/main-v120.js']);
assert.ok(!v110.includes('main-v120')&&!manifest.includes('main-v120'),'Factual v110 entry/manifest must not revive v120 shell lineage');

assert.deepEqual(refsTo('production-v99-invite-picker-hold.js',[TARGETS.invitePicker]),[
  'app/assets/js/production-clean-entry-v100.js',
  'app/assets/js/production-clean-entry-v101.js',
  'app/assets/js/production-clean-entry-v102.js',
  'app/assets/js/production-clean-entry-v103.js',
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
  'app/assets/js/production-clean-entry-v109.js',
  'app/assets/js/production-clean-entry-v99.js',
]);
assert.ok(!clean110.includes('production-v99-invite-picker-hold.js'),'Factual v110 clean-entry must omit historical invite-picker hold');

assert.deepEqual(refsTo('notification-bell-first-click-v115.js',[TARGETS.bell115]),[],'notification bell v115 must remain orphaned');

const searchRefs=refsTo('screens/search-screen.js',[TARGETS.search]);
assert.deepEqual(searchRefs,[
  'app/assets/js/games/battleship/entry.js',
  'app/assets/js/games/checkers/entry.js',
  'app/assets/js/games/chess/entry.js',
  'app/assets/js/games/domino/entry.js',
  'app/assets/js/games/four-in-a-row/entry.js',
  'app/assets/js/games/go/entry.js',
  'app/assets/js/games/reversi/entry.js',
  'app/assets/js/games/tictactoe/entry.js',
  'app/assets/js/main.js',
  'app/assets/js/production-ui-stability-fix.js',
]);
for(const owner of searchRefs){
  assert.ok(audit.includes("'"+owner+"'"),'Search-screen owner must already be classified historical: '+owner);
}

for(const file of Object.values(TARGETS)){
  assert.equal(countCyrillicLines(read(file)),1,file+' Cyrillic evidence count changed');
  assert.ok(audit.includes("'"+file+"'"),file+' must be explicitly classified out of active player debt');
}
for(const file of ACTIVE_TARGETS){
  assert.equal(countCyrillicLines(read(file)),1,file+' active Cyrillic evidence count changed');
  assert.ok(!audit.includes("'"+file+"'"),file+' must remain inside active localization debt');
}

assert.equal(Number(baseline.cyrillic_lines_total),1659);
assert.equal(Number(baseline.by_scope?.client),6);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 final historical client reachability: OK — nine one-line sources are historical/orphaned outside factual v110; six factual active client lines remain.');
