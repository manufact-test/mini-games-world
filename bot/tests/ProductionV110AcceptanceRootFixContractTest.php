<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v110 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$count = static fn(string $haystack, string $needle): int => substr_count($haystack, $needle);

$entry = $read('app/assets/js/production-clean-entry-v110.js');
$main = $read('app/assets/js/main-v110.js');
$runtime = $read('app/assets/js/production-v110-acceptance-runtime.js');
$php = $read('app/v110.php');
$presence = $read('bot/presence.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$legacyV105Ttt = $read('app/assets/js/production-v105-tictactoe-stability.js');
$legacyV109Notifications = $read('app/assets/js/production-v109-notifications.js');

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v110-mvp14r2-acceptance-root-fixes'"),
    'v110 must retain the accepted shell while replacing only the failed acceptance owners.'
);
$assert(
    $count($entry, 'initV110AcceptanceRuntime();') === 1
        && str_contains($entry, "window.__MGW_REGRESSION_BUILD__ = 'v110-mvp14r2-acceptance-root-fixes'"),
    'The v110 acceptance runtime must have exactly one initializer.'
);
$assert(
    !str_contains($entry, 'initV105TicTacToeStability')
        && !str_contains($entry, 'production-v105-tictactoe-stability.js')
        && str_contains($legacyV105Ttt, 'window.fetch = stableFetch'),
    'The retired v105 fetch wrapper and DOM pin must not load in the v110 graph.'
);
$assert(
    !str_contains($entry, 'initV109Notifications')
        && !str_contains($entry, 'production-v109-notifications.js')
        && str_contains($legacyV109Notifications, 'function enrichInviteActions'),
    'The v109 client-side invitation action synthesizer must not load in v110.'
);
$assert(
    str_contains($runtime, 'next.actions = Array.isArray(item?.actions) ? [...item.actions] : [];')
        && !str_contains($runtime, 'function enrichInviteActions')
        && !str_contains($runtime, "type === 'invite_accepted'")
        && !str_contains($runtime, '/bot/invites.php'),
    'Notification actions and list data must come only from the authoritative notifications endpoint.'
);
$assert(
    str_contains($runtime, 'seedToastPreview(toast);')
        && strpos($runtime, 'renderNotifications(currentNotifications(), currentNotifications().length === 0);')
            < strpos($runtime, 'await readNotificationSnapshot()'),
    'A clicked toast or cached notification must paint before the authoritative refresh.'
);
$assert(
    str_contains($runtime, "window.addEventListener('click', guardAndTrackTicTacToe, true)")
        && str_contains($runtime, 'event.stopImmediatePropagation();')
        && str_contains($runtime, "String(authoritative?.turn || '') !== viewerId")
        && str_contains($runtime, "board[cell] !== '-'"),
    'Invalid Tic Tac Toe taps must be rejected before any retained mobile owner can preview a mark.'
);
$assert(
    str_contains($runtime, 'window.requestAnimationFrame')
        && str_contains($runtime, 'paintPendingMove();')
        && !str_contains($runtime, 'window.fetch =')
        && !str_contains($runtime, 'new MutationObserver(schedulePendingPaint)'),
    'The valid mobile mark must be pinned by one bounded frame owner without wrapping fetch or observing its own paint.'
);
$assert(
    str_contains($runtime, 'if (serverRemainingMs + 700 < localRemaining)')
        && str_contains($runtime, 'Never jump upward on a same-turn poll')
        && str_contains($runtime, 'Math.ceil((clock.deadline - performance.now()) / 1000)')
        && str_contains($runtime, "if (value === null || value === undefined || value === '') return null;"),
    'The visible timer must be smooth, monotonic for one turn and must not treat missing server timestamps as zero.'
);
$assert(
    str_contains($runtime, 'mgw-v110-search-summary')
        && str_contains($runtime, '#searchInfo{min-height:2.9em}')
        && str_contains($runtime, 'secondary:type === \'domino\' ? \'Классика 0–6\' : `Поле ${size}×${size}`'),
    'The complete search conditions must reserve their two-line layout before screen activation.'
);
$assert(
    str_contains($presence, "if (\$action === 'ping' || \$action === 'status') \$presence->touch(\$accountId, \$sessionId);")
        && str_contains($presence, "if (\$sessionId === '') throw new RuntimeException('Сессия устройства не найдена.');")
        && strpos($presence, '$presence->touch') < strpos($presence, '$stats->build'),
    'An authenticated status read must confirm the current session before unique-account counting.'
);
$assert(
    str_contains($php, 'production-clean-entry-v110.js?v=110')
        && str_contains($php, 'main-v110.js?v=110')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
        && str_contains($welcome, '/app/v109.php?v=109')
        && !str_contains($welcome, '/app/v110.php?v=110'),
    'The v110 no-store candidate must exist without changing the accepted Telegram launch guard.'
);
$assert(
    !str_contains($runtime, '/bot/game-clock.php')
        && !str_contains($runtime, 'reset_clock')
        && !str_contains($runtime, 'move_timeout_sec:60'),
    'The acceptance fix must not mutate or reset the server clock through a new endpoint.'
);
$assert(
    !str_contains($entry, 'production-v106-')
        && !str_contains($entry, 'production-v107-')
        && !str_contains($entry, 'production-v108-'),
    'The failed v106-v108 runtime chain must not return in v110.'
);

fwrite(STDOUT, "ProductionV110AcceptanceRootFixContractTest: {$assertions} assertions passed\n");
