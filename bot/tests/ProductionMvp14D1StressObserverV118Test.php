<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stress = file_get_contents($root . '/e2e/staging/d1-followup-stress.spec.mjs');
if (!is_string($stress)) throw new RuntimeException('Missing D1 follow-up stress suite.');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$observerStart = strpos($stress, 'async function recordSheetTrace');
$observerEnd = strpos($stress, 'async function takeSheetTrace', $observerStart ?: 0);
$observerBlock = $observerStart !== false && $observerEnd !== false
    ? substr($stress, $observerStart, $observerEnd - $observerStart)
    : '';
$assert($observerBlock !== ''
        && str_contains($observerBlock, 'new MutationObserver(record)')
        && str_contains($observerBlock, 'record();'),
    'The stress trace must capture the current first frame before observing later mutations.');
$assert(str_contains($stress, 'public error: ${publicError}')
        && str_contains($stress, "result.payload.error.slice(0, 300)"),
    'Direct staging API failures must expose the bounded public error in CI logs.');
$assert(str_contains($stress, "test('canonical desktop picker renders empty only after one authoritative response'")
        && str_contains($stress, 'expect(opponentCalls).toBe(0)')
        && str_contains($stress, 'data-player-picker-state="loading"')
        && str_contains($stress, 'data-player-picker-state="empty"')
        && str_contains($stress, 'expect(opponentCalls).toBe(1)'),
    'The canonical opponent scenario must prove loading before one authoritative empty response and no boot request.');
$assert(!str_contains($stress, "expect(trace.some(frame => frame.includes('Загружаем соперников'))).toBe(true)"),
    'A direct transition to the real player list must not fail merely because loading text was too fast to observe.');

fwrite(STDOUT, "ProductionMvp14D1StressObserverV118Test: {$assertions} assertions passed\n");
