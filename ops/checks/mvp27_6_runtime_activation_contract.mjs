import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const manifest = readFileSync('app/runtime/client/version-manifest.php', 'utf8');
const shell = readFileSync('app/assets/js/main-v110-handoff-shell.js', 'utf8');
const arena = readFileSync('app/assets/js/screens/tournaments-screen-v1.js', 'utf8');
const profile = readFileSync('app/assets/js/screens/profile-screen-v110.js', 'utf8');

for (const marker of [
  '&mvp27_6=nav-live-locale-v1',
  '&mvp27_6=arena-live-locale-v1',
  '&mvp27_6=match-result-key-v1',
]) {
  assert.ok(manifest.includes(marker), 'Active import-map cache version must include ' + marker);
}
assert.match(shell, /function localizeBottomNavigation\(\)/);
assert.match(shell, /localizeBottomNavigation\(\);\s*syncAppShellChrome\(\);/);
assert.match(arena, /addEventListener\('mgw:locale-changed', localizeCompetitionChrome\)/);
assert.match(arena, /localizeCompetitionChrome\(\);\s*void activateGame/);
assert.match(profile, /const resultLabel = knownResult \? t\(resultKey\)/);
console.log('MVP-27.6 active v110 nav/Arena/Profile locale module identities PASS');
