<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repoRoot = dirname($root);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$runtime = file_get_contents($repoRoot . '/app/assets/js/production-v110-acceptance-runtime.js');
$manifest = require $repoRoot . '/app/runtime/client/version-manifest.php';
$clock = file_get_contents($root . '/services/MatchPreparationClockService.php');

$assert(is_string($runtime), 'Active v110 acceptance runtime must exist.');
$assert(is_string($runtime) && str_contains($runtime, 'const LAUNCH_COUNTDOWN_STEP_MS = 1000;'), 'Visible 3-2-1 must use real one-second steps.');
$assert(is_string($runtime) && str_contains($runtime, 'const LAUNCH_READY_HOLD_MS = 260;'), 'Ready state must be a short final handoff, not a multi-second waiting state.');
$assert(
    is_string($runtime) && str_contains($runtime, 'if (numbersComplete && serverReady && presentation.readyStartedAt === null)'),
    'Ready must not appear until both countdown and authoritative launch time are ready.'
);
$assert(
    is_string($runtime) && str_contains($runtime, "const countdownWaiting = phase === 'countdown' && !launchStartReached(game);"),
    'Stale countdown phase must stop blocking once the authoritative local launch instant has been reached.'
);
$assert(
    is_string($runtime)
        && str_contains($runtime, 'window.__MGW_V100_GAME_RUNTIME__?.resultOpened?.has?.(gameId)')
        && str_contains($runtime, 'runtime.lastClockLabel'),
    'Terminal transition must preserve the last visible move clock while the result sheet is opening.'
);
$assert(
    is_string($runtime) && str_contains($runtime, 'runtime.lastClockLabel = label;'),
    'Active clock owner must remember its final visible label for the terminal handoff.'
);
$assert(
    is_string($clock)
        && str_contains($clock, 'public const COUNTDOWN_SEC = 3;')
        && str_contains($clock, 'public const MOVE_TIMEOUT_SEC = 60;'),
    'Presentation polish must not change authoritative 3-second launch or 60-second move timing.'
);
$assert(
    str_contains(
        (string)($manifest['imports']['./assets/js/production-v110-acceptance-runtime.js?v=110'] ?? ''),
        'v=130&clock=battleship-setup-single-owner&launch=ready-gated-v2&terminal=clock-stable'
    ),
    'Active v110 manifest must cache-bust only the accepted acceptance-runtime owner.'
);
$assert(
    (string)($manifest['imports']['@mgw/main'] ?? '') === './assets/js/main-v110-reconnect-v174.js?v=2',
    'Reconnect wrapper identity must remain frozen.'
);

fwrite(STDOUT, "Mvp17_5TerminalLaunchPolishContractTest: {$assertions} assertions passed\n");
