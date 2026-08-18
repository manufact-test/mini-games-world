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

try {
    $now = time();
    $originalTurnStart = $now - 20;
    $originalDeadline = $now + 40;
    $pauseStartedMs = ($now - 5) * 1000;
    $future = $now + 86400;

    $db = [
        'users' => [
            'u1' => [
                'id' => 'u1',
                'username' => 'u1',
                'balance' => 900,
                'status' => 'playing',
                'current_game_id' => 'g1',
                'active_session_id' => 'session-old',
                'active_session_at' => gmdate('c', $now - 10),
                'reconnect_game_id' => 'g1',
                'reconnect_until' => gmdate('c', $now + 55),
                'stats' => [],
            ],
            'u2' => [
                'id' => 'u2',
                'username' => 'u2',
                'balance' => 900,
                'status' => 'playing',
                'current_game_id' => 'g1',
                'active_session_id' => 'session-u2',
                'active_session_at' => now_iso(),
                'stats' => [],
            ],
        ],
        'games' => [
            'g1' => [
                'id' => 'g1',
                'status' => 'active',
                'launch_phase' => 'active',
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
            ],
        ],
        'transactions' => [],
        'system' => [],
    ];

    $runtime = new MatchPreparationRuntimeService([
        'commission_rate' => 0.10,
        'active_session_timeout_sec' => 180,
    ]);
    $user =& $db['users']['u1'];
    $result = $runtime->synchronizeCurrentGame(
        $db,
        $user,
        'g1',
        'g1',
        'session-new',
        'device-new'
    );

    $assert(is_array($result), 'Current game must still be returned after reconnect resume.');
    $assert(!isset($db['games']['g1']['reconnect_v2']), 'Authenticated game_state must clear a live reconnect freeze.');
    $assert((string)$db['users']['u1']['active_session_id'] === 'session-new', 'Reconnected client must own the active session.');
    $assert((string)$db['users']['u1']['current_game_id'] === 'g1', 'Reconnect must keep the same game id.');

    $restoredDeadlineMs = (int)($db['games']['g1']['turn_deadline_epoch_ms'] ?? 0);
    $remainingMs = $restoredDeadlineMs - ((int)floor(microtime(true) * 1000));
    $assert($remainingMs > 35000 && $remainingMs <= 42000, 'Move clock must resume with the pre-disconnect remaining time, not stay frozen at 60 seconds.');
    $assert($restoredDeadlineMs < ($future * 1000), 'Frozen one-day guard deadline must be removed on reconnect.');

    $runtimeSource = file_get_contents($root . '/services/MatchPreparationRuntimeService.php');
    $assert(
        is_string($runtimeSource)
            && str_contains($runtimeSource, "$this->reconnect->synchronize") === false
            && str_contains($runtimeSource, '$this->reconnect->synchronize($db, $userId, $sessionId, \'ping\', []);'),
        'game_state lifecycle must reuse the canonical reconnect owner before clock synchronization.'
    );

    $index = file_get_contents(dirname($root) . '/app/index.html');
    $main = file_get_contents(dirname($root) . '/app/assets/js/main.js');
    $assert(is_string($index) && str_contains($index, './assets/js/main.js?v=1742'), 'Entrypoint must bust the cached main reconnect bundle.');
    $assert(is_string($main) && str_contains($main, "./presence-v115.js?v=1742"), 'Main bundle must bust the cached presence module.');

    $clockSource = file_get_contents($root . '/services/MatchPreparationClockService.php');
    $assert(
        is_string($clockSource) && preg_match('/\bMOVE_TIMEOUT_SEC\s*=\s*60\s*;/', $clockSource) === 1,
        'Normal move timeout must remain exactly 60 seconds.'
    );

    fwrite(STDOUT, "Mvp17_4ReconnectResumeRegressionTest: {$assertions} assertions passed\n");
} finally {
    unset($user);
    $removeTree($temp);
}
