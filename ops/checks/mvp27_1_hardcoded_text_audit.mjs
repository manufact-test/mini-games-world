import fs from 'node:fs';
import path from 'node:path';

const CYRILLIC = /[\u0400-\u04FF]/;
const ROOTS = [
  { name:'client', path:'app/assets/js', extensions:new Set(['.js']) },
  { name:'client-entry', path:'app', extensions:new Set(['.php']) },
  { name:'backend', path:'bot', extensions:new Set(['.php']) },
];

const SKIP_PARTS = new Set([
  '.git','node_modules','vendor','docs','tests','test','database','migrations','admin',
]);
const SKIP_PREFIXES = [
  'app/assets/js/localization/',
  'app/locales/',
  'app/runtime/localization/',
  'app/runtime/server/', // staging-only rejected clean-runtime server; not reachable from factual Telegram v110 graph
  'bot/tests/',
  'bot/incident/',
];

const SKIP_FILES = new Set([
  'app/assets/js/games/invite-controller-v120.js', // rejected v120 invite owner; factual v110 graph forbids it
  'app/assets/js/games/invite-link-entry-v115.js', // stripped legacy main.js child; factual v110 uses localized v110r12
  'app/assets/js/games/battleship/entry.js', // historical Battleship setup entry; factual v110 uses unified launcher
  'app/assets/js/games/checkers/entry.js', // historical Checkers setup entry; factual v110 uses unified launcher
  'app/assets/js/games/tictactoe/entry.js', // historical Tic-Tac-Toe setup entry; factual v110 uses unified launcher
  'app/assets/js/games/domino/entry.js', // historical Domino setup child of stripped legacy main.js
  'app/assets/js/games/four-in-a-row/entry.js', // historical Four-in-a-row setup entry; factual v110 uses unified launcher
  'app/assets/js/games/reversi/entry.js', // historical Reversi setup entry; factual v110 no longer initializes it
  'app/assets/js/games/go/entry.js', // historical Go setup entry; factual v110 uses unified launcher
  'app/assets/js/games/chess/entry.js', // historical Chess setup entry; factual v110 uses unified launcher
  'app/assets/js/games/game-invites.js', // superseded by factual game-invites-v110.js owner
  'app/assets/js/screens/game-screen.js', // shadowed after invite polling converges on canonical game-screen-v102-safe owner
  'app/assets/js/residual-ui-game-race-fix.js', // shadowed legacy hotfix; factual v110 strips the only main.js entry that imports it
  'app/assets/js/production-v104-invite-game-controls.js', // historical v104/v105/v105-fast/v107-v109 invite controls; factual v110 uses canonical game-invites-v110
  'app/assets/js/production-v104-result-instant.js', // historical result-speed overlay owned only by legacy clean-entry revisions; absent from factual v110
  'app/assets/js/production-v109-share-speed.js', // historical v109 share-speed overlay; factual v110 uses canonical game-invites-v110 share flow
  'app/assets/js/production-v108-share.js', // abandoned v108 share owner under ownerless clean-entry-v108; factual v110 uses localized game-invites-v110
  'app/assets/js/production-v108-notifications.js', // abandoned v108 notification owner under ownerless clean-entry-v108; factual v110 uses localized r13 notification screen
  'app/assets/js/production-cross-game-coordinator.js', // historical child of stripped production-regression-fix-entry.js; absent from factual canonical v110 graph
  'app/assets/js/production-v105-invite-latency.js', // historical v105-v109 invite owner; factual v110 uses canonical game-invites-v110 instead
  'app/assets/js/production-v106-invite-actions.js', // historical v106-only action owner; replaced before v107 and absent from factual v110
  'app/assets/js/production-v106-self-toast-policy.js', // historical v106-v108 self-toast policy; absent from v109 and factual v110 clean-entry ownership
  'app/assets/js/production-v107-invite-actions.js', // historical v107/v108 invite-action owner; factual v110 uses canonical game-invites-v110
  'app/assets/js/production-v109-invite-speed.js', // historical v109 capture owner; factual v110 uses canonical game-invites-v110 instead
  'app/assets/js/production-v97-runtime-owner.js', // historical v97/v98 coordinator; factual v110 bootstrap/clean-entry graph does not load it
  'app/assets/js/production-v98-ui-owner.js', // historical v98-only UI owner; sole owner is v98 regression entry and factual v110 uses later canonical owners
  'app/assets/js/production-ui-stability-fix.js', // historical v96-v98 UI stability owner; factual v110 strips the regression entry lineage
  'app/assets/js/production-prepared-share-fix.js', // historical v97/v98 prepared-share owner; factual v110 uses canonical invite/share ownership
  'app/assets/js/first-interaction-readiness.js', // historical child of stripped legacy main.js; absent from factual v110 ownership
  'app/assets/js/interaction-latency-coordinator-v101.js', // historical latency coordinator owned by stripped main.js plus historical v114 rewrite; factual v110 uses canonical owners
  'app/assets/js/interaction-latency-coordinator.js', // orphaned predecessor latency coordinator; exhaustive app-source scan has no external owner
  'app/assets/js/production-session-ownership-fix.js', // historical pre-v110 clean/regression session owner after factual v110 migrates to localized v110 successor
  'app/assets/js/production-v103-targeted-interactions.js', // historical v103-v109 targeted-interactions owner; factual v110 uses production-v110-targeted-interactions.js
  'app/assets/js/screens/notification-empty-frame-guard-v115.js', // orphaned notification empty-frame guard v115; exhaustive app-source scan finds no external owner
  'app/assets/js/production-tictactoe-turn-fix.js', // stripped legacy Tic-Tac-Toe turn fix; sole owner is production-regression-fix-entry.js removed from factual v110 bootstrap
  'app/assets/js/production-v107-timer-pvp.js', // historical v107 timer PVP owner loaded only by historical v107 clean-entry/page lineage; absent from factual v110
  'app/assets/js/production-v108-live-game.js', // abandoned v108 live-game owner loaded only by ownerless production-clean-entry-v108.js; historical v108 stays on v105-fast rollback
  'app/assets/js/screens/game-screen-v98.js', // historical v98 game screen owned only by production-v98-ui-owner.js -> historical v98 regression/page lineage; absent from factual v110
  'app/assets/js/games/invite-terminal-actions-v110r12.js', // orphaned invite terminal actions revision; exhaustive app-source scan finds no external owner
  'app/assets/js/games/invite-terminal-actions-v115.js', // owned only by stripped legacy main.js; factual v110 replaces legacy entry scripts with canonical bootstrap
  'app/assets/js/main.js', // stripped legacy main entry; factual v110 replaces regression/main pair with app-bootstrap-v2
  'app/assets/js/production-v106-timer-mobile.js', // historical v106 timer/mobile owner; sole owner is historical clean-entry-v106, absent from factual v110 clean-entry
  'app/assets/js/production-v98-passive-session-transport.js', // historical v98 passive-session owner; sole owner is stripped v98 regression entry
  'app/assets/js/screens/notification-bell-first-click-v116.js', // orphaned notification bell first-click revision; exhaustive app-source scan finds no external owner
  'app/assets/js/production-v109-notifications.js', // historical v109 notification owner; factual v110 uses localized r13 notification screen
  'app/assets/js/production-v105-fast-notifications.js', // historical v108-only fast notification owner; factual v110 uses localized r13 notification screen
  'app/assets/js/screens/notifications-screen.js', // historical notification screen used only by legacy main-v99..v105
  'app/assets/js/screens/notifications-screen-v99.js', // historical notification screen owned by stripped legacy main.js
  'app/assets/js/screens/profile-screen.js', // historical Profile screen owned only by stripped/historical main.js and main-v99..v105; factual v110 uses localized profile-screen-v110.js
  'app/assets/js/screens/notifications-screen-v110-root.js', // superseded historical notification screen revision
  'app/assets/js/screens/notifications-screen-v110.js', // superseded historical notification screen revision
  'app/assets/js/screens/notifications-screen-v110r4.js', // superseded historical notification screen revision
  'app/assets/js/screens/notifications-screen-v110r5.js', // superseded historical notification screen revision
  'app/assets/js/screens/notifications-screen-v110r12.js', // superseded by factual localized notifications-screen-v110r13
  'app/assets/js/screens/notification-desktop-open-owner-v117.js', // orphaned historical desktop notification open owner; former v114 injection is gone and factual v110 uses localized notifications-screen-v110r13
  'app/assets/js/screens/notification-mobile-open-owner-v117.js', // orphaned historical mobile notification open owner; former v114 injection is gone and factual v110 uses localized notifications-screen-v110r13
  'app/assets/js/screens/notification-window-owner-v118.js', // orphaned historical notification-window v118 owner; no current app source loads it and factual v110 uses localized notifications-screen-v110r13
  'app/assets/js/screens/notification-window-owner-v119.js', // legacy notification-window owner; absent from factual localized v110 notification ownership
  'app/assets/js/profile/mgw-profile-victory-effects.js', // physical legacy Victory Effects source; canonical manifest key resolves to localized card-parity -> v4 owner
  'app/assets/js/production-v102-history-controller.js', // historical v102-v109 history owner; factual v110 graph no longer loads it
  'app/assets/js/phase-b-current-entry.js', // historical Phase-B entry; absent from factual v110 top-level ownership
  'app/assets/js/phase-b-current-runtime.js', // reachable only from historical Phase-B entry
  'app/assets/js/screens/game-screen-phase-b-current.js', // reachable only from historical Phase-B runtime
  'app/assets/js/main-v99.js', // historical v99 entry main; factual Telegram launch is v110
  'app/assets/js/main-v100.js', // historical v100 entry main; factual Telegram launch is v110
  'app/assets/js/main-v101.js', // historical v101 entry main; factual Telegram launch is v110
  'app/assets/js/production-v101-result-speed.js', // historical v101 result owner loaded only by historical clean-entry-v101; imports v100-safe game screen
  'app/assets/js/production-v100-share-controller.js', // historical v100 share owner loaded only by historical clean-entry-v100
  'app/assets/js/production-v101-share-controller.js', // historical v101 share owner loaded only by historical clean-entry-v101
  'app/assets/js/production-v102-share-controller.js', // historical v102 share owner reachable only through historical v102-v108 clean-entry lineages
  'app/assets/js/screens/search-screen-v99.js', // historical v99 search owner reachable only from main-v99; imports v99 game screen
  'app/assets/js/screens/game-screen-v99.js', // reachable only from historical v99 main
  'app/assets/js/screens/search-screen-v100.js', // historical v100/v101 search owner; imports v100-safe game screen
  'app/assets/js/screens/game-screen-v100-safe.js', // historical wrapper reachable only from v100/v101 mains
  'app/assets/js/screens/game-screen-v100.js', // reachable only through historical v100-safe wrapper
]);

