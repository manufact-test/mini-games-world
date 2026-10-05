import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const TARGET='app/assets/js/production-tictactoe-turn-fix.js';
const LEGACY_ENTRY='app/assets/js/production-regression-fix-entry.js';
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
const clean110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const target=read(TARGET);
const legacyEntry=read(LEGACY_ENTRY);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"),'Telegram launch must remain on v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/production-regression-fix-entry.js?v=102"></script>'),
  'v110 legacy regression-entry anchor must remain present as replacement evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must strip the legacy regression/main entry pair in favor of canonical bootstrap');
assert.deepEqual([...bootstrap.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),['@mgw/clean-entry','@mgw/main']);

assert.deepEqual(refsTo('production-tictactoe-turn-fix.js',[TARGET]),[LEGACY_ENTRY],
  'Tic-Tac-Toe turn-fix owner set changed');
assert.deepEqual(refsTo('initTicTacToeTurnFixEarly',[TARGET]),[LEGACY_ENTRY],
  'Tic-Tac-Toe early initializer owner set changed');
assert.deepEqual(refsTo('scheduleTicTacToeTurnFixAfter',[TARGET]),[LEGACY_ENTRY],
  'Tic-Tac-Toe delayed initializer owner set changed');

assert.ok(legacyEntry.includes("from './production-tictactoe-turn-fix.js?v=94'"),
  'Legacy regression entry must retain turn-fix import evidence');
assert.ok(legacyEntry.includes('initTicTacToeTurnFixEarly();'),
  'Legacy regression entry must retain early turn-fix initialization evidence');
assert.ok(legacyEntry.includes('scheduleTicTacToeTurnFixAfter();'),
  'Legacy regression entry must retain delayed turn-fix initialization evidence');

for(const [label,source] of [
  ['manifest',manifest],['bootstrap',bootstrap],['factual v110 clean entry',clean110],['factual v110 handoff',handoff]
]){
  assert.ok(!source.includes('production-tictactoe-turn-fix.js'),label+' must not load stripped legacy Tic-Tac-Toe turn fix');
  assert.ok(!source.includes('initTicTacToeTurnFixEarly'),label+' must not initialize stripped legacy Tic-Tac-Toe turn fix');
  assert.ok(!source.includes('scheduleTicTacToeTurnFixAfter'),label+' must not schedule stripped legacy Tic-Tac-Toe turn fix');
}

assert.equal(countCyrillicLines(target),3,'Historical Tic-Tac-Toe turn-fix Cyrillic evidence count changed');
assert.ok(target.includes("'Не удалось выполнить ход.'"));
assert.ok(target.includes("'Игра завершена'"));
assert.ok(target.includes("'Ваш ход'")&&target.includes("'Ход соперника'"));

assert.ok(audit.includes("'app/assets/js/production-tictactoe-turn-fix.js'"),
  'Audit must classify stripped legacy Tic-Tac-Toe turn fix');
assert.ok(Number(baseline.scanned_files)<=696);
assert.ok(Number(baseline.cyrillic_lines_total)<=1707);
assert.ok(Number(baseline.by_scope?.client)<=54);
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 Tic-Tac-Toe turn-fix reachability: OK — sole owner is stripped production-regression-fix-entry.js; factual v110 stays on canonical owners.');
