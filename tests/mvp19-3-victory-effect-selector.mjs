import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../app/assets/js/profile/mgw-victory-effect-selector.js', import.meta.url), 'utf8');
const runnable = source
  .replace("import { t } from '@mgw/i18n';\n", '')
  .replace('export function selectWinnerVictoryEffect', 'function selectWinnerVictoryEffect')
  .replace('export function isCanonicalVictoryEffectItemId', 'function isCanonicalVictoryEffectItemId')
  + '\nObject.assign(globalThis,{selectWinnerVictoryEffect,isCanonicalVictoryEffectItemId});';
const t = (key, params = {}) => key === 'profile.victory_effects.live.player_fallback'
  ? `Игрок ${params.number}`
  : String(key);
const context = { Object, Set, String, t };
vm.createContext(context);
vm.runInContext(runnable, context);

const { selectWinnerVictoryEffect, isCanonicalVictoryEffectItemId } = context;

const finished = (winnerId, players) => ({ id:'g-1', status:'finished', winner_id:winnerId, players });

assert.equal(isCanonicalVictoryEffectItemId('profile-victory-effect-01'), true);
assert.equal(isCanonicalVictoryEffectItemId('profile-victory-effect-02'), true);
assert.equal(isCanonicalVictoryEffectItemId('profile-victory-effect-03'), true);
assert.equal(isCanonicalVictoryEffectItemId('profile-victory-effect-04'), false);

assert.deepEqual(
  JSON.parse(JSON.stringify(selectWinnerVictoryEffect(finished('a', [
    { id:'a', name:'Alpha', victory_effect_item_id:'profile-victory-effect-01' },
    { id:'b', name:'Beta', victory_effect_item_id:'profile-victory-effect-03' },
  ])))),
  { winnerId:'a', itemId:'profile-victory-effect-01', playerIndex:0, name:'Alpha' },
  'Only the winner\'s equipped Victory Effect may be selected.'
);

assert.deepEqual(
  JSON.parse(JSON.stringify(selectWinnerVictoryEffect(finished('b', [
    { id:'a', name:'Alpha', victory_effect_item_id:'profile-victory-effect-01' },
    { id:'b', name:'Beta', victory_effect_item_id:'profile-victory-effect-03' },
  ])))),
  { winnerId:'b', itemId:'profile-victory-effect-03', playerIndex:1, name:'Beta' },
  'The same winner-owned effect is viewer-independent for owner/opponent/spectator presentation.'
);

assert.equal(selectWinnerVictoryEffect({ status:'active', winner_id:'a', players:[] }), null);
assert.equal(selectWinnerVictoryEffect(finished('', [{ id:'a', victory_effect_item_id:'profile-victory-effect-01' }])), null, 'Draw/no-winner must not play a Victory Effect.');
assert.equal(selectWinnerVictoryEffect(finished('a', [{ id:'a' }])), null, 'Winner without an equipped effect must not synthesize one.');
assert.equal(selectWinnerVictoryEffect(finished('a', [{ id:'a', victory_effect_item_id:'unknown' }])), null, 'Unknown item ids must not become presentation owners.');
assert.equal(selectWinnerVictoryEffect(finished('missing', [{ id:'a', victory_effect_item_id:'profile-victory-effect-01' }])), null);

assert.deepEqual(
  JSON.parse(JSON.stringify(selectWinnerVictoryEffect(finished('b', [
    { id:'a', name:'Alpha', victory_effect_item_id:'profile-victory-effect-01' },
    { id:'b', victory_effect_item_id:'profile-victory-effect-03' },
  ])))),
  { winnerId:'b', itemId:'profile-victory-effect-03', playerIndex:1, name:'Игрок 2' },
  'Winner fallback name must resolve through canonical localization semantics.'
);

for (const forbidden of ['gameAction(', 'openSheet(', 'setTimeout(', 'setInterval(', 'fetch(', 'api.']) {
  assert.equal(source.includes(forbidden), false, `Pure selector must not own rules, result UI, timers or network: ${forbidden}`);
}

console.log('MVP-19.3 Victory Effect selector contract passed.');
