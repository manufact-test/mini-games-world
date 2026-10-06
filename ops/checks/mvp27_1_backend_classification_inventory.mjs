import fs from 'node:fs';
import path from 'node:path';

const CYR = /[\u0400-\u04FF]/;
const TEXT_EXT = new Set(['.php','.js','.mjs','.json','.md']);
const SKIP_DIRS = new Set(['.git','node_modules','vendor']);
const AUDIT_SKIP_PARTS = new Set(['tests','test','database','migrations','admin']);
const AUDIT_SKIP_PREFIXES = ['bot/tests/','bot/incident/'];
const ADMIN_PATTERNS = [
  /^bot\/services\/Admin[^/]*\.php$/,
  /^bot\/helpers\/Admin[^/]*\.php$/,
  /^bot\/operations\/Admin[^/]*\.php$/,
];

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
function auditIncluded(file){
  if(!file.startsWith('bot/') || !file.endsWith('.php')) return false;
  if(AUDIT_SKIP_PREFIXES.some(p=>file.startsWith(p))) return false;
  if(ADMIN_PATTERNS.some(r=>r.test(file))) return false;
  return !file.split('/').some(part=>AUDIT_SKIP_PARTS.has(part));
}
function cyrLines(content){
  return content.split(/\r?\n/).filter(line=>CYR.test(line)).length;
}
function symbols(content){
  return [...content.matchAll(/\b(?:final\s+)?(?:class|trait|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/g)].map(m=>m[1]);
}
function isRootEndpoint(file){ return /^bot\/[^/]+\.php$/.test(file); }
function isAdminRoot(file){ return /^bot\/admin(?:-|\.)/.test(file); }

const all = walk('.');
const contents = new Map();
for(const f of all){
  try { contents.set(f,fs.readFileSync(f,'utf8')); } catch {}
}
const backend = [...contents.keys()].filter(auditIncluded);
const findings = backend.map(file=>{
  const content=contents.get(file)||'';
  return {file,count:cyrLines(content),symbols:symbols(content)};
}).filter(x=>x.count>0).sort((a,b)=>b.count-a.count||a.file.localeCompare(b.file));

const symbolOwner = new Map();
for(const item of findings) for(const s of item.symbols) symbolOwner.set(s,item.file);

const phpFiles = [...contents.keys()].filter(f=>f.startsWith('bot/')&&f.endsWith('.php'));
const appFiles = [...contents.keys()].filter(f=>f.startsWith('app/')&&(f.endsWith('.js')||f.endsWith('.php')));

const edges = new Map();
for(const src of phpFiles){
  const text=contents.get(src)||'';
  const set=new Set();
  for(const [symbol,dst] of symbolOwner){
    if(src===dst) continue;
    if(new RegExp('\\b'+symbol+'\\b').test(text)) set.add(dst);
  }
  edges.set(src,set);
}

const rootAppRefs = new Map();
for(const root of phpFiles.filter(isRootEndpoint)){
  const base=path.basename(root);
  const refs=[];
  for(const f of appFiles){
    const text=contents.get(f)||'';
    if(text.includes('/bot/'+base)||text.includes(base)) refs.push(f);
  }
  rootAppRefs.set(root,refs);
}

function rootsReaching(target){
  const roots=phpFiles.filter(isRootEndpoint);
  const reached=[];
  for(const root of roots){
    const seen=new Set([root]);
    const q=[root];
    let ok=root===target;
    while(q.length && !ok){
      const cur=q.shift();
      for(const nxt of edges.get(cur)||[]){
        if(seen.has(nxt)) continue;
        seen.add(nxt);
        if(nxt===target){ ok=true; break; }
        q.push(nxt);
      }
    }
    if(ok) reached.push(root);
  }
  return reached;
}

console.log('MVP27_1_BACKEND_CLASSIFICATION_INVENTORY=BEGIN');
console.log('STAGING_EXPECTED=f4dcfdec0bc6214cc3c32fb37ada9434493d8c13');
console.log('BACKEND_RAW_CYRILLIC_TOTAL='+findings.reduce((s,x)=>s+x.count,0));
console.log('BACKEND_FILES_WITH_CYRILLIC='+findings.length);

for(const item of findings){
  const inbound=[];
  const names=new Set([...item.symbols,path.basename(item.file)]);
  for(const f of phpFiles){
    if(f===item.file) continue;
    const src=contents.get(f)||'';
    if([...names].some(n=>n && src.includes(n))) inbound.push(f);
  }
  const roots=rootsReaching(item.file);
  const playerRoots=roots.filter(r=>(rootAppRefs.get(r)||[]).length>0 && !isAdminRoot(r));
  const adminRoots=roots.filter(isAdminRoot);
  const directAppRefs=isRootEndpoint(item.file)?(rootAppRefs.get(item.file)||[]):[];
  console.log(JSON.stringify({
    file:item.file,
    cyrillic_lines:item.count,
    symbols:item.symbols,
    direct_inbound:inbound.slice(0,20),
    reachable_root_endpoints:roots.slice(0,30),
    app_referenced_player_roots:playerRoots.slice(0,20),
    admin_roots:adminRoots.slice(0,20),
    direct_app_refs:directAppRefs.slice(0,20)
  }));
}
console.log('MVP27_1_BACKEND_CLASSIFICATION_INVENTORY=END');
