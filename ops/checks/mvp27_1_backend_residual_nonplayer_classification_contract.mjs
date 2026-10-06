import fs from 'node:fs';

const CLASSIFICATION='ops/checks/mvp27_1_backend_nonplayer_line_classification.json';
const BASELINE='ops/checks/mvp27_1_hardcoded_text_baseline.json';
const AUDIT='ops/checks/mvp27_1_hardcoded_text_audit.mjs';
const LOCALE='app/locales/ru.json';
const EXPECTED_BASE='554ab8b738d1bd7ff7776558684d89d24093e597';
const FINAL_SUCCESSOR_BASE='b4377c3cc783f9ecc6ded470deb115a0ade81628';
const finalSuccessor=String(process.env.GITHUB_HEAD_REF||'').startsWith('agent/mvp27-1-backend-final-player-localization-');

function assert(ok,msg){ if(!ok) throw new Error(msg); }
const cfg=JSON.parse(fs.readFileSync(CLASSIFICATION,'utf8'));
const baseline=JSON.parse(fs.readFileSync(BASELINE,'utf8'));
const locale=JSON.parse(fs.readFileSync(LOCALE,'utf8'));
const audit=fs.readFileSync(AUDIT,'utf8');

assert(cfg.schema_version===1,'Classification schema mismatch.');
assert(cfg.staging_base===EXPECTED_BASE,'Classification must bind to exact post-#2088 staging.');
assert(cfg.classified_files===17,'Expected 17 classified source files.');
assert(cfg.classified_cyrillic_lines===186,'Expected exactly 186 classified Cyrillic lines.');
assert(
  baseline.staging_base===(finalSuccessor?FINAL_SUCCESSOR_BASE:EXPECTED_BASE),
  finalSuccessor ? 'Final successor baseline must bind to exact post-#2090 staging.' : 'Baseline must bind to exact post-#2088 staging.'
);
assert(baseline.scanned_files===636,'Line classification must not reduce scanned runtime file coverage.');
if(finalSuccessor){
  assert(baseline.cyrillic_lines_total===0 && baseline.by_scope?.backend===0,'Final successor must ratchet backend player debt 199 -> 0.');
  assert(Number(locale?._meta?.version)===75,'Final successor must publish RU locale version 75.');
}else{
  assert(baseline.cyrillic_lines_total===199 && baseline.by_scope?.backend===199,'Expected backend debt 385 -> 199.');
  assert(Number(locale?._meta?.version)===74,'Classification-only PR must not change RU locale version.');
}
assert(baseline.by_scope?.client===0 && baseline.by_scope?.['client-entry']===0,'Client localization debt must remain zero.');
assert(audit.includes('MVP27_1_CLASSIFIED_BACKEND_NONPLAYER_LINES'),'Audit must expose classified-line count.');
assert(audit.includes(CLASSIFICATION),'Audit must consume exact classification evidence.');

let total=0;
for(const [file,entry] of Object.entries(cfg.files||{})){
  const rawLines=fs.readFileSync(file,'utf8').split(/\r?\n/);
  const scoped=[];
  let currentFunction='';
  for(const raw of rawLines){
    const functionMatch=raw.match(/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/);
    if(functionMatch) currentFunction=functionMatch[1];
    scoped.push({text:raw.trim(),function:currentFunction});
  }
  let fileTotal=0;
  for(const occurrence of entry.occurrences||[]){
    const expectedFunction=String(occurrence.function||'');
    const actual=scoped.filter(line=>line.text===occurrence.text && (expectedFunction==='' || line.function===expectedFunction)).length;
    assert(actual===occurrence.count,`Fingerprint drift in ${file}: expected ${occurrence.count}, got ${actual}: ${expectedFunction} :: ${occurrence.text}`);
    assert(/[\u0400-\u04FF]/.test(occurrence.text),`Classified fingerprint must contain Cyrillic: ${file}`);
    fileTotal+=actual;
  }
  assert(fileTotal===entry.classified_lines,`Classified line count drift in ${file}: expected ${entry.classified_lines}, got ${fileTotal}`);
  total+=fileTotal;
}
assert(total===186,'Classification evidence must sum to 186 lines.');

console.log('MVP27_1_BACKEND_RESIDUAL_NONPLAYER_CLASSIFICATION=PASS');
console.log('classified_files=17');
console.log('classified_cyrillic_lines=186');
console.log(`backend_player_debt=${finalSuccessor?0:199}`);
console.log('scanned_files=636');
