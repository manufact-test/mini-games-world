<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtimePath = $root . '/services/ChessRuntimeService.php';
$clockPath = $root . '/services/MatchPreparationClockService.php';

$runtime = file_get_contents($runtimePath);
$clock = file_get_contents($clockPath);
if (!is_string($runtime) || !is_string($clock)) {
    throw new RuntimeException('Phase B projection sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($runtime, "require_once __DIR__ . '/MatchPreparationClockService.php';"),
    'ChessRuntimeService must load the single Phase B clock/projection owner.'
);
$assert(
    str_contains($runtime, 'private MatchPreparationClockService $matchPreparationClock;')
        && str_contains($runtime, '$this->matchPreparationClock = new MatchPreparationClockService();'),
    'ChessRuntimeService must own one MatchPreparationClockService instance.'
);
$assert(
    str_contains($runtime, "if (!array_key_exists('launch_phase', $game)) {")
        && str_contains($runtime, 'return $public;'),
    'Legacy games without launch_phase must keep the accepted public projection unchanged.'
);
$assert(
    str_contains($runtime, 'return $this->matchPreparationClock->enrichPublicGame($game, $public);'),
    'Phase B public projection must be centralized in ChessRuntimeService::publicGame.'
);
$assert(
    !str_contains($runtime, '$this->matchPreparationClock->normalizeExisting($game)'),
    'Public projection must stay read-only and must never normalize stored state during a read.'
);
$assert(
    str_contains($clock, 'public function enrichPublicGame(array $game, array $public): array'),
    'MatchPreparationClockService must remain the timestamp/time-left projection implementation.'
);

foreach (['bot/api.php', 'bot/game-watch.php', 'bot/invites.php'] as $relativePath) {
    $source = file_get_contents(dirname($root) . '/' . $relativePath);
    if (!is_string($source)) throw new RuntimeException('Projection caller source unavailable: ' . $relativePath);
    $assert(
        !str_contains($source, '->enrichPublicGame('),
        'Endpoints must not create competing Phase B public projection owners: ' . $relativePath
    );
}

fwrite(STDOUT, "PhaseBPublicProjectionOwnerContractTest: {$assertions} assertions passed\n");
