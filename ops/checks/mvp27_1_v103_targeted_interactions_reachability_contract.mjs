import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/production-v103-targeted-interactions.js';
const OWNERS=[
  'app/assets/js/production-clean-entry-v103.js',
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
  'app/assets/js/production-clean-entry-v109.js',
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
const v103=read('app/v103.php');
const v104=read('app/v104.php');
const v105=read('app/v105.php');
const v107=read('app/v107.php');
const v108=read('app/v108.php');
const v109=read('app/v109.php');
const v110=read('app/v110.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const cleanWrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.deepEqual(refsTo('production-v103-targeted-interactions.js',[TARGET]),OWNERS,
  'v103 targeted-interactions direct owner set changed');
assert.deepEqual(refsTo('initV103TargetedInteractions',[TARGET]),OWNERS,
  'v103 targeted-interactions initializer owner set changed');

for(const owner of OWNERS){
  const source=read(owner);
  assert.ok(source.includes("from './production-v103-targeted-interactions.js?v=103'"),
    owner+' must retain historical v103 targeted-interactions import evidence');
  assert.ok(source.includes('initV103TargetedInteractions();'),
    owner+' must retain historical v103 targeted-interactions initializer evidence');
}

assert.ok(v103.includes("./assets/js/production-clean-entry-v103.js?v=103"),'Historical v103 page must retain v103 clean entry');
assert.ok(v104.includes("./assets/js/production-clean-entry-v104.js?v=104"),'Historical v104 page must retain v104 clean entry');
assert.ok(v105.includes("./assets/js/production-clean-entry-v105.js?v=105"),'Historical v105 page must retain v105 clean entry');
assert.ok(v107.includes("./assets/js/production-clean-entry-v107.js?v=107"),'Historical v107 page must retain v107 clean entry');
assert.ok(v108.includes("./assets/js/production-clean-entry-v105-fast-notifications.js?v=1051"),
  'Historical v108 page must retain emergency rollback clean entry');
assert.ok(!v108.includes('production-clean-entry-v108.js'),'Historical v108 page must not revive abandoned clean-entry-v108');
assert.ok(v109.includes("./assets/js/production-clean-entry-v109.js?v=109"),'Historical v109 page must retain v109 clean entry');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry/main pair with canonical bootstrap');
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"));
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"));
assert.ok(clean110.includes("import { initV110TargetedInteractions } from './production-v110-targeted-interactions.js?v=1102';"));
assert.ok(clean110.includes('initV110TargetedInteractions();'));

for(const [label,source] of [
  ['manifest',manifest],['bootstrap',bootstrap],['v110 wrapper',cleanWrapper],
  ['v110 polish',cleanPolish],['v110 clean entry',clean110]
]){
  assert.ok(!source.includes('production-v103-targeted-interactions.js'),label+' must not load historical v103 targeted interactions');
  assert.ok(!source.includes('initV103TargetedInteractions'),label+' must not initialize historical v103 targeted interactions');
}

assert.equal(countCyrillicLines(target),4,'Historical v103 targeted-interactions Cyrillic evidence count changed');
assert.ok(audit.includes("'app/assets/js/production-v103-targeted-interactions.js'"),
  'Audit must classify historical v103 targeted-interactions owner');

assert.ok(Number(baseline.scanned_files)<=698);
assert.ok(Number(baseline.cyrillic_lines_total)<=1714);
assert.ok(Number(baseline.by_scope?.client)<=61);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 v103 targeted interactions reachability: OK — direct owners are historical v103-v109 clean-entry lineages; factual v110 uses the v110 targeted-interactions owner.');