const ADMIN_CONTROL_PLANE_PATTERNS = [
  /^app\/admin\.php$/,
  /^app\/assets\/js\/admin(?:-|\/)/,
  /^bot\/services\/Admin[^/]*\.php$/,
  /^bot\/helpers\/Admin[^/]*\.php$/,
  /^bot\/operations\/Admin[^/]*\.php$/,
];

function normalized(file) {
  return file.split(path.sep).join('/');
}

function shouldSkip(file) {
  const n = normalized(file);
  if (SKIP_FILES.has(n)) return true;
  if (SKIP_PREFIXES.some(prefix => n.startsWith(prefix))) return true;
  if (ADMIN_CONTROL_PLANE_PATTERNS.some(pattern => pattern.test(n))) return true;
  return n.split('/').some(part => SKIP_PARTS.has(part));
}

function collect(dir, extensions, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (shouldSkip(full)) continue;
    if (entry.isDirectory()) collect(full, extensions, out);
    else if (extensions.has(path.extname(entry.name))) out.push(full);
  }
  return out;
}

const baselinePath = 'ops/checks/mvp27_1_hardcoded_text_baseline.json';
const baseline = fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
  : null;

const findings = [];
const scanned = new Set();

for (const root of ROOTS) {
  for (const file of collect(root.path, root.extensions)) {
    const n = normalized(file);
    if (scanned.has(n)) continue;
    scanned.add(n);
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    lines.forEach((line, index) => {
      if (!CYRILLIC.test(line)) return;
      findings.push({ scope:root.name, file:n, line:index + 1, text:line.trim().slice(0,220) });
    });
  }
}

