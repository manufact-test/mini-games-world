import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';

const EXPECTED_STAGING='554ab8b738d1bd7ff7776558684d89d24093e597';
const AUDIT='ops/checks/mvp27_1_hardcoded_text_audit.mjs';
const BASELINE='ops/checks/mvp27_1_hardcoded_text_baseline.json';
const TEXT_EXT=new Set(['.php','.js','.mjs','.json','.md']);
const SKIP_DIRS=new Set(['.git','node_modules','vendor']);

function assert(ok,msg){ if(!ok) throw new Error(msg); }
function norm(p){ return p.split(path.sep).join('/'); }
function walk(dir,out=[]){
  if(!fs.existsSync(dir)) return out;
  for(const e of fs.readdirSync(dir,{withFileTypes:true})){
    if(SKIP_DIRS.has(e.name)) continue;
    const full=path.join(dir,e.name);
    if(e.isDirectory()) walk(full,out);
    else if(TEXT_EXT.has(path.extname(e.name))) out.push(norm(full));
  }
  return out;
}
function symbols(content){
  return [...content.matchAll(/\b(?:final\s+)?(?:class|trait|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/g)].map(m=>m[1]);
}
function isRootEndpoint(file){ return /^bot\/[^/]+\.php$/.test(file); }
function isAdminRoot(file){ return /^bot\/admin(?:-|\.)/.test(file); }
function isAdminApp(file){ return file==='app/admin.php' || /^app\/assets\/js\/admin(?:-|\/)/.test(file); }

const baseline=JSON.parse(fs.readFileSync(BASELINE,'utf8'));
assert(baseline.staging_base==='34baadda9695b12961b53ee33f31ef7a528beba3','Post-#2088 baseline must retain exact pre-PR ratchet owner.');
assert(baseline.scanned_files===636,'Expected 636 scanned player-runtime files after #2088.');
assert(baseline.cyrillic_lines_total===385,'Expected 385 remaining localization lines after #2088.');
assert(baseline.by_scope?.backend===385,'Expected 385 backend lines after #2088.');
assert(baseline.by_scope?.client===0 && baseline.by_scope?.['client-entry']===0,'Client debt must remain zero.');

const auditSource=fs.readFileSync(AUDIT,'utf8');
const widened=auditSource.replace('.slice(0,40);','.slice(0,100000);');
assert(widened!==auditSource,'Could not widen current audit inventory output.');
const tmp=path.join(os.tmpdir(),'mgw-mvp27-1-audit-all-'+process.pid+'.mjs');
fs.writeFileSync(tmp,widened);
const run=spawnSync(process.execPath,[tmp],{cwd:process.cwd(),encoding:'utf8'});
try{ fs.unlinkSync(tmp); }catch{}
if(run.status!==0){
  process.stderr.write(run.stdout||'');
  process.stderr.write(run.stderr||'');
  throw new Error('Current hardcoded-text audit failed.');
}
const auditOut=String(run.stdout||'');
function metric(name){
  const m=auditOut.match(new RegExp('^'+name+'=(\\d+)$','m'));
  assert(m,'Missing audit metric '+name);
  return Number(m[1]);
}
assert(metric('MVP27_1_SCANNED_FILES')===636,'Fresh audit scan count drifted from 636.');
assert(metric('MVP27_1_CYRILLIC_LINES_TOTAL')===385,'Fresh audit total drifted from 385.');
assert(metric('MVP27_1_CYRILLIC_LINES_BACKEND')===385,'Fresh backend debt drifted from 385.');
assert(metric('MVP27_1_CYRILLIC_LINES_CLIENT')===0,'Client debt regressed.');
assert(metric('MVP27_1_CYRILLIC_LINES_CLIENT_ENTRY')===0,'Client-entry debt regressed.');

const start=auditOut.indexOf('MVP27_1_TOP_FILES_BEGIN');
const end=auditOut.indexOf('MVP27_1_TOP_FILES_END');
assert(start>=0 && end>start,'Audit file inventory markers missing.');
const inventory=auditOut.slice(start+'MVP27_1_TOP_FILES_BEGIN'.length,end)
  .trim().split(/\r?\n/).filter(Boolean)
  .map(line=>{
    const m=line.match(/^\s*(\d+)\s+(.+)$/);
    assert(m,'Unparseable audit inventory line: '+line);
    return {count:Number(m[1]),file:m[2].trim()};
  });
assert(inventory.length>0,'No remaining backend debt files.');
assert(inventory.reduce((s,x)=>s+x.count,0)===385,'Inventory does not sum to 385.');
for(const item of inventory) assert(/^bot\/.*\.php$/.test(item.file),'Unexpected non-backend residual owner: '+item.file);

const all=walk('.');
const contents=new Map();
for(const file of all){
  try{ contents.set(file,fs.readFileSync(file,'utf8')); }catch{}
}
const phpFiles=[...contents.keys()].filter(f=>f.startsWith('bot/')&&f.endsWith('.php')&&!f.startsWith('bot/tests/'));
const appFiles=[...contents.keys()].filter(f=>f.startsWith('app/')&&(f.endsWith('.js')||f.endsWith('.php')));

const symbolOwner=new Map();
for(const file of phpFiles){
  for(const symbol of symbols(contents.get(file)||'')){
    if(!symbolOwner.has(symbol)) symbolOwner.set(symbol,file);
  }
}
const edges=new Map();
for(const src of phpFiles){
  const text=contents.get(src)||'';
  const set=new Set();
  for(const [symbol,dst] of symbolOwner){
    if(src===dst) continue;
    if(new RegExp('\\b'+symbol+'\\b').test(text)) set.add(dst);
  }
  edges.set(src,set);
}
const roots=phpFiles.filter(isRootEndpoint);
const rootAppRefs=new Map();
for(const root of roots){
  const base=path.basename(root);
  const refs=[];
  for(const file of appFiles){
    const text=contents.get(file)||'';
    if(text.includes('/bot/'+base) || text.includes(base)) refs.push(file);
  }
  rootAppRefs.set(root,refs);
}
function rootsReaching(target){
  const reached=[];
  for(const root of roots){
    const seen=new Set([root]);
    const q=[root];
    let ok=root===target;
    while(q.length&&!ok){
      const cur=q.shift();
      for(const next of edges.get(cur)||[]){
        if(seen.has(next)) continue;
        seen.add(next);
        if(next===target){ ok=true; break; }
        q.push(next);
      }
    }
    if(ok) reached.push(root);
  }
  return reached;
}

console.log('MVP27_1_BACKEND_CLASSIFICATION_INVENTORY=BEGIN');
console.log('STAGING_EXPECTED='+EXPECTED_STAGING);
console.log('BACKEND_AUDIT_CYRILLIC_TOTAL=385');
console.log('BACKEND_FILES_WITH_CYRILLIC='+inventory.length);

for(const item of inventory){
  const content=contents.get(item.file)||'';
  const itemSymbols=symbols(content);
  const inbound=[];
  const names=new Set([...itemSymbols,path.basename(item.file)]);
  for(const file of phpFiles){
    if(file===item.file) continue;
    const src=contents.get(file)||'';
    if([...names].some(n=>n&&src.includes(n))) inbound.push(file);
  }
  const reached=rootsReaching(item.file);
  const playerRoots=reached.filter(root=>
    !isAdminRoot(root) &&
    (rootAppRefs.get(root)||[]).some(ref=>!isAdminApp(ref))
  );
  const adminRoots=reached.filter(root=>
    isAdminRoot(root) ||
    (rootAppRefs.get(root)||[]).some(isAdminApp)
  );
  const directRefs=isRootEndpoint(item.file)?(rootAppRefs.get(item.file)||[]):[];
  console.log(JSON.stringify({
    file:item.file,
    cyrillic_lines:item.count,
    symbols:itemSymbols,
    direct_inbound:inbound.slice(0,30),
    reachable_root_endpoints:reached.slice(0,40),
    app_referenced_player_roots:playerRoots.slice(0,30),
    admin_roots:adminRoots.slice(0,30),
    direct_player_app_refs:directRefs.filter(ref=>!isAdminApp(ref)).slice(0,30),
    direct_admin_app_refs:directRefs.filter(isAdminApp).slice(0,30)
  }));
}
console.log('MVP27_1_BACKEND_CLASSIFICATION_INVENTORY=END');
