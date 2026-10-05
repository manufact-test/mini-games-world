import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET = 'app/assets/js/production-ui-stability-fix.js';
const OWNERS = [
  'app/assets/js/production-regression-fix-entry-v97.js',
  'app/assets/js/production-regression-fix-entry-v98.js',
  'app/assets/js/production-regression-fix-entry.js',
];

const read=p=>fs.readFileSync(p,'utf8');
const normalized=p=>p.split(path.sep).join('/');
const countCyrillicLines=s=>s.split(/\r?\n/).filter(line=>/[\u0400-\u04FF]/.test(line)).length;

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
const index=read('app/index.html');
const v97=read('app/v97.php');
const v98=read('app/v98.php');
const v99=read('app/v99.php');
const v110=read('app/v110.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const cleanWrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const cleanPolish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const baseOwner=read('app/assets/js/production-regression-fix-entry.js');
const v97Owner=read('app/assets/js/production-regression-fix-entry-v97.js');
const v98Owner=read('app/assets/js/production-regression-fix-entry-v98.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.ok(index.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'index must retain the legacy regression-entry source anchor');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'v110 must still identify the exact legacy regression-entry anchor');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace the legacy regression/main pair with canonical bootstrap');
assert.ok(v110.includes("substr_count($html, '<script type=\"module\" src=\"') !== 1"),
  'v110 must keep the one-top-level-bootstrap invariant');

assert.deepEqual(
  [...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Canonical bootstrap must sequence only accepted clean-entry and main'
);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
assert.ok(cleanWrapper.includes("import './production-clean-entry-v110-mvp19-3-final-polish.js"));
assert.ok(cleanPolish.includes("import './production-clean-entry-v110.js"));

assert.deepEqual(refsTo('production-ui-stability-fix.js',[TARGET]),OWNERS,
  'UI stability owner set changed; every direct owner must be historically classified');
assert.deepEqual(refsTo('initProductionUiStabilityFix',[TARGET]),OWNERS,
  'UI stability initializer owner set changed');

for(const [label,source] of [
  ['base regression entry',baseOwner],
  ['v97 regression entry',v97Owner],
  ['v98 regression entry',v98Owner],
]){
  assert.ok(source.includes("from './production-ui-stability-fix.js?v=94'"),
    label+' must retain historical UI-stability import evidence');
  assert.ok(source.includes('initProductionUiStabilityFix();'),
    label+' must retain historical UI-stability initialization evidence');
}

assert.ok(v97.includes("'./assets/js/production-regression-fix-entry-v97.js?v=97'"),
  'Historical v97 page must retain v97 replacement evidence');
assert.ok(v98.includes("'./assets/js/production-regression-fix-entry-v98.js?v=98'"),
  'Historical v98 page must retain v98 replacement evidence');
assert.ok(v99.includes("'./assets/js/production-clean-entry-v99.js?v=99'"),
  'v99 must retain the clean-entry successor boundary');
assert.ok(!v99.includes('production-ui-stability-fix.js'),
  'v99 page must not directly own the historical UI-stability module');

for(const [label,source] of [
  ['manifest',manifest],
  ['bootstrap',bootstrap],
  ['v110 clean wrapper',cleanWrapper],
  ['v110 clean polish',cleanPolish],
  ['v110 clean entry',clean110],
  ['v110 handoff',handoff],
]){
  assert.ok(!source.includes('production-ui-stability-fix.js'),label+' must not load historical UI-stability owner');
  assert.ok(!source.includes('initProductionUiStabilityFix'),label+' must not initialize historical UI-stability owner');
}

assert.equal(countCyrillicLines(target),5,
  'Historical UI-stability Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(target.includes("message:'Связь с сервером временно нестабильна."),
  'Historical UI-stability source must retain original RU evidence untouched');
assert.ok(audit.includes("'app/assets/js/production-ui-stability-fix.js'"),
  'Hardcoded-text audit must classify historical UI-stability source');

assert.ok(Number(baseline.scanned_files)<=702);
assert.ok(Number(baseline.cyrillic_lines_total)<=1735);
assert.ok(Number(baseline.by_scope?.client)<=82);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 UI stability reachability: OK — module is confined to stripped/historical regression entries and absent from factual v110 ownership.');
