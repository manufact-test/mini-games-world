<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read v111 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$count = static fn(string $haystack, string $needle): int => substr_count($haystack, $needle);

$entry = $read('app/assets/js/production-clean-entry-v111.js');
$main = $read('app/assets/js/main-v111.js');
$runtime = $read('app/assets/js/production-v111-synced-match-start.js');
$php = $read('app/v111.php');
$clockEndpoint = $read('bot/game-clock.php');
$clockService = $read('bot/services/MatchPreparationClockService.php');
$actions = $read('bot/services/GameActionService.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');
$registry = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');

$assert(
    str_contains($main, "import './main-v105.js?v=105';")
        && str_contains($main, "window.__MGW_BUILD__ = 'v111-mvp14r2-synced-match-start-clock'"),
    'v111 must retain the accepted application/game shell.'
);
$assert(
    $count($entry, 'initV111SyncedMatchStart();') === 1
        && $count($entry, 'initV110AcceptanceRuntime();') === 1,
    'v111 must add one launch owner while retaining the accepted v110 owner exactly once.'
);
$assert(
    !str_contains($entry, 'production-v106-')
        && !str_contains($entry, 'production-v107-')
        && !str_contains($entry, 'production-v108-'),
    'The failed v106-v108 runtime graph must not return.'
);
$assert(
    str_contains($runtime, "api.gameState = gameId => synchronizedState(gameId);")
        && str_contains($runtime, "api.makeMove = (gameId, cell) => api.gameAction(gameId, { type:'cell', cell });"),
    'All state and stale move callers must pass through the shared synchronized owner.'
);
$assert(
    str_contains($runtime, 'retireV110ClockOwner();')
        && str_contains($runtime, 'window.clearInterval(v110.timer)')
        && str_contains($runtime, 'v110.observer?.disconnect?.()')
        && str_contains($runtime, 'reconcileV110PendingMove();'),
    'v111 must retire only the duplicate v110 clock while preserving pending mobile move cleanup.'
);
$assert(
    str_contains($runtime, 'const CLOCK_URL = `${window.location.origin}/bot/game-clock.php`;')
        && str_contains($runtime, "protocol:'v111'")
        && str_contains($clockEndpoint, "if (\$protocol !== 'v111')")
        && str_contains($registry, "'bot/game-clock.php' => 'game_clock'"),
    'Readiness must use an explicit v111 protocol on the registered clock entrypoint.'
);
$assert(
    str_contains($clockEndpoint, 'new MatchPreparationClockService()')
        && str_contains($clockEndpoint, '$matchClock->initializeLaunch($game)')
        && str_contains($clockEndpoint, '$matchClock->enrichPublicGame(')
        && !str_contains($clockEndpoint, 'function mgw_v111_'),
    'The endpoint must remain a thin protocol adapter around one clock service.'
);
$assert(
    str_contains($clockService, 'final class MatchPreparationClockService')
        && str_contains($clockService, 'public const PREPARATION_TIMEOUT_SEC = 10')
        && str_contains($clockService, 'public const COUNTDOWN_SEC = 3')
        && str_contains($clockService, 'public const TURN_HANDOFF_SEC = 1')
        && str_contains($clockService, 'public const MOVE_TIMEOUT_SEC = 60'),
    'One service must own all preparation and turn timing constants.'
);
$assert(
    str_contains($clockService, "hash('sha256', \$sessionId)")
        && str_contains($clockService, "\$game['v111_ready_devices'][\$userId]")
        && str_contains($clockService, 'startCountdownIfReady'),
    'Readiness must be per authenticated device without persisting the raw session ID.'
);
$assert(
    str_contains($clockService, "\$game['turn_starts_at'] = gmdate('c', \$startsAt)")
        && str_contains($clockService, "\$game['turn_deadline_at'] = gmdate('c', \$startsAt + self::MOVE_TIMEOUT_SEC)")
        && str_contains($clockService, "'server_now_ms' => \$serverNowMs"),
    'The service must expose one server start, deadline and time anchor.'
);
$assert(
    str_contains($clockService, 'return array_replace($public, [')
        && str_contains($clockService, "'time_left' => \$timeLeft")
        && str_contains($clockService, "'move_timeout_sec' => self::MOVE_TIMEOUT_SEC"),
    'The authoritative projection must replace legacy time_left rather than lose to PHP array-union precedence.'
);
$assert(
    str_contains($clockEndpoint, "'v106_first_turn_clock_armed_at'")
        && str_contains($clockEndpoint, "\$game['turn_started_at'] = \$now;")
        && str_contains($clockEndpoint, "\$games->publicGame(\$game, \$userId)"),
    'Requests without v111 protocol must preserve the accepted v106 bot-clock rollback.'
);
$assert(
    str_contains($actions, "require_once __DIR__ . '/MatchPreparationClockService.php';")
        && str_contains($actions, '$this->matchClock->assertLaunchReady($game)')
        && str_contains($actions, '$this->matchClock->synchronizeTurnHandoff('),
    'Every game engine action must use the same launch guard and turn-handoff service.'
);
$assert(
    str_contains($actions, "if (\$requestedActionType === 'cancel_preparation')")
        && str_contains($actions, '$this->matchClock->settlePreparationTimeout(')
        && str_contains($clockService, "'category' => 'game_preparation_refund'")
        && str_contains($clockService, "\$game['v111_preparation_refund_done'] = true"),
    'Preparation timeout settlement must be idempotent and entered through primary game_action.'
);
$assert(
    !str_contains($clockEndpoint, "'category' => 'game_preparation_refund'")
        && str_contains($clockService, 'Settlement must run through api.php'),
    'The clock endpoint must never perform financial settlement outside primary API hooks.'
);
$assert(
    str_contains($runtime, "originalGameAction(id, { type:'cancel_preparation' })")
        && str_contains($runtime, 'runtime.timeoutSettling.add(id)')
        && str_contains($runtime, 'runtime.timeoutSettling.delete(id)'),
    'The client must settle an expired preparation once through api.php.'
);
$assert(
    str_contains($runtime, 'mgwV111MatchPreparation')
        && str_contains($runtime, 'Синхронизируем игроков…')
        && str_contains($runtime, 'Подготавливаем поле и единый таймер матча')
        && str_contains($runtime, "tictactoe:'Крестики-нолики'")
        && str_contains($runtime, "domino:'Домино'"),
    'One full-screen preparation component must cover all eight games.'
);
$assert(
    str_contains($runtime, 'Math.ceil((deadline - serverNow) / 1000)')
        && str_contains($runtime, 'serverNow < startsAt')
        && str_contains($runtime, '? timeout'),
    'The visible clock must display 60 before synchronized start and count from the server deadline.'
);
$assert(
    str_contains($php, 'production-clean-entry-v111.js?v=111')
        && str_contains($php, 'main-v111.js?v=111')
        && str_contains($php, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'The v111 no-store candidate entrypoint must be complete.'
);
$assert(
    str_contains($welcome, '/app/v110.php?v=110')
        && !str_contains($welcome, '/app/v111.php?v=111'),
    'v111 must remain inactive until manual acceptance and explicit launch approval.'
);

fwrite(STDOUT, "ProductionV111SyncedMatchStartContractTest: {$assertions} assertions passed\n");
