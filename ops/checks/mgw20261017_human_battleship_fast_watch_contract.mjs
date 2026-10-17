import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const read = path => readFileSync(path, 'utf8');

const screen = read('app/assets/js/screens/game-screen-v102.js');
const owner = read('app/assets/js/production-v110-readonly-game-sync.js');
const shell = read('app/assets/js/main-v110-handoff-shell.js');
const api = read('app/assets/js/api/client.js');
const server = read('bot/game-watch.php');
const manifest = read('app/runtime/client/version-manifest.php');

assert.match(shell, /initV110ReadonlyGameSync\(\)/,
  'Active v110 shell must retain exactly the established read-only watcher.');
assert.doesNotMatch(api, /battleWatch:/,
  'Avoid introducing a second battle-watch API client owner.');

const game = { game_type:'battleship', phase:'battle', status:'active', is_bot_game:false, match_source:'human' };
const valid = source => {
  const match=source.match(/function isOrdinaryHumanBattleshipBattle\(game\)\{[\s\S]*?\n\}/);
  assert.ok(match,'Must be limited to ordinary human Battleship battles.');
  return vm.runInNewContext('(' + match[0] + ')',{gameTypeOf:g=>g?.game_type});
};
const screenEligible = valid(screen);
const watchEligible = valid(owner);
for (const check of [screenEligible,watchEligible]) {
  assert.equal(check(game),true,'Human Battleship battle gets lighter polls.');
  assert.equal(check({...game,phase:'setup'}),false,'Fleet setup remains unchanged.');
  assert.equal(check({...game,is_bot_game:true}),false,'Bot engine remains unchanged.');
  assert.equal(check({...game,match_source:'tournament'}),false,'Tournament cadence remains unchanged.');
  assert.equal(check({...game,game_type:'chess'}),false,'Other engines remain unchanged.');
  assert.equal(check({...game,status:'finished'}),false,'Finished game route remains unchanged.');
}
assert.match(screen,/const HUMAN_BATTLESHIP_AUTHORITATIVE_POLL_MS = 3000;/);
assert.match(screen,/if \(isOrdinaryHumanBattleshipBattle\(game\)\) \{\s*return Math\.max\(HUMAN_BATTLESHIP_AUTHORITATIVE_POLL_MS/,
  'Canonical 3-second game_state poll must remain active as lifecycle/timeout heartbeat.');
assert.match(owner,/const WATCH_INTERVAL_MS = 250;/,
  'Keep 250ms watcher for all other games.');
assert.match(owner,/const HUMAN_BATTLESHIP_WATCH_INTERVAL_MS = 500;/,
  'Reduce only human Battleship watcher load to a 500ms cadence.');
assert.match(owner,/scheduleWatch\(currentWatchInterval\(\)\)/,
  'Adaptive watch cadence must apply after every watcher iteration.');
assert.match(owner,/runtime\.battleshipPollCount % 2 === 0/,
  'Every second read can skip the unrelated reaction SQL query.');
assert.match(owner,/watchBody\.mode = 'battle_poll'/,
  'Use the established game-watch endpoint rather than a second polling loop.');
assert.match(server,/in_array\(\$userId, \$participants, true\)/,
  'Read-only watch must remain participant-scoped.');
assert.match(server,/if \(\$watchMode !== 'battle_poll'\)/,
  'Keep reaction refresh on alternating watches; skip redundant DB read only.');
assert.match(server,/LOCK_SH \| LOCK_NB/,
  'Fast watcher must never queue on gameplay JSON writes.');
assert.match(manifest,/game-screen-v102\.js\?v=115&/);
assert.match(manifest,/readonly-game-sync\.js\?v=1118&/);
assert.match(manifest,/api\/client\.js\?v=1154&/,
  'Keep the original API client runtime; no extra battle watch introduced.');
console.log('MGW_HUMAN_BATTLESHIP_EXISTING_WATCH_LOAD_CORRECTIVE=PASS');
