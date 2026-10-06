import fs from 'node:fs';

const CYR=/[\u0400-\u04FF]/;
const baseline=JSON.parse(fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_baseline.json','utf8'));
const audit=fs.readFileSync('ops/checks/mvp27_1_hardcoded_text_audit.mjs','utf8');

function assert(ok,msg){ if(!ok) throw new Error(msg); }
function cyrLines(path){
  return fs.readFileSync(path,'utf8').split(/\r?\n/).filter(line=>CYR.test(line)).length;
}

const classified={
  'bot/admin-incident.php':20,
  'bot/admin-operations.php':16,
  'bot/admin-system.php':10,
  'bot/admin-tournaments.php':7,
  'bot/admin-replay.php':6,
  'bot/admin-analytics.php':5,
  'bot/admin-support.php':5,
  'bot/admin-rating.php':4,
  'bot/admin-read.php':4,
  'bot/admin-reports.php':4,
  'bot/admin-test-coins.php':4,
  'bot/admin-compensation.php':3,
  'bot/admin-economy.php':3,
  'bot/admin-notifications.php':3,
  'bot/economy/CompensationService.php':35,
  'bot/antifraud/AntiFraudCaseService.php':31,
  'bot/system/SystemAdminService.php':16,
  'bot/system/RuntimeFeatureFlagAdminService.php':5,
  'bot/analytics/ProductEconomyAnalyticsService.php':4,
  'bot/services/StagingAdminTestCoinGrantService.php':9,
  'bot/tournaments/StagingTournamentManualAcceptanceService.php':24,
  'bot/tournaments/TournamentAdminNotificationBridge.php':3,
  'bot/notifications/AdminNotificationEventService.php':16,
  'bot/support/SupportTelegramNotifier.php':22,
};

assert(typeof baseline?.staging_base==='string' && /^[0-9a-f]{40}$/.test(baseline.staging_base),'Classification ratchet must bind to an exact staging SHA.');
assert(Number.isInteger(Number(baseline?.scanned_files)) && Number(baseline.scanned_files)>0 && Number(baseline.scanned_files)<=655,'Successor classification may reduce audited runtime coverage only through explicit ownership proof.');
assert(Number(baseline?.cyrillic_lines_total)<=589,'Successor localization/classification may only reduce total debt from 589.');
assert(Number(baseline?.by_scope?.backend)<=589,'Successor localization/classification may only reduce backend debt from 589.');
assert(baseline?.by_scope?.client===0 && baseline?.by_scope?.['client-entry']===0,'Client localization debt must remain zero.');

let total=0;
for(const [path,expected] of Object.entries(classified)){
  const actual=cyrLines(path);
  assert(actual===expected,`Classification evidence drift for ${path}: expected ${expected}, got ${actual}`);
  assert(audit.includes(`'${path}'`),`Audit must explicitly classify ${path} out of player-facing debt.`);
  total+=actual;
}
assert(Object.keys(classified).length===24,'Expected exactly 24 classified source files.');
assert(total===259,'Expected exactly 259 classified Cyrillic lines.');

console.log('MVP27_1_BACKEND_NONPLAYER_CLASSIFICATION_BUNDLE=PASS');
console.log('classified_files=24');
console.log('classified_cyrillic_lines=259');
console.log(`backend_player_debt=${baseline.by_scope.backend}`);
