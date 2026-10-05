import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/screens/notification-empty-frame-guard-v115.js';
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
const v110=read('app/v110.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.deepEqual(refsTo('notification-empty-frame-guard-v115.js',[TARGET]),[],
  'Notification empty-frame guard v115 acquired an external app owner');
assert.equal(countCyrillicLines(target),4,'Notification empty-frame v115 Cyrillic evidence count changed');
assert.ok(target.includes("sheetTitle() !== 'Уведомления'"));
assert.ok(target.includes("'Пока уведомлений нет'"));
assert.ok(target.includes("'<div>🔔</div><strong>Загружаем…</strong>'"));

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain canonical bootstrap replacement');
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/clean-entry'\s*=>\s*'\.\/assets\/js\/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2\.js[^']*'/);
for(const [label,source] of [['v110',v110],['manifest',manifest],['bootstrap',bootstrap]]){
  assert.ok(!source.includes('notification-empty-frame-guard-v115.js'),label+' must not load orphaned notification empty-frame guard');
}

assert.ok(audit.includes("'app/assets/js/screens/notification-empty-frame-guard-v115.js'"),
  'Audit must classify orphaned notification empty-frame guard v115');
assert.ok(Number(baseline.scanned_files)<=697);
assert.ok(Number(baseline.cyrillic_lines_total)<=1710);
assert.ok(Number(baseline.by_scope?.client)<=57);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 notification empty-frame v115 reachability: OK — exhaustive app scan finds no external owner; factual Telegram v110 remains independent.');
