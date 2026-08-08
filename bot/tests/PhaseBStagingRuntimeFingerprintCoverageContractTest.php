<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/bot/helpers/staging-e2e-runtime-files.txt';
$manifest = file_get_contents($manifestPath);
if (!is_string($manifest)) {
    throw new RuntimeException('Staging runtime fingerprint manifest is unavailable.');
}

$paths = array_values(array_filter(array_map(
    static fn(string $line): string => trim($line),
    preg_split('/\R/', $manifest) ?: []
), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
$pathSet = array_fill_keys($paths, true);

$required = [
    'bot/core/bootstrap.php',
    'bot/api.php',
    'bot/game-watch.php',
    'bot/game-clock.php',
    'bot/services/GameService.php',
    'bot/services/GameRuntimeService.php',
    'bot/services/ChessRuntimeService.php',
    'bot/services/GameActionService.php',
    'bot/services/GameSettlementService.php',
    'bot/services/GameLaunchFinalizationService.php',
    'bot/services/MatchPreparationClockService.php',
    'bot/services/MatchPreparationRuntimeService.php',
    'bot/services/HistoryService.php',
    'bot/services/SessionService.php',
    'bot/services/GameInviteService.php',
    'bot/services/invites/GameInviteActionTrait.php',
    'bot/services/invites/GameInviteCreationTrait.php',
    'bot/services/invites/GameInviteStorageTrait.php',
    'bot/realtime/RealtimeRuntimeBridge.php',
    'bot/ledger/EconomyRuntimeBridge.php',
];

$assertions = 0;
foreach ($required as $path) {
    $assertions++;
    if (!isset($pathSet[$path])) {
        throw new RuntimeException('Phase B runtime owner missing from staging fingerprint: ' . $path);
    }
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Fingerprinted Phase B runtime owner does not exist: ' . $path);
    }
}

$assertions++;
if (count($paths) !== count(array_unique($paths))) {
    throw new RuntimeException('Staging runtime fingerprint manifest must not contain duplicate paths.');
}

fwrite(STDOUT, "PhaseBStagingRuntimeFingerprintCoverageContractTest: {$assertions} assertions passed\n");
