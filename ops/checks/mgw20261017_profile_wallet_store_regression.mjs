import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const read = path => readFileSync(path, 'utf8');
const files = [
  'mgw-profile-backgrounds.js',
  'mgw-profile-badges.js',
  'mgw-profile-frames.js',
  'mgw-profile-entry-effects.js',
  'mgw-profile-reactions.js',
  'mgw-profile-victory-effects-v4.js',
];
for (const file of files) {
  const source = read('app/assets/js/profile/' + file);
  assert.doesNotMatch(source, /const balance = Number\(result\.user\.balance/,
    file + ': passive Profile V2 response may not replace canonical wallet.');
  assert.match(source, /api\.profileV2\(\)/,
    file + ': inventory still must be hydrated from Profile V2.');
}
const ui = read('app/assets/js/ui.js');
assert.match(ui, /#screen-profile \.profile-v2-balance strong/,
  'Shared wallet writer must also refresh already-rendered Profile coin balance.');
assert.match(ui, /if \(profileBalance\.textContent !== label\)/,
  'Cached Profile balance changes must be idempotent without DOM remount.');

const store = read('app/assets/js/screens/store-screen.js');
assert.ok((store.match(/new CustomEvent\('mgw:store-surface-rendered'\)/g) || []).length >= 2,
  'Store must announce both full-panel render and tab-panel recreation.');
const victory = read('app/assets/js/profile/mgw-profile-victory-effects-v4.js');
assert.match(victory, /addEventListener\('mgw:store-surface-rendered', scheduleDecorate\)/,
  'Victory Effects must restore their section after Store overwrites the panel.');
assert.match(victory, /snapshotAttempted = false; return state\.profileInventory/,
  'A failed first Profile V2 catalog fetch must be retriable after route reentry.');
const manifest = read('app/runtime/client/version-manifest.php');
assert.match(manifest, /store-screen\.js\?v=73&intent_base=1/,
  'Active Store import mapping must invalidate cached old DOM owner.');
assert.match(manifest, /mgw-profile-victory-effects-card-parity\.js\?v=14&/,
  'Active Victory Effects mapping must invalidate cached old decorator.');
console.log('MGW_PROFILE_WALLET_STORE_REGRESSION=PASS');
