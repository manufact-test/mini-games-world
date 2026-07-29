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
$clock = $read('bot/game-clock.php');
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
    str_contains($runtime, "const CLOCK_URL = `${window.location.origin}/bot/game-clock.php`;")
        && str_contains($registry, "'bot/game-clock.php' => 'game_clock'"),
    'Readiness must use the registered DB-primary clock entrypoint.'
);
$assert(
    str_contains($clock, "hash('sha256', \$sessionId)")
        && str_contains($clock, "\$game['v111_ready_devices'][\$userId]")
        && str_contains($clock, 'mgw_v111_all_ready'),
    'Readiness must be per authenticated device without persisting the raw session ID.'
);
$assert(
    str_contains($clock, 'MGW_V111_PREPARATION_TIMEOUT_SEC = 10')
        && str_contains($clock, 'MGW_V111_COUNTDOWN_SEC = 3')
        && str_contains($clock, "\$game['launch_phase'] = 'countdown'"),
    'A bounded preparation phase and common countdown must be server-owned.'
);
$assert(
    str_contains($clock, "\$game['turn_starts_at'] = gmdate('c', \$startsAt)")
        && str_contains($clock, "\$game['turn_deadline_at'] = gmdate('c', \$startsAt + MGW_V111_MOVE_TIMEOUT_SEC)")
        && str_contains($clock, "'server_now_ms' => \$serverNowMs"),
    'The first turn must expose one server start, deadline and time anchor.'
);
$assert(
    str_contains($actions, 'TURN_HANDOFF_DELAY_SEC = 1')
        && str_contains($actions, 'synchronizeTurnHandoff')
        && str_contains($actions, "\$game['turn_deadline_at'] = gmdate('c', \$startsAt + self::MOVE_TIMEOUT_SEC)"),
    'Every engine turn change must receive the same future handoff contract.'
);
$assert(
    str_contains($actions, "if (\$requestedActionType === 'cancel_preparation')")
        && str_contains($actions, "'category' => 'game_preparation_refund'")
        && str_contains($actions, "\$game['v111_preparation_refund_done'] = true"),
    'Preparation timeout settlement must be idempotent in the primary game action.'
);
$assert(
    !str_contains($clock, "'category' => 'game_preparation_refund'")
        && str_contains($clock, 'Financial settlement must go through api.php'),
    'The clock endpoint must never perform financial settlement outside primary API hooks.'
);
$assert(
    str_contains($runtime, "originalGameAction(id, { type:'cancel_preparation' })")
        && str_contains($runtime, "runtime.timeoutSettling.add(id)")
        && str_contains($runtime, "runtime.timeoutSettling.delete(id)"),
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
    'The visible clock must display 60 before the synchronized start and count from the server deadline.'
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
