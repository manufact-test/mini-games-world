import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/production-v108-live-game.js';
const OWNER='app/assets/js/production-clean-entry-v108.js';
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
const v108=read('app/v108.php');
const v110=read('app/v110.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const clean108=read(OWNER);
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');

assert.deepEqual(refsTo('production-v108-live-game.js',[TARGET]),[OWNER],
  'v108 live-game direct owner set changed');
assert.deepEqual(refsTo('initV108LiveGame',[TARGET]),[OWNER],
  'v108 live-game initializer owner set changed');
assert.deepEqual(refsTo('production-clean-entry-v108.js',[OWNER]),[],
  'Abandoned clean-entry-v108 acquired an external app owner');

assert.ok(clean108.includes("from './production-v108-live-game.js?v=108'"),
  'Abandoned clean-entry-v108 must retain live-game import evidence');
assert.ok(clean108.includes('initV108LiveGame();'),
  'Abandoned clean-entry-v108 must retain live-game initializer evidence');
assert.ok(v108.includes("./assets/js/production-clean-entry-v105-fast-notifications.js?v=1051"),
  'Historical v108 page must retain the accepted v105-fast rollback owner');
assert.ok(!v108.includes('production-clean-entry-v108.js'),
  'Historical v108 page must not revive abandoned clean-entry-v108');

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
  assert.ok(!source.includes('production-v108-live-game.js'),label+' must not load abandoned v108 live-game source');
  assert.ok(!source.includes('initV108LiveGame'),label+' must not initialize abandoned v108 live-game source');
}

assert.equal(countCyrillicLines(target),3,'Historical v108 live-game Cyrillic evidence count changed');
assert.ok(target.includes("'Ждём подключения соперника'"));
assert.ok(target.includes("'60 сек'"));
assert.ok(target.includes('сек'));
assert.ok(audit.includes("'app/assets/js/production-v108-live-game.js'"),
  'Audit must classify abandoned v108 live-game source');

assert.ok(Number(baseline.scanned_files)<=694);
assert.ok(Number(baseline.cyrillic_lines_total)<=1701);
assert.ok(Number(baseline.by_scope?.client)<=48);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 v108 live-game reachability: OK — sole direct owner is ownerless clean-entry-v108; historical v108 remains on v105-fast rollback and factual v110 does not load it.');
