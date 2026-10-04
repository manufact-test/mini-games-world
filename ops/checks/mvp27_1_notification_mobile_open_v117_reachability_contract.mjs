import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const CANDIDATE = 'app/assets/js/screens/notification-mobile-open-owner-v117.js';
const ACTIVE = 'app/assets/js/screens/notifications-screen-v110r13.js';
const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collectSources(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectSources(full, out);
    else if (entry.isFile() && ['.js','.php','.html'].includes(path.extname(entry.name))) out.push(normalized(full));
  }
  return out;
}
const sources = collectSources('app').sort();
function externalRefs(needle){
  return sources.filter(file => file !== CANDIDATE && read(file).includes(needle)).sort();
}

const launch=read('bot/helpers/WebAppLaunchUrl.php');
const v110=read('app/v110.php');
const v114=read('app/v114.php');
const manifest=read('app/runtime/client/version-manifest.php');
const bootstrapCore=read('app/assets/js/app-bootstrap-v2-core.js');
const cleanV110=read('app/assets/js/production-clean-entry-v110.js');
const handoff=read('app/assets/js/main-v110-handoff-shell.js');
const candidate=read(CANDIDATE);
const active=read(ACTIVE);
const audit=read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline=JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must keep canonical bootstrap replacement');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(m=>m[2]),
  ['@mgw/clean-entry','@mgw/main'],
  'Bootstrap core must retain canonical clean-entry and main ownership'
);
assert.ok(handoff.includes("./screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Factual v110 handoff must retain r13 notification ownership');
assert.ok(manifest.includes("./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle"),
  'Version manifest must retain localized r13 notification mapping');
assert.ok(active.includes("from '@mgw/i18n'"), 'Active notification owner must retain i18n ownership');
assert.equal(countCyrillicLines(active),0,'Active r13 notification owner must remain free of hardcoded Cyrillic');

for(const [label,source] of [
  ['v110 entry',v110],
  ['v110 manifest',manifest],
  ['bootstrap core',bootstrapCore],
  ['clean entry',cleanV110],
  ['v110 handoff',handoff],
  ['active r13 notifications',active],
  ['current historical v114 page',v114],
]){
  assert.ok(!source.includes('notification-mobile-open-owner-v117.js'),
    label+' must not load the legacy mobile-open owner');
}

assert.deepEqual(externalRefs('notification-mobile-open-owner-v117.js'),[],
  'Legacy mobile notification open owner acquired a current app source owner');

assert.equal(countCyrillicLines(candidate),10,
  'Legacy mobile-open Cyrillic evidence count changed; re-prove before changing debt');
assert.ok(candidate.includes('initMobileNotificationOpenOwner();')
  && candidate.includes('function initMobileNotificationOpenOwner()'),
  'Legacy mobile-open source must retain historical implementation evidence');
assert.ok(candidate.includes("new Intl.DateTimeFormat('ru-RU'"),
  'Legacy mobile-open source must retain historical RU formatter evidence rather than being rewritten');

assert.ok(audit.includes("'app/assets/js/screens/notification-mobile-open-owner-v117.js'"),
  'Hardcoded-text audit must classify legacy mobile-open owner');
assert.ok(Number(baseline.scanned_files)<=708,'successor must not restore classified files');
assert.ok(Number(baseline.cyrillic_lines_total)<=1786,'successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client)<=133,'successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend),1653,'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']),0,'Client-entry debt must remain zero');

console.log('MVP-27.1 mobile notification open-owner reachability: OK — v117 mobile owner is orphaned historical source; factual v110 retains localized r13 notification ownership.');
