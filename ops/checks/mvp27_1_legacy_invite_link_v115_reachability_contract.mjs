import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const LEGACY = 'app/assets/js/games/invite-link-entry-v115.js';
const ACTIVE = 'app/assets/js/games/invite-link-entry-v110r12.js';

const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collect(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collect(full, out);
    else if (entry.isFile() && ['.js','.php','.html'].includes(path.extname(entry.name))) out.push(normalized(full));
  }
  return out;
}
const sources = collect('app').sort();
const refs = sources
  .filter(file => file !== LEGACY)
  .filter(file => read(file).includes('invite-link-entry-v115.js'))
  .sort();

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const v110 = read('app/v110.php');
const bootstrapCore = read('app/assets/js/app-bootstrap-v2-core.js');
const manifest = read('app/runtime/client/version-manifest.php');
const reconnect = read('app/assets/js/main-v110-reconnect-v174.js');
const main110 = read('app/assets/js/main-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const legacyMain = read('app/assets/js/main.js');
const legacy = read(LEGACY);
const active = read(ACTIVE);
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on accepted v110');
assert.ok(v110.includes('<script type="module" src="./assets/js/main.js?v=98.4-wallet-15-3"></script>'),
  'v110 must retain stripped legacy main.js anchor as forensic evidence');
assert.ok(v110.includes('$html = str_replace($entryScriptsAnchor, $bootstrapTag, $html);'),
  'v110 must retain canonical bootstrap replacement');
assert.ok(v110.includes("header('X-MGW-Client-Bootstrap: v2-single-owner');"),
  'v110 must retain the accepted single-owner bootstrap marker');
assert.deepEqual(
  [...bootstrapCore.matchAll(/(?:await\s+)?import\((['"])([^'"]+)\1\)/g)].map(match => match[2]),
  ['@mgw/clean-entry', '@mgw/main'],
  'Bootstrap core must sequence only canonical clean-entry and main owners'
);
assert.ok(!bootstrapCore.includes('./main.js'),
  'Canonical bootstrap core must not import stripped physical main.js directly');
assert.match(manifest, /'@mgw\/main'\s*=>\s*'\.\/assets\/js\/main-v110-reconnect-v174\.js[^']*'/,
  'Manifest must retain accepted v110 main owner');
assert.ok(reconnect.includes("import './main-v110.js"), 'Reconnect owner must delegate to main-v110');
assert.ok(main110.includes("import './main-v110-handoff-shell.js"), 'main-v110 must delegate to factual handoff');

assert.ok(handoff.includes("from './games/invite-link-entry-v110r12.js?v=1124&mvp24=room-copy-removed-v1'"),
  'Factual v110 handoff must retain active invite-link v110r12 ownership');
assert.ok(handoff.includes('await openIncomingInviteFromTelegram();'),
  'Factual v110 handoff must retain invite-link invocation');
assert.ok(manifest.includes("'./assets/js/games/invite-link-entry-v110r12.js?v=1124&mvp24=room-copy-removed-v1' => './assets/js/games/invite-link-entry-v110r12.js?v=1125&mvp24=room-copy-removed-v1&mvp27_1=active-shell-copy-v1'"),
  'Manifest must publish active localized invite-link v110r12 identity');

assert.equal(countCyrillicLines(active), 0, 'Active invite-link v110r12 owner must remain free of hardcoded Cyrillic');
assert.ok(active.includes("from '@mgw/i18n'"), 'Active invite-link v110r12 owner must consume canonical i18n');

assert.equal(countCyrillicLines(legacy), 10, 'Legacy invite-link v115 Cyrillic evidence count changed');
assert.ok(legacy.includes('export async function openIncomingInviteFromTelegram()'),
  'Legacy invite-link v115 implementation evidence must remain intact');
assert.deepEqual(refs, ['app/assets/js/main.js'], 'Legacy invite-link v115 owner set changed; it must remain confined to stripped main.js');
assert.ok(legacyMain.includes("from './games/invite-link-entry-v115.js?v=d1'"),
  'Stripped legacy main.js must retain invite-link v115 import evidence');
assert.ok(legacyMain.includes('await openIncomingInviteFromTelegram();'),
  'Stripped legacy main.js must retain invite-link v115 invocation evidence');
assert.ok(!manifest.includes('invite-link-entry-v115.js'), 'Version manifest must not map or publish legacy invite-link v115');
for (const [label, source] of [
  ['v110 entry', v110],
  ['bootstrap core', bootstrapCore],
  ['reconnect main', reconnect],
  ['main-v110', main110],
  ['factual handoff', handoff],
]) assert.ok(!source.includes('invite-link-entry-v115.js'), label + ' must not load legacy invite-link v115');

assert.ok(audit.includes("'app/assets/js/games/invite-link-entry-v115.js'"),
  'Hardcoded-text audit must classify stripped legacy invite-link v115');

assert.ok(Number(baseline.scanned_files) <= 719, 'Invite-link v115 successor must not restore classified runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 1934, 'Invite-link v115 successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 281, 'Invite-link v115 successor client debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy invite-link v115 reachability: OK — v115 is confined to stripped legacy main.js; factual v110 uses localized v110r12 ownership.');
