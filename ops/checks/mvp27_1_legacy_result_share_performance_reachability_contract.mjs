import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const normalized = p => p.split(path.sep).join('/');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

function collectJs(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes:true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collectJs(full, out);
    else if (entry.isFile() && entry.name.endsWith('.js')) out.push(normalized(full));
  }
  return out;
}

function refsTo(needle) {
  return collectJs('app/assets/js')
    .filter(file => read(file).includes(needle))
    .sort();
}

const launch = read('bot/helpers/WebAppLaunchUrl.php');
const manifest = read('app/runtime/client/version-manifest.php');
const cleanV110 = read('app/assets/js/production-clean-entry-v110.js');
const handoff = read('app/assets/js/main-v110-handoff-shell.js');
const activeInvites = read('app/assets/js/games/game-invites-v110.js');
const activeGameScreen = read('app/assets/js/screens/game-screen-v102-safe.js');
const resultInstant = read('app/assets/js/production-v104-result-instant.js');
const shareSpeed = read('app/assets/js/production-v109-share-speed.js');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(launch.includes("private const ENTRY_PATH = '/app/v110.php"), 'Telegram launch must remain on v110');
assert.ok(manifest.includes("./assets/js/games/game-invites-v110.js?v=1150"),
  'Manifest must retain canonical localized v110 invite ownership');
assert.ok(manifest.includes("./assets/js/screens/game-screen-v102-safe.js?v=105"),
  'Manifest must retain canonical game-screen-v102-safe ownership');

for (const [label, source] of [
  ['version manifest', manifest],
  ['factual v110 clean entry', cleanV110],
  ['factual v110 handoff', handoff],
  ['canonical game invites', activeInvites],
  ['canonical game screen', activeGameScreen],
]) {
  assert.ok(!source.includes('production-v104-result-instant.js'),
    `${label} must not import/map historical v104 result-instant owner`);
  assert.ok(!source.includes('initV104ResultInstant'),
    `${label} must not initialize historical v104 result-instant owner`);
  assert.ok(!source.includes('production-v109-share-speed.js'),
    `${label} must not import/map historical v109 share-speed owner`);
  assert.ok(!source.includes('initV109ShareSpeed'),
    `${label} must not initialize historical v109 share-speed owner`);
}

assert.equal(countCyrillicLines(resultInstant), 22,
  'Historical v104 result-instant Cyrillic evidence count changed');
assert.equal(countCyrillicLines(shareSpeed), 22,
  'Historical v109 share-speed Cyrillic evidence count changed');
assert.ok(audit.includes("'app/assets/js/production-v104-result-instant.js'"),
  'Hardcoded-text audit must classify historical v104 result-instant owner');
assert.ok(audit.includes("'app/assets/js/production-v109-share-speed.js'"),
  'Hardcoded-text audit must classify historical v109 share-speed owner');

assert.deepEqual(
  refsTo('production-v104-result-instant.js'),
  [
    'app/assets/js/production-clean-entry-v104.js',
    'app/assets/js/production-clean-entry-v105-fast-notifications.js',
    'app/assets/js/production-clean-entry-v105.js',
    'app/assets/js/production-clean-entry-v107.js',
    'app/assets/js/production-clean-entry-v108.js',
    'app/assets/js/production-clean-entry-v109.js',
  ],
  'v104 result-instant must remain confined to historical clean-entry owners'
);

assert.deepEqual(
  refsTo('production-v109-share-speed.js'),
  ['app/assets/js/production-clean-entry-v109.js'],
  'v109 share-speed must remain confined to historical clean-entry-v109'
);

for (const file of [
  'app/assets/js/production-clean-entry-v104.js',
  'app/assets/js/production-clean-entry-v105.js',
  'app/assets/js/production-clean-entry-v105-fast-notifications.js',
  'app/assets/js/production-clean-entry-v107.js',
  'app/assets/js/production-clean-entry-v108.js',
  'app/assets/js/production-clean-entry-v109.js',
]) {
  const source = read(file);
  assert.ok(source.includes("production-v104-result-instant.js?v=104"),
    `${file} must retain historical v104 result-instant import evidence`);
  assert.ok(source.includes('initV104ResultInstant();'),
    `${file} must retain historical v104 result-instant initialization evidence`);
}
const v109Clean = read('app/assets/js/production-clean-entry-v109.js');
assert.ok(v109Clean.includes("production-v109-share-speed.js?v=109"),
  'Historical v109 clean entry must retain share-speed import evidence');
assert.ok(v109Clean.includes('initV109ShareSpeed();'),
  'Historical v109 clean entry must retain share-speed initialization evidence');

assert.equal(countCyrillicLines(activeInvites), 0,
  'Canonical game-invites-v110 must remain free of hardcoded Cyrillic player copy');
assert.equal(countCyrillicLines(activeGameScreen), 0,
  'Canonical game-screen-v102-safe must remain free of hardcoded Cyrillic player copy');

assert.ok(Number(baseline.scanned_files) <= 726, 'legacy result/share successor must not restore excluded runtime files');
assert.ok(Number(baseline.cyrillic_lines_total) <= 2044, 'legacy result/share successor total debt must not exceed accepted ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 391, 'legacy result/share successor client debt must not exceed accepted ceiling');
assert.equal(Number(baseline.by_scope?.backend), 1653, 'Backend debt must remain unchanged');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0, 'Client-entry debt must remain zero');

console.log('MVP-27.1 legacy result/share performance reachability: OK — both overlays are confined to historical clean-entry ownership and absent from factual v110.');
