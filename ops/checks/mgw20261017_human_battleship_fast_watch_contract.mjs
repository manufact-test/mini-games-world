import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
const read = path => readFileSync(path, 'utf8');

const screen = read('app/assets/js/screens/game-screen-v102.js');
const api = read('app/assets/js/api/client.js');
const server = read('bot/game-watch.php');
const manifest = read('app/runtime/client/version-manifest.php');

const predicateMatch = screen.match(/function canUseHumanBattleshipWatch\(game\)\{[\s\S]*?\n\}/);
assert.ok(predicateMatch, 'Live game screen must have a battle-only policy.');
const predicate = vm.runInNewContext('(' + predicateMatch[0] + ')', {
  gameTypeOf: game => game?.game_type,
});
const good = { game_type:'battleship', phase:'battle', status:'active', is_bot_game:false, match_source:'human' };
assert.equal(predicate(good),true, 'Active human Battleship should use read-only polling.');
assert.equal(predicate({...good,phase:'setup'}),false, 'Ship setup stays authoritative.');
assert.equal(predicate({...good,is_bot_game:true}),false, 'Bot matches keep engine progression.');
assert.equal(predicate({...good,match_source:'tournament'}),false, 'Tournament retains regular authoritative cadence.');
assert.equal(predicate({...good,game_type:'chess'}),false, 'Other engines are unchanged.');
assert.equal(predicate({...good,status:'finished'}),false, 'Finished matches never use read-only watch.');

const cadence = Number(screen.match(/const HUMAN_BATTLESHIP_AUTH_POLL_EVERY = (\d+);/)?.[1] || 0);
assert.equal(cadence,4,'Canonical gameplay updates must run every fourth successful poll, starting with the first.');
const pattern=Array.from({length:12},(_,i)=>(i+1)%cadence!==1?'read-only':'authoritative');
assert.deepEqual(pattern.filter(x=>x==='authoritative').length,3);
assert.deepEqual(pattern.slice(0,5),['authoritative','read-only','read-only','read-only','authoritative']);
assert.match(screen,/useWatch \? await api\.battleWatch\(gameId\) : await api\.gameState\(gameId\)/);
assert.match(screen,/if \(!result\?\.game\) \{\s*if \(useWatch\) \{[\s\S]*?item\.battleshipPollSequence = 0;\s*return;/);
assert.match(screen,/catch \(error\) \{\s*if \(useWatch\) \{[\s\S]*?item\.battleshipPollSequence = 0;\s*return;/);
assert.match(api,/battleWatch: gameId => requestUrl\(RESULT_WATCH_URL, \{ gameId, mode:'battle_poll' \}\)/);
assert.match(server,/in_array\(\$userId, \$participants, true\)/,'Watch must remain participant-scoped.');
assert.match(server,/\$watchMode !== 'battle_poll'/,'Lean read-only game watch must skip only the reaction DB lookup.');
assert.match(server,/LOCK_SH \| LOCK_NB/,'The game-watch file read must not queue behind gameplay writer lock.');
assert.match(manifest,/api\/client\.js\?v=1155&/,'New API URL must be active.');
assert.match(manifest,/game-screen-v102\.js\?v=115&/,'New polling game screen URL must be active.');
console.log('MGW_HUMAN_BATTLESHIP_FAST_WATCH=PASS');
