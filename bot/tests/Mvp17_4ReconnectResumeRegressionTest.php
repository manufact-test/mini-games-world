<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix): string {
        static $counter = 0;
        return $prefix . '_resume_test_' . (++$counter);
    }
}

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/mgw-mvp17-4-resume-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Unable to create temporary runtime directory.');
}
$GLOBALS['config'] = ['data_dir' => $temp];

require_once $root . '/services/PresenceService.php';
require_once $root . '/services/GameSettlementService.php';
require_once $root . '/services/GameNoContestSettlementService.php';
require_once $root . '/services/ReconnectLifecycleService.php';
require_once $root . '/services/MatchPreparationClockService.php';
require_once $root . '/services/MatchPreparationRuntimeService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};

$makeFrozenDb = static function (string $gameId, bool $withLaunchPhase): array {
    $now = time();
    $originalTurnStart = $now - 20;
    $originalDeadline = $now + 40;
    $pauseStartedMs = ($now - 5) * 1000;
    $future = $now + 86400;

    $game = [
        'id' => $gameId,
        'status' => 'active',
        'room' => 'match',
        'game_type' => 'tictactoe',
        'bet' => 100,
        'player_ids' => ['u1', 'u2'],
        'turn' => 'u1',
        'board' => 'X-O-X----',
        'turn_started_at' => gmdate('c', $future),
        'turn_starts_at' => gmdate('c', $future),
        'turn_starts_epoch_ms' => $future * 1000,
        'turn_deadline_at' => gmdate('c', $future),
        'turn_deadline_epoch_ms' => $future * 1000,
        'clock_turn' => 'u1',
        'clock_revision' => 2,
        'reconnect_v2' => [
            'version' => 2,
            'paused' => true,
            'paused_at_ms' => $pauseStartedMs,
            'players' => [
                'u1' => [
                    'disconnected_at_ms' => $pauseStartedMs,
                    'disconnected_at' => gmdate('c', intdiv($pauseStartedMs, 1000)),
                    'deadline_ms' => $pauseStartedMs + 60000,
                    'deadline_at' => gmdate('c', intdiv($pauseStartedMs + 60000, 1000)),
                ],
            ],
            'clock_snapshot' => [
                'turn_started_at' => gmdate('c', $originalTurnStart),
                'turn_starts_at' => gmdate('c', $originalTurnStart),
                'turn_starts_epoch_ms' => $originalTurnStart * 1000,
                'turn_deadline_at' => gmdate('c', $originalDeadline),
                'turn_deadline_epoch_ms' => $originalDeadline * 1000,
            ],
        ],
    ];
    if ($withLaunchPhase) $game['launch_phase'] = 'active';

    return [
        'db' => [
            'users' => [
                'u1' => [
                    'id' => 'u1',
                    'username' => 'u1',
                    'balance' => 900,
                    'status' => 'playing',
                    'current_game_id' => $gameId,
                    'active_session_id' => 'session-old',
                    'active_session_at' => gmdate('c', $now - 10),
                    'reconnect_game_id' => $gameId,
                    'reconnect_until' => gmdate('c', $now + 55),
                    'stats' => [],
                ],
                'u2' => [
                    'id' => 'u2',
                    'username' => 'u2',
                    'balance' => 900,
                    'status' => 'playing',
                    'current_game_id' => $gameId,
                    'active_session_id' => 'session-u2',
                    'active_session_at' => now_iso(),
                    'stats' => [],
                ],
            ],
            'games' => [$gameId => $game],
            'transactions' => [],
            'system' => [],
        ],
        'future_ms' => $future * 1000,
    ];
};

