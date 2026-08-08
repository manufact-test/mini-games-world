<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/MatchPreparationClockService.php';

$service = new MatchPreparationClockService();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$allows = static function (array $game) use ($service): bool {
    try {
        $service->assertSurrenderAllowed($game);
        return true;
    } catch (RuntimeException) {
        return false;
    }
};

$legacy = ['status' => 'active'];
$active = ['status' => 'active', 'launch_phase' => 'active'];
$preparing = ['status' => 'active', 'launch_phase' => 'preparing'];
$countdown = ['status' => 'active', 'launch_phase' => 'countdown'];
$timedOut = ['status' => 'active', 'launch_phase' => 'preparation_timeout'];
$finished = ['status' => 'finished', 'launch_phase' => 'finished'];

$assert($allows($legacy), 'Legacy games without launch_phase must preserve accepted surrender behavior.');
$assert($allows($active), 'Explicit active Phase B games must preserve surrender behavior.');
$assert(!$allows($preparing), 'Preparing Phase B games must reject ordinary surrender.');
$assert(!$allows($countdown), 'Countdown Phase B games must reject ordinary surrender.');
$assert(!$allows($timedOut), 'Preparation-timeout games must reject ordinary surrender.');
$assert(!$allows($finished), 'Explicit non-active Phase B games must not enter ordinary surrender settlement.');

$clockPath = dirname(__DIR__) . '/services/MatchPreparationClockService.php';
$runtimePath = dirname(__DIR__) . '/services/ChessRuntimeService.php';
$clockSource = file_get_contents($clockPath);
$runtimeSource = file_get_contents($runtimePath);
if (!is_string($clockSource) || !is_string($runtimeSource)) {
    throw new RuntimeException('Phase B surrender owner sources are unavailable.');
}

$assert(substr_count($clockSource, 'public function assertSurrenderAllowed(array $game): void') === 1,
    'The preparation clock service must own exactly one surrender lifecycle guard.');
$assert(substr_count($runtimeSource, '$this->matchPreparationClock->assertSurrenderAllowed($game);') === 1,
    'The central runtime surrender owner must invoke the guard exactly once.');

$methodStart = strpos($runtimeSource, 'public function surrenderGame(');
$methodEnd = $methodStart === false ? false : strpos($runtimeSource, 'public function findActiveGameForUser(', $methodStart);
$method = ($methodStart !== false && $methodEnd !== false) ? substr($runtimeSource, $methodStart, $methodEnd - $methodStart) : '';
$guardPos = strpos($method, '$this->matchPreparationClock->assertSurrenderAllowed($game);');
$chessPos = strpos($method, '$this->chess->surrender(');
$goPos = strpos($method, '$this->go->surrender(');
$dominoPos = strpos($method, '$this->domino->surrender(');
$basePos = strpos($method, '$this->base->surrenderGame(');
$assert($guardPos !== false && $chessPos !== false && $goPos !== false && $dominoPos !== false && $basePos !== false
    && $guardPos < $chessPos && $guardPos < $goPos && $guardPos < $dominoPos && $guardPos < $basePos,
    'The lifecycle guard must run before every engine/base surrender dispatch.');

$assert(!str_contains($clockSource, 'sleep(') && !str_contains($clockSource, 'usleep('),
    'The surrender guard must not introduce timing patches.');

fwrite(STDOUT, "PhaseBPreActiveSurrenderGuardTest: {$assertions} assertions passed\n");
