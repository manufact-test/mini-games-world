import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const helperUrl = new URL('../app/assets/js/profile/mgw-entry-effect-player-arbitration.js', import.meta.url);
const helperSource = await readFile(helperUrl, 'utf8');
const helperModule = await import(`data:text/javascript;base64,${Buffer.from(helperSource).toString('base64')}`);
const { arbitratePlayerEntryEffects } = helperModule;

assert.equal(typeof arbitratePlayerEntryEffects, 'function');

function run(viewerId, aEffect = '', bEffect = ''){
  const players = [
    { id:'A', effect:aEffect },
    { id:'B', effect:bEffect },
  ];
  return arbitratePlayerEntryEffects(players, {
    isLocalPlayer: player => player.id === viewerId,
    resolveEntry: (player, index) => player.effect ? { playerId:player.id, effect:player.effect, index } : null,
  });
}

function ids(entries){ return entries.map(entry => `${entry.playerId}:${entry.effect}`); }

/* Only A equipped: both player clients see A. */
assert.deepEqual(ids(run('A', 'entry-01', '')), ['A:entry-01']);
assert.deepEqual(ids(run('B', 'entry-01', '')), ['A:entry-01']);

/* Only B equipped: both player clients see B. */
assert.deepEqual(ids(run('A', '', 'entry-02')), ['B:entry-02']);
assert.deepEqual(ids(run('B', '', 'entry-02')), ['B:entry-02']);

/* Both equipped: each player client sees only its own effect. */
assert.deepEqual(ids(run('A', 'entry-01', 'entry-03')), ['A:entry-01']);
assert.deepEqual(ids(run('B', 'entry-01', 'entry-03')), ['B:entry-03']);

/* Neither equipped: no Entry Effect. */
assert.deepEqual(ids(run('A', '', '')), []);
assert.deepEqual(ids(run('B', '', '')), []);

for (const viewerId of ['A', 'B']) {
  for (const pair of [
    ['entry-01', ''],
    ['', 'entry-02'],
    ['entry-01', 'entry-03'],
    ['', ''],
  ]) {
    assert.ok(run(viewerId, ...pair).length <= 1, `identified player ${viewerId} received more than one Entry Effect`);
  }
}

/* Spectator/unknown-viewer policy is intentionally unchanged in this slice. */
assert.deepEqual(ids(run('spectator', 'entry-01', 'entry-03')), ['A:entry-01', 'B:entry-03']);

assert.doesNotMatch(helperSource, /\b(document|window|setTimeout|setInterval|fetch|XMLHttpRequest)\b/);
assert.doesNotMatch(helperSource, /\b(api|state|activeGame|balance|coins?)\b/i);

const profileSource = await readFile(new URL('../app/assets/js/profile/mgw-profile-entry-effects.js', import.meta.url), 'utf8');
assert.match(profileSource, /import \{ arbitratePlayerEntryEffects \} from '\.\/mgw-entry-effect-player-arbitration\.js\?v=1';/);
assert.match(profileSource, /const entries = arbitratePlayerEntryEffects\(players, \{/);
assert.match(profileSource, /isLocalPlayer: player => isLocalEntryEffectPlayer\(player, localIds\)/);
assert.doesNotMatch(profileSource, /const entries = players\.map\(\(player, index\) => \{/);
assert.match(profileSource, /playedGames\.add\(gameId\);/);
assert.match(profileSource, /Math\.min\(4000, Math\.max\(2000, duration\)\)/);

const manifestSource = await readFile(new URL('../app/runtime/client/version-manifest.php', import.meta.url), 'utf8');
assert.match(
  manifestSource,
  /'\.\/assets\/js\/profile\/mgw-profile-entry-effects\.js\?v=1&mvp19_3=entry-effects' => '\.\/assets\/js\/profile\/mgw-profile-entry-effects\.js\?v=6&mvp19_3=player-arbitration'/,
);

console.log('MVP-19.3 Entry Effect player arbitration: PASS');
