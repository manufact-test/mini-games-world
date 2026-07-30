<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v112 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$count = static fn(string $haystack, string $needle): int => substr_count($haystack, $needle);

$entry = $read('app/assets/js/production-clean-entry-v112.js');
$main = $read('app/assets/js/main-v112.js');
$runtime = $read('app/assets/js/production-v112-instant-launch-overlay.js');
$php = $read('app/v112.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$v110 = $read('app/assets/js/production-v110-acceptance-runtime.js');

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v112-mvp14r2-instant-launch-overlay'"),
    'v112 must retain the accepted application shell.'
);
$assert(
    $count($entry, 'initV112InstantLaunchOverlay();') === 1
        && $count($entry, 'initV110AcceptanceRuntime();') === 1,
    'v112 must add one overlay owner while retaining the accepted v110 owner exactly once.'
);
$assert(
    strpos($runtime, "window.addEventListener('pointerdown', handleLaunchIntent, true)") !== false
        && strpos($runtime, 'showOverlay(readInviteGameTitle());') !== false
        && strpos($runtime, 'watchLaunchResult();') > strpos($runtime, 'showOverlay(readInviteGameTitle());'),
    'The owner launch overlay must paint immediately from pointerdown before waiting for launch completion.'
);
$assert(
    str_contains($runtime, "origin.closest('[data-invite-action=\"start\"]')")
        && str_contains($runtime, 'Number(event.detail || 0) !== 0'),
    'Pointer and keyboard launch paths must share the same immediate overlay owner.'
);
$assert(
    str_contains($runtime, "new MutationObserver(() =>")
        && str_contains($runtime, "gameScreen.classList.contains('active')")
        && str_contains($runtime, 'if (!isOverlayVisible()) showOverlay'),
    'The second device and all non-button game entries must receive the overlay before the game screen paints.'
);
$assert(
    str_contains($runtime, 'Boolean(state.activeGame?.id)')
        && str_contains($runtime, 'Boolean(board?.childElementCount)')
        && str_contains($runtime, 'Boolean(players?.childElementCount)'),
    'Overlay dismissal must wait for the real rendered game surface, not a decorative fixed delay alone.'
);
$assert(
    str_contains($runtime, 'Готовим игру')
        && str_contains($runtime, 'Подключаем игроков…')
        && str_contains($runtime, 'Матч начнётся через мгновение'),
    'The overlay copy must be human-facing and non-technical.'
);
$assert(
    str_contains($runtime, 'mgw-v112-progress')
        && !str_contains($runtime, '3… 2… 1')
        && !str_contains($runtime, '>3<')
        && !str_contains($runtime, '>2<')
        && !str_contains($runtime, '>1<'),
    'The visual must use an indeterminate progress treatment and no disruptive numeric countdown.'
);
$assert(
    str_contains($runtime, 'position:fixed;inset:0')
        && str_contains($runtime, '.mgw-v112-launch-overlay[hidden]{display:none!important}'),
    'The launch visual must cover the app without changing document layout.'
);
$assert(
    !str_contains($runtime, 'window.fetch =')
        && !str_contains($runtime, '/bot/')
        && !str_contains($runtime, 'game-clock.php')
        && !str_contains($runtime, 'timerText')
        && !str_contains($runtime, 'turn_started_at')
        && !str_contains($runtime, 'deadline'),
    'The visual candidate must not wrap transport or mutate server readiness, game rules or clocks.'
);
$assert(
    str_contains($runtime, "tictactoe:'Крестики-нолики'")
        && str_contains($runtime, "four_in_a_row:'4 в ряд'")
        && str_contains($runtime, "battleship:'Морской бой'")
        && str_contains($runtime, "checkers:'Шашки'")
        && str_contains($runtime, "reversi:'Реверси'")
        && str_contains($runtime, "chess:'Шахматы'")
        && str_contains($runtime, "go:'Го'")
        && str_contains($runtime, "domino:'Домино'"),
    'One visual component must support all eight games.'
);
$assert(
    str_contains($php, 'production-clean-entry-v112.js?v=112')
        && str_contains($php, 'main-v112.js?v=112')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The no-store v112 candidate entrypoint must be complete.'
);
$assert(
    str_contains($welcome, '/app/v112.php?v=112')
        && str_contains($welcome, '/app/v110.php?v=110')
        && str_contains($welcome, 'Current authorized acceptance target: v112'),
    'v112 must be the authorized Telegram launch while v110 remains an explicit rollback.'
);
$assert(
    str_contains($v110, 'seedToastPreview(toast);')
        && str_contains($v110, 'guardAndTrackTicTacToe')
        && str_contains($v110, 'mgw-v110-search-summary'),
    'The previously accepted v110 notification, Tic Tac Toe and search fixes must remain available.'
);

fwrite(STDOUT, "ProductionV112InstantLaunchOverlayContractTest: {$assertions} assertions passed\n");
