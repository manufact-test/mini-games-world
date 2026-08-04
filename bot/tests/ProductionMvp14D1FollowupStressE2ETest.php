<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/d1-followup-stress.spec.mjs');
if (!is_string($spec)) throw new RuntimeException('Missing D1 follow-up stress E2E spec.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($spec, "test('D1 follow-up:") === 3
        && str_contains($spec, "test('canonical desktop picker renders empty only after one authoritative response'"),
    'The live suite must contain three notification follow-ups and one canonical picker scenario.');
$assert(str_contains($spec, 'Приглашение отклонено')
        && str_contains($spec, "toHaveCount(0)")
        && str_contains($spec, "not.toHaveText('')"),
    'Bots must verify declined history, no actions and a visible timestamp.');
$assert(str_contains($spec, 'await delay(700);')
        && str_contains($spec, "items:[], unread_count:0")
        && str_contains($spec, "toBeLessThan(650)")
        && str_contains($spec, 'Пока уведомлений нет'),
    'Mobile bots must inject a delayed false-empty response and enforce immediate cached first paint.');
$assert(str_contains($spec, 'await delay(2_000);')
        && str_contains($spec, 'for (let iteration = 0; iteration < 8; iteration += 1)')
        && str_contains($spec, 'await playerA.page.waitForTimeout(2_300);'),
    'Desktop bots must click repeatedly while an old request is unfinished and verify no stale reopen.');
$assert(str_contains($spec, "test('canonical desktop picker renders empty only after one authoritative response'")
        && str_contains($spec, 'expect(opponentCalls).toBe(0)')
        && str_contains($spec, 'data-player-picker-state="loading"')
        && str_contains($spec, 'data-player-picker-state="empty"')
        && str_contains($spec, 'expect(opponentCalls).toBe(1)'),
    'Opponent automation must prove no boot fetch and one authoritative manual empty response.');
$assert(str_contains($spec, 'beforeGoto:async page =>')
        && str_contains($spec, 'isMobile:false')
        && str_contains($spec, 'isMobile = options.isMobile ?? true'),
    'The suite must cover pre-bootstrap interception and separate mobile/desktop browser contexts.');

fwrite(STDOUT, "ProductionMvp14D1FollowupStressE2ETest: {$assertions} assertions passed\n");