$assertResumed = static function (
    MatchPreparationRuntimeService $runtime,
    array $fixture,
    string $gameId,
    bool $expectLaunchPhase
) use ($assert): void {
    $db = $fixture['db'];
    $user =& $db['users']['u1'];
    $result = $runtime->synchronizeCurrentGame(
        $db,
        $user,
        $gameId,
        $gameId,
        'session-new',
        'device-new'
    );

    $assert(is_array($result), 'Current game must still be returned after reconnect resume.');
    $assert(!isset($db['games'][$gameId]['reconnect_v2']), 'Authenticated game_state must clear a live reconnect freeze.');
    $assert((string)$db['users']['u1']['active_session_id'] === 'session-new', 'Reconnected client must own the active session.');
    $assert((string)$db['users']['u1']['current_game_id'] === $gameId, 'Reconnect must keep the same game id.');

    $restoredDeadlineMs = (int)($db['games'][$gameId]['turn_deadline_epoch_ms'] ?? 0);
    $remainingMs = $restoredDeadlineMs - ((int)floor(microtime(true) * 1000));
    $assert($remainingMs > 42000 && $remainingMs <= 48000, 'Move clock must resume with the pre-disconnect remaining time, not stay frozen at 60 seconds.');
    $assert($restoredDeadlineMs < (int)$fixture['future_ms'], 'Frozen one-day guard deadline must be removed on reconnect.');

    if ($expectLaunchPhase) {
        $assert((string)($db['games'][$gameId]['launch_phase'] ?? '') === 'active', 'Phase-B game must keep its active launch phase.');
    } else {
        $assert(!array_key_exists('launch_phase', $db['games'][$gameId]), 'Legacy compatibility reconnect must not introduce launch_phase.');
    }
    unset($user);
};

try {
    $runtime = new MatchPreparationRuntimeService([
        'commission_rate' => 0.10,
        'active_session_timeout_sec' => 180,
    ]);

    // Existing regression: a Phase-B active match must resume.
    $assertResumed($runtime, $makeFrozenDb('g-phase-b', true), 'g-phase-b', true);

    // Manual-acceptance regression: a compatible active match that predates
    // launch_phase may still enter reconnect_v2. It must resume on game_state
    // instead of returning early with the one-day frozen guard clock intact.
    $assertResumed($runtime, $makeFrozenDb('g-legacy', false), 'g-legacy', false);

    $runtimeSource = file_get_contents($root . '/services/MatchPreparationRuntimeService.php');
    $reconnectCall = is_string($runtimeSource)
        ? strpos($runtimeSource, '$this->reconnect->synchronize($db, $userId, $sessionId, \'ping\', []);')
        : false;
    $legacyGateNeedle = "if (!array_key_exists('launch_phase', " . '$game' . '))';
    $legacyGate = is_string($runtimeSource)
        ? strpos($runtimeSource, $legacyGateNeedle)
        : false;
    $assert(
        is_int($reconnectCall) && is_int($legacyGate) && $reconnectCall < $legacyGate,
        'Reconnect resume must run before the legacy no-launch-phase compatibility return.'
    );

    $index = file_get_contents(dirname($root) . '/app/index.html');
    $entry = file_get_contents(dirname($root) . '/app/v114.php');
    $main = file_get_contents(dirname($root) . '/app/assets/js/main.js');
    $assert(
        is_string($index) && str_contains($index, './assets/js/main.js?v=98.4-wallet-15-3'),
        'Stable index source anchor must remain unchanged for canonical entry transforms.'
    );
    $assert(
        is_string($entry) && str_contains($entry, './assets/js/main.js?v=d2-unified-wallet-15-3-r1743'),
        'Canonical v114 entry owner must cache-bust the authoritative reconnect boot bundle.'
    );
    $assert(is_string($main) && str_contains($main, "./presence-v115.js?v=1742"), 'Presence module identity must remain unchanged by the boot-only corrective pass.');
    $assert(is_string($main) && str_contains($main, "mvp17-4-authoritative-reconnect-boot-r3"), 'Frontend build marker must identify the authoritative reconnect boot pass.');

    $authoritativeBoot = is_string($main)
        ? strpos($main, 'const activeState = await api.gameState(result.active_game.id);')
        : false;
    $adoptAuthoritative = is_string($main)
        ? strpos($main, 'state.activeGame = activeGame;')
        : false;
    $oldBootstrapAdoption = is_string($main)
        ? strpos($main, 'state.activeGame = result.active_game;')
        : false;
    $assert(
        is_int($authoritativeBoot)
            && is_int($adoptAuthoritative)
            && $authoritativeBoot < $adoptAuthoritative
            && $oldBootstrapAdoption === false,
        'Reopened active matches must await authoritative game_state before becoming local activeGame state.'
    );

    $clockSource = file_get_contents($root . '/services/MatchPreparationClockService.php');
    $assert(
        is_string($clockSource) && preg_match('/\bMOVE_TIMEOUT_SEC\s*=\s*60\s*;/', $clockSource) === 1,
        'Normal move timeout must remain exactly 60 seconds.'
    );

    fwrite(STDOUT, "Mvp17_4ReconnectResumeRegressionTest: {$assertions} assertions passed\n");
} finally {
    $removeTree($temp);
}
