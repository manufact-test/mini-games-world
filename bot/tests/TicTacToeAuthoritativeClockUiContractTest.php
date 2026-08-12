<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing source: ' . $path);
    return $content;
};

$screen = $read('app/assets/js/screens/game-screen-phase-b-current.js');
$clockRuntime = $read('app/assets/js/phase-b-current-runtime.js');
$legacyEntry = $read('app/assets/js/production-regression-fix-entry.js');
$style = $read('app/assets/css/screens/game.css');
$mainStyle = $read('app/assets/css/main.css');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');
$twoContext = $read('e2e/staging/two-context.spec.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    !str_contains($legacyEntry, 'initTicTacToeTurnFixEarly')
        && !str_contains($legacyEntry, 'scheduleTicTacToeTurnFixAfter'),
    'The active graph must not retain a second Tic Tac Toe action owner.'
);
$assert(
    str_contains($screen, 'createTicTacToeOptimisticProjection')
        && str_contains($screen, 'clock_pending_authority:true')
        && str_contains($screen, "new CustomEvent('mgw:game-projected'"),
    'The authoritative game screen must own immediate TTT projection and clock handoff.'
);
$assert(
    !str_contains($screen, "timer.textContent = game.status === 'active'")
        && str_contains($screen, "if (game.status !== 'active') timer.textContent = '—';"),
    'Active timer text must have only the Phase B clock owner.'
);
$assert(
    str_contains($clockRuntime, "document.addEventListener('mgw:game-projected'")
        && str_contains($clockRuntime, 'clock.pendingAuthority || beforeTurnStart')
        && !str_contains($clockRuntime, 'new MutationObserver'),
    'The Phase B clock must consume projections directly without observing another timer writer.'
);
$assert(
    str_contains($style, 'width:76px;min-width:76px;flex:0 0 76px')
        && str_contains($style, '.game-board-screen[data-game-type="tictactoe"] .game-player .player-mark-symbol')
        && str_contains($style, 'font-size:18px'),
    'Timer geometry must be fixed and only the mobile TTT mark may be enlarged.'
);
$assert(
    str_contains($mainStyle, "./screens/game.css?v=57&ttt=authoritative-clock"),
    'The active CSS graph must address the new TTT presentation.'
);
$assert(
    str_contains($manifest, 'app/assets/css/screens/game.css')
        && str_contains($manifest, 'app/assets/js/production-regression-fix-entry.js'),
    'Every changed nested runtime owner must remain in exact staging fingerprint coverage.'
);
$assert(
    str_contains($twoContext, 'expectSynchronizedTicTacToeTurn')
        && str_contains($twoContext, "toBe('60 сек|60 сек')")
        && str_contains($twoContext, 'toBeCloseTo(76, 1)')
        && str_contains($twoContext, 'Math.abs(size - 18) < 0.1'),
    'Canonical two-context E2E must prove synchronized full clocks and stable mobile geometry.'
);

fwrite(STDOUT, "TicTacToeAuthoritativeClockUiContractTest: {$assertions} assertions passed\n");
