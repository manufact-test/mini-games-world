import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('./two-context.spec.mjs', import.meta.url), 'utf8');
const helperStart = source.indexOf('async function waitForAuthoritativeTicTacToeLaunch');
const helperEnd = source.indexOf("\ntest('TEST PLAYER A and B run in isolated browser contexts'", helperStart);
assert.ok(helperStart >= 0 && helperEnd > helperStart, 'authoritative launch helper must exist');

const helper = source.slice(helperStart, helperEnd);
assert.ok(helper.includes("playerA.page"), 'Player A must explicitly establish readiness');
assert.ok(helper.includes("playerB.page"), 'Player B must explicitly establish readiness');
assert.ok((helper.match(/\{ action: 'game_state', gameId \}/g) || []).length >= 3,
  'readiness and launch observation must use explicit game_state(gameId) intent');
assert.ok(helper.includes("phase === 'active'"), 'launch wait must require active phase');
assert.ok(helper.includes('serverNowMs >= turnStartsAtMs'),
  'launch wait must use authoritative server time and turn start');
assert.ok(helper.includes('expect.poll'), 'launch wait must observe authoritative state');
assert.equal(helper.includes('waitForTimeout'), false, 'launch wait must not use a fixed sleep');

const scenarioStart = source.indexOf("test('A invites B through notifications and they finish a Tic Tac Toe match'");
assert.ok(scenarioStart >= 0, 'final Tic Tac Toe scenario must exist');
const scenario = source.slice(scenarioStart);
const waitIndex = scenario.indexOf('await waitForAuthoritativeTicTacToeLaunch(playerA, playerB, gameId);');
const moveLoopIndex = scenario.indexOf('for (const cell of winningSequence)');
assert.ok(waitIndex >= 0 && moveLoopIndex > waitIndex,
  'authoritative launch wait must happen before the first move loop');

console.log('Phase B authoritative launch wait contract: PASS');
