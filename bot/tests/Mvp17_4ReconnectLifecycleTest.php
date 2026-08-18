<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix): string {
        static $counter = 0;
        return $prefix . '_test_' . (++$counter);
    }
}

$root = dirname(__DIR__);
require_once $root . '/services/PresenceService.php';
require_once $root . '/services/GameSettlementService.php';
require_once $root . '/services/GameNoContestSettlementService.php';
require_once $root . '/services/ReconnectLifecycleService.php';
require_once $root . '/services/SessionService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$temp = sys_get_temp_dir() . '/mgw-mvp17-4-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Unable to create temporary presence directory.');
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};

$newUser = static function (string $id, string $session, string $gameId): array {
    return [
        'id' => $id,
        'username' => $id,
        'balance' => 900,
        'status' => 'playing',
        'current_game_id' => $gameId,
        'active_session_id' => $session,
        'active_session_at' => now_iso(),
        'stats' => [
            'games_played' => 0,
            'match_games_this_week' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
        ],
    ];
};

$newHumanGameDb = static function (string $gameId, string $a, string $b) use ($newUser): array {
    $now = time();
    return [
        'users' => [
            $a => $newUser($a, 'session-' . $a, $gameId),
            $b => $newUser($b, 'session-' . $b, $gameId),
        ],
        'games' => [
            $gameId => [
                'id' => $gameId,
                'status' => 'active',
                'launch_phase' => 'active',
                'room' => 'match',
                'game_type' => 'tictactoe',
                'bet' => 100,
                'player_ids' => [$a, $b],
                'turn' => $a,
                'turn_started_at' => gmdate('c', $now - 20),
                'turn_deadline_at' => gmdate('c', $now + 40),
                'turn_deadline_epoch_ms' => ($now + 40) * 1000,
            ],
        ],
        'transactions' => [],
        'system' => [],
    ];
};

$config = ['commission_rate' => 0.10, 'active_session_timeout_sec' => 180];
$presence = new PresenceService($temp);
$lifecycle = new ReconnectLifecycleService($config, $presence);
$sessions = new SessionService($config);

