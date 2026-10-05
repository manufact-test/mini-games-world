import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/production-v106-self-toast-policy.js';
const OWNERS=[
  'app/assets/js/production-clean-entry-v106.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
];

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
const v106=read('app/v106.php');
const v107=read('app/v107.php');
const v108=read('app/v108.php');
const v109=read('app/v109.php');
const v110=read('app/v110.php');
const clean106=read('app/assets/js/production-clean-entry-v106.js');
const clean107=read('app/assets/js/production-clean-entry-v107.js');
const clean108=read('app/assets/js/production-clean-entry-v108.js');
const clean109=read('app/assets/js/production-clean-entry-v109.js');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const cleanWrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.ok(v106.includes("./assets/js/production-clean-entry-v106.js?v=106"),'Historical v106 page must retain v106 clean entry');
assert.ok(v107.includes("./assets/js/production-clean-entry-v107.js?v=107"),'Historical v107 page must retain v107 clean entry');
assert.ok(v108.includes("./assets/js/production-clean-entry-v105-fast-notifications.js?v=1051"),
  'Historical v108 page must retain emergency rollback owner');
assert.ok(!v108.includes('production-clean-entry-v108.js'),'Historical v108 page must not revive abandoned clean-entry-v108');
assert.ok(v109.includes("./assets/js/production-clean-entry-v109.js?v=109"),'Historical v109 page must retain v109 successor');
assert.ok(!clean109.includes('production-v106-self-toast-policy.js')&&!clean109.includes('initV106SelfToastPolicy'),
  'v109 successor must not retain v106 self-toast policy');

assert.deepEqual(refsTo('production-v106-self-toast-policy.js',[TARGET]),OWNERS,
  'v106 self-toast policy owner set changed');
assert.deepEqual(refsTo('initV106SelfToastPolicy',[TARGET]),OWNERS,
  'v106 self-toast initializer owner set changed');

for(const [label,source] of [['clean-v106',clean106],['clean-v107',clean107],['clean-v108',clean108]]){
  assert.ok(source.includes("from './production-v106-self-toast-policy.js?v=106'"),label+' must retain historical import evidence');
  assert.ok(source.includes('initV106SelfToastPolicy();'),label+' must retain historical initializer evidence');
}
assert.deepEqual(refsTo('production-clean-entry-v106.js',['app/assets/js/production-clean-entry-v106.js']),['app/v106.php']);
assert.deepEqual(refsTo('production-clean-entry-v107.js',['app/assets/js/production-clean-entry-v107.js']),['app/v107.php']);
assert.deepEqual(refsTo('production-clean-entry-v108.js',['app/assets/js/production-clean-entry-v108.js']),[],
  'clean-entry-v108 must remain ownerless');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'));
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"));
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"));

for(const [label,source] of [
  ['manifest',manifest],['bootstrap',bootstrap],['v110 wrapper',cleanWrapper],
  ['v110 polish',cleanPolish],['v110 clean entry',clean110],['v110 handoff',handoff]
]){
  assert.ok(!source.includes('production-v106-self-toast-policy.js'),label+' must not load historical v106 self-toast policy');
  assert.ok(!source.includes('initV106SelfToastPolicy'),label+' must not initialize historical v106 self-toast policy');
}

assert.equal(countCyrillicLines(target),5,'Historical self-toast Cyrillic evidence count changed');
assert.ok(target.includes("'Приглашение отменено.'")&&target.includes("'Матч отменён.'"),
  'Historical self-toast RU evidence must remain untouched');
assert.ok(audit.includes("'app/assets/js/production-v106-self-toast-policy.js'"),
  'Audit must classify historical v106 self-toast policy');

assert.ok(Number(baseline.scanned_files)<=701);
assert.ok(Number(baseline.cyrillic_lines_total)<=1730);
assert.ok(Number(baseline.by_scope?.client)<=77);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 v106 self-toast reachability: OK — policy is confined to historical v106-v108 clean-entry lineages and absent from factual v110.');
