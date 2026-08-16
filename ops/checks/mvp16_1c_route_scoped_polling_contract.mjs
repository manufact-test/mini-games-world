import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const search = read('app/assets/js/screens/search-screen-v102.js');
const game = read('app/assets/js/screens/game-screen-v102-safe.js');
const manifest = read('app/runtime/client/version-manifest.php');

const assert = (ok, message) => {
  if (!ok) throw new Error(message);
};

assert(shell.includes("registerScreenCleanup('home', stopStatsPolling)"), 'Home must register stats cleanup');
assert(shell.includes("onScreenEnter('home', startStatsPolling)"), 'Home must restart stats polling on route enter');
assert(shell.includes("if (currentScreen() !== 'home') return;"), 'Stats polling must not start off Home');
assert(shell.includes('state.timers.stats = clearTimer(state.timers.stats);'), 'Home cleanup must clear stats timer');

assert(search.includes("registerScreenCleanup('search', handleSearchScreenLeave)"), 'Search must register route cleanup');
assert(search.includes('state.timers.search = clearTimer(state.timers.search);'), 'Search cleanup must clear search timer');
assert(search.includes("if (String(to || '') === 'game') return;"), 'Search-to-game transition must not issue an orphan cancellation');
assert(search.includes('void stopSearchAuthoritatively(pendingStart);'), 'Unexpected search exit must reconcile authoritatively');

assert(game.includes("registerScreenCleanup('game', () => {"), 'Game wrapper must register route cleanup');
assert(game.includes('state.timers.game = clearTimer(state.timers.game);'), 'Game route cleanup must clear game polling interval');

assert(manifest.includes("'version' => 'v2-route-scoped-polling'"), 'Manifest version must describe route-scoped polling');
assert(manifest.includes("v=1147&mvp16=route-scoped-polling"), 'Manifest must own new shell target');
assert(manifest.includes("v=107&search=route-scoped-lifecycle"), 'Manifest must own new search target');
assert(manifest.includes("v=104&polling=route-cleanup"), 'Manifest must own new safe game target');

console.log('MVP16_1C_ROUTE_SCOPED_POLLING_CONTRACT=PASS');
