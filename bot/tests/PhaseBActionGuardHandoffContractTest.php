<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/services/GameActionService.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('GameActionService source is unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($source, "require_once __DIR__ . '/MatchPreparationClockService.php';"),
    'GameActionService must load the Phase B clock owner.'
);
$assert(
    str_contains($source, 'private MatchPreparationClockService $matchPreparationClock;')
        && str_contains($source, '$this->matchPreparationClock = new MatchPreparationClockService();'),
    'GameActionService must own one clock service instance.'
);
$assert(
    str_contains($source, "$phaseManaged = array_key_exists('launch_phase', $game);")
        || str_contains($source, "\$phaseManaged = array_key_exists('launch_phase', \$game);"),
    'Phase B action behavior must be gated by stored launch_phase presence.'
);
$assert(
    substr_count($source, '$this->matchPreparationClock->advance($storedGame);') === 1,
    'Action path must advance Phase B exactly once before validation.'
);
$assert(
    substr_count($source, '$this->matchPreparationClock->assertActionAllowed($storedGame);') === 1,
    'Action path must enforce the shared pre-start action guard exactly once.'
);
$assert(
    substr_count($source, '$this->matchPreparationClock->synchronizeTurnHandoff($storedGame, $previousTurn);') === 1,
    'Successful managed action must publish exactly one synchronized turn handoff.'
);
$assert(
    !str_contains($source, '->normalizeExisting(')
        && !str_contains($source, '->initializeNewGame(')
        && !str_contains($source, '->markReady('),
    'Action service must not normalize, activate, or own readiness.'
);

$phaseGate = strpos($source, "$phaseManaged = array_key_exists('launch_phase', $game);");
if ($phaseGate === false) $phaseGate = strpos($source, "\$phaseManaged = array_key_exists('launch_phase', \$game);");
$advance = strpos($source, '$this->matchPreparationClock->advance($storedGame);');
$assertAllowed = strpos($source, '$this->matchPreparationClock->assertActionAllowed($storedGame);');
$dispatch = strpos($source, '$result = match ($engine)');
$handoff = strpos($source, '$this->matchPreparationClock->synchronizeTurnHandoff($storedGame, $previousTurn);');

$assert(
    $phaseGate !== false && $advance !== false && $assertAllowed !== false && $dispatch !== false && $handoff !== false
        && $phaseGate < $advance
        && $advance < $assertAllowed
        && $assertAllowed < $dispatch
        && $dispatch < $handoff,
    'Managed action order must remain gate -> advance -> guard -> engine -> handoff.'
);
$assert(
    str_contains($source, 'if (!$phaseManaged || !isset($db[\'games\'][$gameId]) || !is_array($db[\'games\'][$gameId]))')
        && str_contains($source, 'return $result;'),
    'Legacy games without launch_phase must return the original engine result unchanged.'
);

fwrite(STDOUT, "PhaseBActionGuardHandoffContractTest: {$assertions} assertions passed\n");
