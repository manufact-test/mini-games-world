import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const LEGACY='app/assets/js/production-session-ownership-fix.js';
const ACTIVE='app/assets/js/production-session-ownership-fix-v110.js';
const LEGACY_OWNERS=[
  'app/assets/js/production-clean-entry-v100.js',
  'app/assets/js/production-clean-entry-v101.js',
  'app/assets/js/production-clean-entry-v102.js',
  'app/assets/js/production-clean-entry-v103.js',
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
  'app/assets/js/production-clean-entry-v109.js',
  'app/assets/js/production-clean-entry-v99.js',
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
const clean=read('app/assets/js/production-clean-entry-v110.js');
const polish=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish.js');
const wrapper=read('app/assets/js/production-clean-entry-v110-mvp19-3-final-polish-mobile-nav-v2.js');
const manifest=read('app/runtime/client/version-manifest.php');
const legacy=read(LEGACY);
const active=read(ACTIVE);
const ru=JSON.parse(read('app/locales/ru.json'));
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"));
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'));

assert.ok(clean.includes("import { initSessionOwnershipFix } from './production-session-ownership-fix-v110.js?v=1&mvp27_1=session-ownership-localized-v1';"));
assert.ok(clean.includes('initSessionOwnershipFix();'));
assert.ok(!clean.includes("from './production-session-ownership-fix.js?v=96'"));

assert.ok(polish.includes('session_ownership=localized-v1'));
assert.ok(wrapper.includes('session_ownership=localized-v1'));
assert.ok(manifest.includes("'@mgw/clean-entry' =>")&&manifest.includes('session_ownership=localized-v1'));

assert.ok(active.includes("import { t } from '@mgw/i18n';"));
assert.ok(active.includes("'network.session_ownership_stale_reconnecting'"));
assert.ok(active.includes("'network.profile_connect_failed'"));
assert.ok(active.includes("'network.telegram_identity_missing'"));
assert.ok(active.includes("'network.device_registration_failed'"));
assert.equal(countCyrillicLines(active),0);

assert.equal(ru.network.session_ownership_stale_reconnecting,'Сессия этого устройства устарела. Переподключаем профиль автоматически.');
assert.equal(ru.network.profile_connect_failed,'Не удалось подключить игровой профиль. Закройте Mini Games World и откройте снова.');
assert.equal(ru.network.telegram_identity_missing,'Telegram не передал данные аккаунта. Закройте Mini Games World и откройте снова из чата с ботом.');
assert.equal(ru.network.device_registration_failed,'Не удалось зарегистрировать это устройство. Закройте Mini Games World и откройте снова.');

assert.deepEqual(refsTo('production-session-ownership-fix.js',[LEGACY]),LEGACY_OWNERS,
  'Legacy session owner set changed after factual v110 migration');
for(const owner of LEGACY_OWNERS){
  const source=read(owner);
  assert.ok(source.includes("from './production-session-ownership-fix.js?v=96'"));
  assert.ok(source.includes('initSessionOwnershipFix();'));
}
assert.equal(countCyrillicLines(legacy),4);
assert.ok(audit.includes("'app/assets/js/production-session-ownership-fix.js'"));

for(const marker of [
  'LEGACY_SESSION_KEY','SCOPED_SESSION_PREFIX','SAFE_RETRY_ACTIONS','syncScopedSession(false)',
  'rewriteSessionId','isSafeRetryRequest','translateErrorResponse','createSessionId'
]){
  assert.ok(legacy.includes(marker)&&active.includes(marker),'Session behavior marker must survive v110 successor: '+marker);
}

assert.ok(Number(baseline.scanned_files)<=699);
assert.ok(Number(baseline.cyrillic_lines_total)<=1718);
assert.ok(Number(baseline.by_scope?.client)<=65);
assert.equal(Number(baseline.by_scope?.backend),1653);
assert.equal(Number(baseline.by_scope?.['client-entry']),0);

console.log('MVP-27.1 session ownership localization: OK — factual v110 uses localized successor; historical pre-v110 clean/regression lineages retain untouched shared owner; behavior markers preserved.');
