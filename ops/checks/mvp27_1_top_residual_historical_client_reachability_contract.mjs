import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

const TARGETS=Object.freeze({
  orphanInvite:'app/assets/js/games/invite-terminal-actions-v110r12.js',
  legacyInvite:'app/assets/js/games/invite-terminal-actions-v115.js',
  legacyMain:'app/assets/js/main.js',
  legacyV106Timer:'app/assets/js/production-v106-timer-mobile.js',
  legacyV98Passive:'app/assets/js/production-v98-passive-session-transport.js',
  orphanBell:'app/assets/js/screens/notification-bell-first-click-v116.js',
});
const ACTIVE_TARGET='app/assets/js/production-v110-targeted-interactions.js';

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
const cleanWrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const main=read(TARGETS.legacyMain);
const clean106=read('app/assets/js/production-clean-entry-v106.js');
const regression98=read('app/assets/js/production-regression-fix-entry-v98.js');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on factual v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'));
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'));
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must strip the legacy regression/main entry pair and replace it with canonical bootstrap');
assert.ok(v110.includes("substr_count($html, '<script type=\"module\" src=\"') !== 1"),
  'v110 must retain exactly-one-top-level-bootstrap guard');

assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match=>match[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
assert.ok(manifest.includes("'@mgw/main' => './assets/js/main-v110-reconnect-v174.js?v=2'"));
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"));
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"));
assert.ok(clean110.includes("from './production-v110-targeted-interactions.js?v=1102'"),
  'Current active targeted-interactions owner must remain in factual v110 clean-entry');

assert.deepEqual(refsTo('invite-terminal-actions-v110r12.js',[TARGETS.orphanInvite]),[],
  'v110r12 invite terminal actions must remain orphaned');
assert.deepEqual(refsTo('invite-terminal-actions-v115.js',[TARGETS.legacyInvite]),[TARGETS.legacyMain],
  'v115 invite terminal actions must remain owned only by stripped legacy main.js');
assert.ok(main.includes("from './games/invite-terminal-actions-v115.js?v=115'"));
assert.ok(!clean110.includes('invite-terminal-actions-v115.js')&&!clean110.includes('invite-terminal-actions-v110r12.js'));

assert.deepEqual(refsTo('production-v106-timer-mobile.js',[TARGETS.legacyV106Timer]),['app/assets/js/production-clean-entry-v106.js'],
  'v106 timer/mobile owner set changed');
assert.ok(clean106.includes("from './production-v106-timer-mobile.js?v=106'"));
assert.ok(!clean110.includes('production-v106-timer-mobile.js')&&!clean110.includes('production-clean-entry-v106.js'),
  'Factual v110 clean-entry must not inherit historical v106 timer/mobile ownership');

assert.deepEqual(refsTo('production-v98-passive-session-transport.js',[TARGETS.legacyV98Passive]),['app/assets/js/production-regression-fix-entry-v98.js'],
  'v98 passive session owner set changed');
assert.ok(regression98.includes("from './production-v98-passive-session-transport.js?v=98'"));
assert.ok(!clean110.includes('production-v98-passive-session-transport.js')&&!clean110.includes('production-regression-fix-entry-v98.js'),
  'Factual v110 clean-entry must not inherit historical v98 passive-session ownership');

assert.deepEqual(refsTo('notification-bell-first-click-v116.js',[TARGETS.orphanBell]),[],
  'notification bell v116 must remain orphaned');

for(const file of Object.values(TARGETS)){
  assert.equal(countCyrillicLines(read(file)),2,file+' Cyrillic evidence count changed');
  assert.ok(audit.includes("'"+file+"'"),file+' must be explicitly classified out of active player debt');
}
assert.equal(countCyrillicLines(read(ACTIVE_TARGET)),2,'Active targeted-interactions evidence count changed');
assert.ok(!audit.includes("'"+ACTIVE_TARGET+"'"),'Active targeted interactions must remain inside localization debt');

assert.ok(Number(baseline.scanned_files)<=679);
assert.ok(Number(baseline.cyrillic_lines_total)<=1670);
assert.ok(Number(baseline.by_scope?.client)<=17);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 top residual historical client reachability: OK — six 2-line sources are stripped/historical/orphaned outside factual v110; active v110 targeted interactions remains debt.');
