import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/interaction-latency-coordinator-v101.js';
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
const mainLegacy=read('app/assets/js/main.js');
const baseCoordinator=read('app/assets/js/interaction-latency-coordinator.js');
const target=read(TARGET);
const v114=read('app/v114.php');
const v110=read('app/v110.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const mainReconnect=read('app/assets/js/main-v110-reconnect-v174.js');
const main110=read('app/assets/js/main-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.deepEqual(refsTo('interaction-latency-coordinator-v101.js',[TARGET]),[
  'app/assets/js/main.js',
  'app/v114.php',
],'v101 latency direct reference set changed');

assert.ok(mainLegacy.includes("import { initInteractionLatencyCoordinator } from './interaction-latency-coordinator-v101.js?v=101';"),
  'Legacy main must retain v101 latency import evidence');
assert.ok(mainLegacy.includes('initInteractionLatencyCoordinator();'),
  'Legacy main must retain v101 latency initialization evidence');
assert.ok(v114.includes('"./assets/js/interaction-latency-coordinator-v101.js?v=101": "./assets/js/interaction-latency-coordinator-v101.js?v=114"'),
  'Historical v114 must retain its v101 latency cache rewrite');
assert.ok(v114.includes("./assets/js/main.js?v=d2-unified-wallet-15-3-r1744"),
  'Historical v114 must retain legacy main ownership evidence');

assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must identify stripped legacy main anchor');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must replace legacy entry/main pair with canonical bootstrap');
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/);
assert.ok(mainReconnect.includes("import './main-v110.js"));
assert.ok(main110.includes("import './main-v110-handoff-shell.js"));

for(const [label,source] of [
  ['manifest',manifest],['bootstrap',bootstrap],['v110 reconnect',mainReconnect],['main-v110',main110],['handoff',handoff]
]){
  assert.ok(!source.includes('interaction-latency-coordinator-v101.js'),label+' must not load historical v101 latency coordinator');
  assert.ok(!source.includes('initInteractionLatencyCoordinator'),label+' must not initialize historical v101 latency coordinator');
}

assert.equal(countCyrillicLines(target),4,'Historical v101 latency Cyrillic evidence count changed');
assert.equal(countCyrillicLines(baseCoordinator),4,'Base latency coordinator must remain explicit separate debt until its own proof');
assert.ok(audit.includes("'app/assets/js/interaction-latency-coordinator-v101.js'"));
assert.ok(!audit.includes("'app/assets/js/interaction-latency-coordinator.js'"),
  'Base latency coordinator must remain in debt');

assert.ok(Number(baseline.scanned_files)<=700);
assert.ok(Number(baseline.cyrillic_lines_total)<=1726);
assert.ok(Number(baseline.by_scope?.client)<=73);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 interaction-latency-v101 reachability: OK — direct references are stripped main.js and historical v114; factual v110 remains on canonical main ownership.');