const byScope = new Map();
const byFile = new Map();
for (const finding of findings) {
  byScope.set(finding.scope, (byScope.get(finding.scope) || 0) + 1);
  byFile.set(finding.file, (byFile.get(finding.file) || 0) + 1);
}

const topFiles = [...byFile.entries()]
  .sort((a,b) => b[1] - a[1] || a[0].localeCompare(b[0]))
  .slice(0,40);

console.log('MVP27_1_HARDCODED_TEXT_AUDIT=PASS');
console.log(`MVP27_1_SCANNED_FILES=${scanned.size}`);
console.log(`MVP27_1_CYRILLIC_LINES_TOTAL=${findings.length}`);
for (const [scope,count] of [...byScope.entries()].sort()) {
  console.log(`MVP27_1_CYRILLIC_LINES_${scope.toUpperCase().replace(/[^A-Z0-9]+/g,'_')}=${count}`);
}
console.log('MVP27_1_TOP_FILES_BEGIN');
for (const [file,count] of topFiles) console.log(`${String(count).padStart(4,' ')}  ${file}`);
console.log('MVP27_1_TOP_FILES_END');

if (process.argv.includes('--sample')) {
  console.log('MVP27_1_SAMPLE_BEGIN');
  for (const finding of findings.slice(0,120)) {
    console.log(`${finding.scope}\t${finding.file}:${finding.line}\t${finding.text}`);
  }
  console.log('MVP27_1_SAMPLE_END');
}