try {
    $db = $newHumanGameDb('g-human', 'u1', 'u2');
    $presence->touch('u1', 'session-u1', 'lease-u1');
    $presence->touch('u2', 'session-u2', 'lease-u2');
    $presence->background('u1', 'session-u1', 'lease-u1');
    $assert($presence->gameplaySnapshot('u1')['state'] === 'background', 'Background must remain connected-idle.');
    $assert(!$lifecycle->needsMutation($db, 'u1', 'session-u1', 'background', ['state' => 'foreground']), 'Background must not start reconnect.');
    $assert(!isset($db['games']['g-human']['reconnect_v2']), 'Background must not mutate game state.');

    $presence->touch('u1', 'session-u1', 'lease-u1');
    $previous = $presence->gameplaySnapshot('u1');
    $originalTurnStarted = strtotime((string)$db['games']['g-human']['turn_started_at']);
    $originalDeadlineMs = (int)$db['games']['g-human']['turn_deadline_epoch_ms'];
    $presence->leave('u1', 'session-u1', 'lease-u1');
    $assert($lifecycle->needsMutation($db, 'u1', 'session-u1', 'leave', $previous), 'Explicit leave must start reconnect.');
    $lifecycle->synchronize($db, 'u1', 'session-u1', 'leave', $previous);

    $reconnect = $db['games']['g-human']['reconnect_v2'] ?? null;
    $assert(is_array($reconnect) && !empty($reconnect['paused']), 'Disconnect must pause match.');
    $deadlineMs = (int)($reconnect['players']['u1']['deadline_ms'] ?? 0);
    $disconnectedAtMs = (int)($reconnect['players']['u1']['disconnected_at_ms'] ?? 0);
    $assert($deadlineMs - $disconnectedAtMs === 60000, 'Reconnect window must be exactly 60 seconds.');
    $assert(strtotime((string)$db['games']['g-human']['turn_started_at']) > time() + 3600, 'Legacy clock must be frozen during reconnect.');
    $assert((int)$db['games']['g-human']['turn_deadline_epoch_ms'] > (time() + 3600) * 1000, 'Millisecond deadline must be frozen during reconnect.');
    $assert($sessions->canTakeSession($db['users']['u1'], 'session-u1-new'), 'New client may take session inside reconnect window.');

    $previous = $presence->gameplaySnapshot('u1');
    $presence->touch('u1', 'session-u1-new', 'lease-u1-new');
    $lifecycle->synchronize($db, 'u1', 'session-u1-new', 'ping', $previous);
    $assert(!isset($db['games']['g-human']['reconnect_v2']), 'Successful reconnect must clear pause.');
    $assert((string)$db['users']['u1']['current_game_id'] === 'g-human', 'Reconnect must restore same game.');
    $assert((string)$db['users']['u1']['active_session_id'] === 'session-u1-new', 'Reconnect must transfer session ownership.');
    $restoredTurnStarted = strtotime((string)$db['games']['g-human']['turn_started_at']);
    $restoredDeadlineMs = (int)$db['games']['g-human']['turn_deadline_epoch_ms'];
    $assert($restoredTurnStarted >= $originalTurnStarted && $restoredTurnStarted <= $originalTurnStarted + 3, 'Legacy clock must resume with only actual pause added.');
    $assert($restoredDeadlineMs >= $originalDeadlineMs && $restoredDeadlineMs <= $originalDeadlineMs + 3000, 'Millisecond deadline must preserve remaining move time.');

    $lockedUser = $newUser('locked', 'session-old', 'g-locked');
    $assert(!$sessions->canTakeSession($lockedUser, 'session-new'), 'Device lock must remain outside reconnect.');

    $botNow = time();
    $dbBot = [
        'users' => ['u5' => $newUser('u5', 'session-u5', 'g-bot')],
        'games' => [
            'g-bot' => [
                'id' => 'g-bot',
                'status' => 'active',
                'launch_phase' => 'active',
                'room' => 'match',
                'game_type' => 'tictactoe',
                'bet' => 100,
                'player_ids' => ['u5', 'bot_test'],
                'turn' => 'u5',
                'turn_started_at' => gmdate('c', $botNow - 10),
                'turn_deadline_at' => gmdate('c', $botNow + 50),
                'turn_deadline_epoch_ms' => ($botNow + 50) * 1000,
                'is_bot_game' => true,
                'bot_id' => 'bot_test',
                'bot_difficulty' => 'medium',
            ],
        ],
        'transactions' => [],
        'system' => [],
    ];
    $presence->touch('u5', 'session-u5', 'lease-u5');
    $previous = $presence->gameplaySnapshot('u5');
    $presence->leave('u5', 'session-u5', 'lease-u5');
    $lifecycle->synchronize($dbBot, 'u5', 'session-u5', 'leave', $previous);
    $assert(($dbBot['games']['g-bot']['status'] ?? '') === 'active', 'Bot match must remain active during reconnect window.');
    $assert(empty($dbBot['games']['g-bot']['no_contest']), 'Human-vs-bot disconnect must not become both-disconnected.');
    $dbBot['games']['g-bot']['reconnect_v2']['players']['u5']['deadline_ms'] = 1;
    $lifecycle->synchronize($dbBot, 'nobody', 'session-none', 'status', []);
    $assert(($dbBot['games']['g-bot']['status'] ?? '') === 'finished', 'Expired bot reconnect must finish match.');
    $assert(($dbBot['games']['g-bot']['finish_reason'] ?? '') === 'disconnect_timeout', 'Expired reconnect must be technical loss.');
    $assert((int)($dbBot['users']['u5']['stats']['losses'] ?? 0) === 1, 'Technical disconnect loss must count as loss.');
    $assert((int)$dbBot['users']['u5']['balance'] === 900, 'Technical disconnect loss must not refund entry.');

    $dbBoth = $newHumanGameDb('g-both', 'u3', 'u4');
    $presence->touch('u3', 'session-u3', 'lease-u3');
    $presence->touch('u4', 'session-u4', 'lease-u4');
    $previousU3 = $presence->gameplaySnapshot('u3');
    $presence->leave('u3', 'session-u3', 'lease-u3');
    $lifecycle->synchronize($dbBoth, 'u3', 'session-u3', 'leave', $previousU3);
    $previousU4 = $presence->gameplaySnapshot('u4');
    $presence->leave('u4', 'session-u4', 'lease-u4');
    $assert($lifecycle->needsMutation($dbBoth, 'u4', 'session-u4', 'leave', $previousU4), 'Second disconnect must be observed while paused.');
    $lifecycle->synchronize($dbBoth, 'u4', 'session-u4', 'leave', $previousU4);
    $assert(($dbBoth['games']['g-both']['finish_reason'] ?? '') === 'both_disconnected', 'Two humans disconnected must settle no-contest.');
    $assert(!empty($dbBoth['games']['g-both']['no_contest']), 'Both-disconnected must carry no-contest marker.');
    $assert((int)$dbBoth['users']['u3']['balance'] === 1000 && (int)$dbBoth['users']['u4']['balance'] === 1000, 'Both entries must be refunded.');
    $assert((int)$dbBoth['users']['u3']['stats']['games_played'] === 0 && (int)$dbBoth['users']['u4']['stats']['games_played'] === 0, 'No-contest must not increment match stats.');

    $dbFailure = $newHumanGameDb('g-failure', 'u6', 'u7');
    $cancelled = $lifecycle->cancelActiveGamesForServerFailure($dbFailure, 'incident-test');
    $assert($cancelled === 1, 'Server-failure path must cancel active match once.');
    $assert(($dbFailure['games']['g-failure']['finish_reason'] ?? '') === 'server_failure', 'Server failure needs dedicated reason.');
    $assert((int)$dbFailure['users']['u6']['balance'] === 1000 && (int)$dbFailure['users']['u7']['balance'] === 1000, 'Server failure must refund entries.');
    $assert((int)$dbFailure['users']['u6']['stats']['games_played'] === 0 && (int)$dbFailure['users']['u7']['stats']['games_played'] === 0, 'Server failure must not change match stats.');
    $assert((int)($dbFailure['system']['telemetry']['server_failure_cancelled_games_total'] ?? 0) === 1, 'Server failure must be logged.');

    $clockSource = file_get_contents($root . '/services/MatchPreparationClockService.php');
    $assert(is_string($clockSource) && str_contains($clockSource, 'private const MOVE_TIMEOUT_SEC = 60;'), 'Existing 60-second move timeout must remain unchanged.');

    fwrite(STDOUT, "Mvp17_4ReconnectLifecycleTest: {$assertions} assertions passed\n");
} finally {
    $removeTree($temp);
}
