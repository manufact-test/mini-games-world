<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string
    {
        return gmdate('c');
    }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string
    {
        static $n = 0;
        $n++;
        return $prefix . '_runtime_test_' . $n;
    }
}

require_once dirname(__DIR__) . '/services/MatchPreparationRuntimeService.php';

$service = new MatchPreparationRuntimeService(['commission_rate' => 0.10]);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$stats = [
    'games_played' => 7,
    'match_games_this_week' => 2,
    'wins' => 3,
    'losses' => 2,
    'draws' => 2,
];
$makeUser = static function (string $id, string $gameId, int $balance = 90) use ($stats): array {
    return [
        'id' => $id,
        'username' => strtoupper($id),
        'balance_match' => $balance,
        'status' => 'playing',
        'current_game_id' => $gameId,
        'stats' => $stats,
    ];
};
$makePreparingGame = static function (string $id, int $deadlineOffset = 30): array {
    return [
        'id' => $id,
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'bank' => 20,
        'player_ids' => ['u1', 'u2'],
        'status' => 'active',
        'launch_phase' => 'preparing',
        'preparing_started_at' => gmdate('c', time() - 1),
        'preparation_deadline_at' => gmdate('c', time() + $deadlineOffset),
        'preparation_ready_devices' => [],
        'starts_at' => null,
        'turn' => 'u1',
        'turn_started_at' => gmdate('c', time() + max(1, $deadlineOffset)),
        'turn_starts_at' => null,
        'turn_deadline_at' => null,
        'clock_turn' => '',
        'clock_revision' => 0,
        'winner_id' => null,
        'loser_id' => null,
        'finish_reason' => null,
        'payout_done' => false,
        'is_bot_game' => false,
        'created_at' => gmdate('c', time() - 2),
        'updated_at' => gmdate('c', time() - 1),
    ];
};

// Legacy games remain a strict no-op for the dormant Phase B runtime owner.
$db = [
    'users' => ['u1' => $makeUser('u1', 'legacy')],
    'games' => [
        'legacy' => [
            'id' => 'legacy',
            'status' => 'active',
            'player_ids' => ['u1', 'u2'],
            'turn' => 'u1',
            'turn_started_at' => now_iso(),
        ],
    ],
];
$before = $db['games']['legacy'];
$user =& $db['users']['u1'];
$result = $service->synchronizeCurrentGame($db, $user, 'legacy', 'legacy', 'sess-a', 'device-a');
$assert($result === $before && $db['games']['legacy'] === $before,
    'Legacy games without launch_phase must remain byte-for-byte unchanged.');
unset($user);

// Discovery polling owns advancement only; it must never signal readiness.
$game = $makePreparingGame('discover');
$deadline = $game['preparation_deadline_at'];
$db = [
    'users' => ['u1' => $makeUser('u1', 'discover')],
    'games' => ['discover' => $game],
];
$user =& $db['users']['u1'];
$service->synchronizeCurrentGame($db, $user, 'discover', '', 'sess-a', 'device-a');
$assert(($db['games']['discover']['launch_phase'] ?? '') === 'preparing',
    'Discovery polling must leave an unready preparation in preparing.');
$assert(($db['games']['discover']['preparation_ready_devices'] ?? []) === [],
    'game_state discovery without an explicit requested game id must not mark readiness.');
$assert(($db['games']['discover']['preparation_deadline_at'] ?? '') === $deadline,
    'Discovery polling must never extend the immutable preparation deadline.');
unset($user);

// A stale response may project an old explicit game but cannot mutate its lifecycle.
$stale = $makePreparingGame('stale', -2);
$db = [
    'users' => ['u1' => $makeUser('u1', 'newer')],
    'games' => ['stale' => $stale],
];
$before = $db['games']['stale'];
$user =& $db['users']['u1'];
$service->synchronizeCurrentGame($db, $user, 'stale', 'stale', 'sess-a', 'device-a');
$assert($db['games']['stale'] === $before,
    'A game that is no longer current for the participant must not be marked ready, advanced or settled.');
unset($user);

// Explicit current-game polling is the readiness intent. The second readiness
// creates one shared start and a full sixty-second first-turn window.
$game = $makePreparingGame('ready');
$game['preparation_ready_devices']['u2'] = [
    'device_hash' => hash('sha256', 'sess-b|device-b'),
    'ready_at' => now_iso(),
];
$db = [
    'users' => [
        'u1' => $makeUser('u1', 'ready'),
        'u2' => $makeUser('u2', 'ready'),
    ],
    'games' => ['ready' => $game],
];
$user =& $db['users']['u1'];
$service->synchronizeCurrentGame($db, $user, 'ready', 'ready', 'sess-a', 'device-a');
$readyGame = $db['games']['ready'];
$assert(($readyGame['launch_phase'] ?? '') === 'countdown',
    'Both readiness identities must advance preparation to one countdown.');
$assert(count($readyGame['preparation_ready_devices'] ?? []) === 2,
    'Readiness count must contain exactly one identity per participant.');
$assert(($readyGame['preparation_ready_devices']['u1']['device_hash'] ?? '') === hash('sha256', 'sess-a|device-a'),
    'Readiness must persist only the session+device hash.');
