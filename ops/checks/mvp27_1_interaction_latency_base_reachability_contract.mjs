import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/interaction-latency-coordinator.js';
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
const target=read(TARGET);
const v101=read('app/assets/js/interaction-latency-coordinator-v101.js');
const v110=read('app/v110.php');
const bootstrap=read('app/assets/js/app-bootstrap-v2-core.js');
const manifest=read('app/runtime/client/version-manifest.php');
const mainReconnect=read('app/assets/js/main-v110-reconnect-v174.js');
const main110=read('app/assets/js/main-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"));
assert.deepEqual(refsTo('interaction-latency-coordinator.js',[TARGET]),[],
  'Base interaction-latency coordinator acquired an external app owner');
assert.equal(countCyrillicLines(target),4,'Base interaction-latency Cyrillic evidence count changed');
assert.equal(countCyrillicLines(v101),4,'v101 latency historical evidence count changed');
assert.ok(target.includes('export function initInteractionLatencyCoordinator()'),
  'Base coordinator must retain historical implementation evidence');

assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'));
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);
assert.match(manifest,/'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/);
assert.ok(mainReconnect.includes("import './main-v110.js"));
assert.ok(main110.includes("import './main-v110-handoff-shell.js"));

for(const [label,source] of [['manifest',manifest],['bootstrap',bootstrap],['reconnect',mainReconnect],['main-v110',main110],['handoff',handoff]]){
  assert.ok(!source.includes('interaction-latency-coordinator.js'),label+' must not load orphaned base latency coordinator');
}

assert.ok(audit.includes("'app/assets/js/interaction-latency-coordinator.js'"));
assert.ok(audit.includes("'app/assets/js/interaction-latency-coordinator-v101.js'"),
  'v101 historical coordinator classification must remain intact');
assert.ok(Number(baseline.scanned_files)<=699);
assert.ok(Number(baseline.cyrillic_lines_total)<=1722);
assert.ok(Number(baseline.by_scope?.client)<=69);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 base interaction-latency reachability: OK — predecessor coordinator is orphaned and factual v110 remains on canonical main ownership.');
