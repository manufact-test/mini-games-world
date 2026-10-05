import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/production-v107-timer-pvp.js';
const OWNER='app/assets/js/production-clean-entry-v107.js';
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
const v107=read('app/v107.php');
const v110=read('app/v110.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const clean107=read(OWNER);
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const target=read(TARGET);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');

assert.deepEqual(refsTo('production-v107-timer-pvp.js',[TARGET]),[OWNER],
  'v107 timer PVP direct owner set changed');
assert.deepEqual(refsTo('initV107TicTacToeStability',[TARGET]),[OWNER],
  'v107 timer PVP initializer owner set changed');
assert.deepEqual(refsTo('production-clean-entry-v107.js',[OWNER]),['app/v107.php'],
  'Historical v107 clean-entry page ownership changed');

assert.ok(v107.includes("./assets/js/production-clean-entry-v107.js?v=107"),
  'Historical v107 page must retain v107 clean-entry evidence');
assert.ok(clean107.includes("from './production-v107-timer-pvp.js?v=107'"),
  'Historical v107 clean entry must retain timer PVP import evidence');
assert.ok(clean107.includes('initV107TicTacToeStability();'),
  'Historical v107 clean entry must retain timer PVP initializer evidence');

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
  assert.ok(!source.includes('production-v107-timer-pvp.js'),label+' must not load historical v107 timer PVP');
  assert.ok(!source.includes('initV107TicTacToeStability'),label+' must not initialize historical v107 timer PVP');
}

assert.equal(countCyrillicLines(target),3,'Historical v107 timer PVP Cyrillic evidence count changed');
assert.ok(target.includes("'60 сек'")||target.includes('60 сек'));
assert.ok(target.includes("'Не удалось синхронизировать таймер.'"));
assert.ok(target.includes('сек'));
assert.ok(audit.includes("'app/assets/js/production-v107-timer-pvp.js'"),
  'Audit must classify historical v107 timer PVP');

assert.ok(Number(baseline.scanned_files)<=695);
assert.ok(Number(baseline.cyrillic_lines_total)<=1704);
assert.ok(Number(baseline.by_scope?.client)<=51);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 v107 timer PVP reachability: OK — sole owner is historical v107 clean-entry/page lineage; factual Telegram v110 does not load it.');