$assert(!str_contains(json_encode($readyGame['preparation_ready_devices'], JSON_UNESCAPED_SLASHES) ?: '', 'sess-a')
    && !str_contains(json_encode($readyGame['preparation_ready_devices'], JSON_UNESCAPED_SLASHES) ?: '', 'device-a'),
    'Raw session and device identifiers must not be stored in readiness state.');
$startsAt = strtotime((string)($readyGame['starts_at'] ?? '')) ?: 0;
$turnStartsAt = strtotime((string)($readyGame['turn_starts_at'] ?? '')) ?: 0;
$deadlineAt = strtotime((string)($readyGame['turn_deadline_at'] ?? '')) ?: 0;
$assert($startsAt > 0 && $turnStartsAt === $startsAt,
    'Countdown and first authoritative turn must share the same starts_at.');
$assert($deadlineAt - $turnStartsAt === MatchPreparationClockService::MOVE_TIMEOUT_SEC,
    'The first turn must receive the full sixty-second authoritative window.');
$assert((int)($readyGame['clock_revision'] ?? 0) === 1,
    'The first synchronized turn must create exactly revision one.');
unset($user);

// Elapsed preparation is advanced and settled by this one owner, exactly once.
$game = $makePreparingGame('timeout', -2);
$db = [
    'users' => [
        'u1' => $makeUser('u1', 'timeout'),
        'u2' => $makeUser('u2', 'timeout'),
    ],
    'games' => ['timeout' => $game],
    'transactions' => [],
    'system' => ['fees_match' => 77],
];
$user =& $db['users']['u1'];
$service->synchronizeCurrentGame($db, $user, 'timeout', '', 'sess-a', 'device-a');
$timeoutGame = $db['games']['timeout'];
$assert(($timeoutGame['status'] ?? '') === 'finished'
    && ($timeoutGame['launch_phase'] ?? '') === 'cancelled'
    && ($timeoutGame['finish_reason'] ?? '') === 'preparation_timeout',
    'Elapsed preparation must become the dedicated cancelled preparation result.');
$assert(($db['users']['u1']['balance_match'] ?? 0) === 100
    && ($db['users']['u2']['balance_match'] ?? 0) === 100,
    'Preparation timeout must restore each human stake exactly once.');
$assert(($db['users']['u1']['stats'] ?? []) === $stats && ($db['users']['u2']['stats'] ?? []) === $stats,
    'A match that never started must not alter game or weekly result statistics.');
$assert(($db['system']['fees_match'] ?? null) === 77,
    'Preparation cancellation must not charge commission.');
$finishRows = array_values(array_filter(
    $db['transactions'],
    static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish'
));
$assert(count($finishRows) === 1,
    'Preparation timeout must persist exactly one game_finish row.');
$transactionCount = count($db['transactions']);
$service->synchronizeCurrentGame($db, $user, 'timeout', 'timeout', 'sess-a', 'device-a');
$assert(count($db['transactions']) === $transactionCount
    && ($db['users']['u1']['balance_match'] ?? 0) === 100
    && ($db['users']['u2']['balance_match'] ?? 0) === 100,
    'Repeated stale timeout observation must not duplicate settlement or refund.');
unset($user);

// Active observations synchronize an externally changed turn through the same
// authoritative clock owner.
$now = time();
$db = [
    'users' => ['u1' => $makeUser('u1', 'observed')],
    'games' => [
        'observed' => [
            'id' => 'observed',
            'status' => 'active',
            'launch_phase' => 'active',
            'player_ids' => ['u1', 'u2'],
            'turn' => 'u2',
            'clock_turn' => 'u1',
            'clock_revision' => 4,
            'turn_started_at' => gmdate('c', $now - 3),
            'turn_starts_at' => gmdate('c', $now - 3),
            'turn_deadline_at' => gmdate('c', $now + 57),
        ],
    ],
];
$user =& $db['users']['u1'];
$service->synchronizeCurrentGame($db, $user, 'observed', '', 'sess-a', 'device-a');
$observed = $db['games']['observed'];
$assert(($observed['clock_turn'] ?? '') === 'u2' && (int)($observed['clock_revision'] ?? 0) === 5,
    'Observed active turn handoff must advance exactly one authoritative clock revision.');
$assert((strtotime((string)($observed['turn_starts_at'] ?? '')) ?: 0) > $now,
    'Observed turn handoff must create the bounded future turn-start guard window.');
$assert((strtotime((string)($observed['turn_deadline_at'] ?? '')) ?: 0)
        - (strtotime((string)($observed['turn_starts_at'] ?? '')) ?: 0)
        === MatchPreparationClockService::MOVE_TIMEOUT_SEC,
    'Observed handoff must grant the receiving turn the full sixty seconds.');
unset($user);

$serviceSource = file_get_contents(dirname(__DIR__) . '/services/MatchPreparationRuntimeService.php');
if (!is_string($serviceSource)) throw new RuntimeException('Runtime owner source unavailable.');
$assert(!str_contains($serviceSource, 'sleep(') && !str_contains($serviceSource, 'usleep('),
    'Preparation runtime ownership must not hide races with sleeps.');

fwrite(STDOUT, "PhaseBMatchPreparationRuntimeServiceTest: {$assertions} assertions passed\n");
