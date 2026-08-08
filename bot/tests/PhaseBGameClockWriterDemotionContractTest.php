<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/game-clock.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('game-clock.php source is unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    !str_contains($source, 'MatchPreparationClockService'),
    'game-clock.php must not instantiate or load the Phase B lifecycle service.'
);
foreach ([
    '->normalizeExisting(',
    '->markReady(',
    '->advance(',
    '->synchronizeObservedTurn(',
    '->enrichPublicGame(',
] as $forbiddenWrite) {
    $assert(
        !str_contains($source, $forbiddenWrite),
        'game-clock.php must not retain a Phase B lifecycle/projection write path: ' . $forbiddenWrite
    );
}
$assert(
    !str_contains($source, '$games->cleanup($data)'),
    'mvp14r2 compatibility clock must not run a competing engine cleanup owner.'
);
$assert(
    str_contains($source, "if (\$protocol === 'mvp14r2')")
        && str_contains($source, "'preparation_protocol' => 'mvp14r2-readonly-primary-api-owner'"),
    'mvp14r2 compatibility path must remain explicitly marked read-only and primary-API-owned.'
);
$assert(
    str_contains($source, "'game' => \$games->publicGame(\$game, \$userId)"),
    'Compatibility protocol must consume the centralized public projection owner.'
);

// The proven accepted v106 default path remains until client migration is
// intentionally activated in a later PR.
foreach ([
    "empty(\$game['v106_first_turn_clock_armed_at'])",
    "\$game['turn_started_at'] = \$now;",
    "\$game['v106_first_turn_clock_armed_at'] = \$now;",
    "\$game['bot_move_after_at'] = gmdate('c', time() + 1);",
] as $legacyInvariant) {
    $assert(
        str_contains($source, $legacyInvariant),
        'Accepted v106 clock compatibility invariant changed unexpectedly: ' . $legacyInvariant
    );
}

fwrite(STDOUT, "PhaseBGameClockWriterDemotionContractTest: {$assertions} assertions passed\n");