if (scanned.size === 0) throw new Error('Localization audit scanned no runtime files.');

if (baseline) {
  const limits = [
    ['total', findings.length, Number(baseline.cyrillic_lines_total)],
    ['client', byScope.get('client') || 0, Number(baseline.by_scope?.client)],
    ['backend', byScope.get('backend') || 0, Number(baseline.by_scope?.backend)],
    ['client-entry', byScope.get('client-entry') || 0, Number(baseline.by_scope?.['client-entry'])],
  ];
  for (const [label,current,limit] of limits) {
    if (!Number.isFinite(limit)) throw new Error(`Invalid localization baseline for ${label}.`);
    if (current > limit) {
      throw new Error(`Localization debt regression: ${label} increased from baseline ${limit} to ${current}.`);
    }
  }
  console.log('MVP27_1_BASELINE_RATCHET=PASS');
}


if (true) {
  const targets = [
    'app/assets/js/api/request-guard.js',
    'app/assets/js/commerce/mgw-purchase-feedback.js',
    'app/assets/js/main-v102.js',
    'app/assets/js/main-v103.js',
    'app/assets/js/main-v104.js',
    'app/assets/js/main-v105.js',
    'app/assets/js/main-v120-invite-controller-shell.js',
    'app/assets/js/production-v110-match-lifecycle.js',
    'app/assets/js/production-v99-explicit-lock-guard.js',
    'app/assets/js/production-v99-invite-picker-hold.js',
    'app/assets/js/profile/mgw-avatar-registry.js',
    'app/assets/js/profile/mgw-victory-effect-selector.js',
    'app/assets/js/screens/notification-bell-first-click-v115.js',
    'app/assets/js/screens/search-screen.js',
    'app/assets/js/session.js',
  ];
  const allowedExt = new Set(['.js','.php','.html','.mjs']);
  const allSources = [];
  const walkAll = dir => {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir,{withFileTypes:true})) {
      const full = path.join(dir,entry.name);
      if (entry.isDirectory()) walkAll(full);
      else if (allowedExt.has(path.extname(entry.name))) allSources.push(normalized(full));
    }
  };
  walkAll('app');
  console.log('MVP27_1_FINAL15_OWNER_DIAG_BEGIN');
  for (const target of targets) {
    const basename = path.basename(target);
    const targetSource = fs.readFileSync(target,'utf8');
    const cyr = targetSource.split(/\r?\n/).map((line,i)=>({line:i+1,text:line.trim()})).filter(x=>CYRILLIC.test(x.text));
    const refs = [];
    for (const source of allSources) {
      if (source === target) continue;
      const text = fs.readFileSync(source,'utf8');
      if (text.includes(basename)) refs.push(source);
    }
    console.log('TARGET '+target);
    console.log('CYR '+cyr.length);
    for (const item of cyr) console.log('RU '+item.line+' '+item.text.slice(0,220));
    console.log('REFS '+refs.length);
    for (const ref of refs.sort()) console.log('REF '+ref);
  }
  console.log('MVP27_1_FINAL15_OWNER_DIAG_END');
}
