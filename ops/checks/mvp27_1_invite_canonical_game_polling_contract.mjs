import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p, 'utf8');
const countCyrillicLines = source => source.split(/\r?\n/).filter(line => /[\u0400-\u04FF]/.test(line)).length;

const shell = read('app/assets/js/main-v110-handoff-shell.js');
const invite = read('app/assets/js/games/game-invites-v110.js');
const inviteWrapper = read('app/assets/js/games/game-invites-v110-rematch-policy-v175.js');
const canonicalSafe = read('app/assets/js/screens/game-screen-v102-safe.js');
const canonicalGame = read('app/assets/js/screens/game-screen-v102.js');
const legacyGame = read('app/assets/js/screens/game-screen.js');
const manifest = read('app/runtime/client/version-manifest.php');
const audit = read('ops/checks/mvp27_1_hardcoded_text_audit.mjs');
const baseline = JSON.parse(read('ops/checks/mvp27_1_hardcoded_text_baseline.json'));

assert.ok(shell.includes("import { initGameInvites } from './games/game-invites-v110.js?v=1137&ux=1';"),
  'Factual v110 shell must retain canonical invite owner');
assert.ok(shell.includes("from './screens/game-screen-v102-safe.js?v=102';"),
  'Factual v110 shell must retain canonical v102-safe game owner');
assert.ok(inviteWrapper.includes("from './game-invites-v110.js?v=1142&zone=unified&rematch=optimistic&terminal=self-silent';"),
  'Accepted rematch wrapper must retain its frozen base invite specifier');

assert.ok(invite.includes("import { startGamePolling } from '../screens/game-screen.js?v=74';"),
  'Accepted invite owner source must stay frozen on its historical polling specifier');
assert.ok(manifest.includes(
  "'./assets/js/screens/game-screen.js?v=74' => './assets/js/screens/game-screen-v102-safe.js?v=105&polling=route-cleanup&entry_effect_handoff=1&mvp27_1=invite-canonical-polling-v1'"
), 'Import map must shadow the historical invite polling specifier with canonical v102-safe runtime');
assert.ok(manifest.includes(
  "'./assets/js/screens/game-screen-v102-safe.js?v=102' => './assets/js/screens/game-screen-v102-safe.js?v=105&polling=route-cleanup&entry_effect_handoff=1'"
), 'Canonical v102-safe manifest owner must remain unchanged');

assert.ok(canonicalSafe.includes("from './game-screen-v102.js?v=102';"),
  'Canonical safe game wrapper must delegate to v102 base owner');
assert.ok(canonicalSafe.includes('export { startGamePolling, clearGameView };'),
  'Canonical safe game wrapper must continue exporting polling ownership');
for (const token of [
  'state.timers.search = clearTimer(state.timers.search);',
  'state.timers.game = clearTimer(state.timers.game);',
  'window.setInterval(() => refreshGame(id), APP_CONFIG.gameIntervalMs)',
  'window.setTimeout(() => refreshGame(id)',
]) {
  assert.ok(canonicalGame.includes(token), `Canonical v102 polling invariant missing: ${token}`);
}

assert.equal(countCyrillicLines(legacyGame), 30,
  'Legacy game-screen Cyrillic evidence count changed');
assert.ok(audit.includes("'app/assets/js/screens/game-screen.js'"),
  'Audit must classify the import-map-shadowed legacy game screen');

assert.ok(Number(baseline.cyrillic_lines_total) <= 2827,
  'Canonical polling successor debt must not regress above the accepted #1963 total ceiling');
assert.ok(Number(baseline.by_scope?.client) <= 1174,
  'Canonical polling successor client debt must not regress above the accepted #1963 ceiling');
assert.ok(Number(baseline.by_scope?.backend) <= 1653, 'Backend localization debt may only decrease from the accepted 1653 ceiling.');
assert.equal(Number(baseline.by_scope?.['client-entry']), 0,
  'Client-entry debt must remain zero');

console.log('MVP-27.1 invite canonical game polling: OK — frozen v74 source edge resolves to canonical v102-safe and legacy game-screen is shadowed.');
