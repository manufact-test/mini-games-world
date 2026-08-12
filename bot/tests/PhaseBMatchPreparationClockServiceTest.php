<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}

require_once dirname(__DIR__) . '/services/MatchPreparationClockService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$clock = new MatchPreparationClockService();
$game = [
    'id' => 'game_phase_b_test',
    'status' => 'active',
    'game_type' => 'tictactoe',
    'player_ids' => ['player_a', 'player_b'],
    'turn' => 'player_a',
    'created_at' => now_iso(),
    'updated_at' => now_iso(),
    'turn_started_at' => now_iso(),
];

$clock->initializeNewGame($game);
$assert(($game['launch_phase'] ?? '') === 'preparing', 'New games must enter preparing.');
$assert(empty($game['starts_at']), 'A new preparing game must not have starts_at yet.');
$assert(empty($game['turn_starts_at']), 'Turn clock must not start during preparation.');
$assert(empty($game['turn_deadline_at']), 'Turn deadline must not exist during preparation.');
$assert((int)($game['clock_revision'] ?? -1) === 0, 'Preparation must start at clock revision zero.');
$assert(strtotime((string)$game['turn_started_at']) >= time() + 8, 'Legacy timeout owner must be held beyond preparation.');

$blocked = false;
try {
    $clock->assertActionAllowed($game);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'синхронизирует');
}
$assert($blocked, 'Moves must be blocked while players prepare.');

$clock->markReady($game, 'player_a', 'raw-session-a', 'raw-device-a');
$assert(count($game['preparation_ready_devices'] ?? []) === 1, 'One ready player must not start countdown.');
$encoded = json_encode($game, JSON_UNESCAPED_UNICODE);
$assert(!str_contains((string)$encoded, 'raw-session-a'), 'Raw session IDs must never be persisted.');
$assert(!str_contains((string)$encoded, 'raw-device-a'), 'Raw device IDs must never be persisted.');
$clock->advance($game);
$assert(($game['launch_phase'] ?? '') === 'preparing', 'Countdown must wait for both players.');

$clock->markReady($game, 'player_b', 'raw-session-b', 'raw-device-b');
$clock->advance($game);
$assert(($game['launch_phase'] ?? '') === 'countdown', 'Both ready players must create one shared countdown.');
$assert((int)($game['clock_revision'] ?? 0) === 1, 'First turn must own clock revision one.');
$startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
$turnStartsAt = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
$deadlineAt = strtotime((string)($game['turn_deadline_at'] ?? '')) ?: 0;
$assert($startsAt > time(), 'Shared starts_at must be in the future.');
$assert($turnStartsAt === $startsAt, 'First turn must start at the exact shared match start.');
$assert($deadlineAt - $turnStartsAt === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'First player must receive the full move timeout.');

$game['starts_at'] = gmdate('c', time() - 1);
$game['turn_started_at'] = $game['starts_at'];
$game['turn_starts_at'] = $game['starts_at'];
$game['turn_deadline_at'] = gmdate('c', time() - 1 + MatchPreparationClockService::MOVE_TIMEOUT_SEC);
$activationRequestedAt = time();
$clock->advance($game);
$assert(($game['launch_phase'] ?? '') === 'active', 'Countdown must activate only after starts_at.');
$activationStart = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
$activationDeadline = strtotime((string)($game['turn_deadline_at'] ?? '')) ?: 0;
$assert(
    $activationStart >= $activationRequestedAt
        && $activationStart <= $activationRequestedAt + 1,
    'First playable turn must start when the server authoritatively activates the match.'
);
$assert(
    $activationStart > strtotime((string)$game['starts_at']),
    'A delayed activation request must not consume the first player clock during the countdown boundary gap.'
);
$assert(
    $activationDeadline - $activationStart === MatchPreparationClockService::MOVE_TIMEOUT_SEC,
    'Authoritative activation must restore the first player to the full move timeout.'
);
$activePublic = $clock->enrichPublicGame($game, []);
$assert(
    (int)($activePublic['time_left'] ?? 0) === MatchPreparationClockService::MOVE_TIMEOUT_SEC,
    'The first active response must expose the full timer.'
);
$clock->assertActionAllowed($game);

$previousTurn = 'player_a';
$game['turn'] = 'player_b';
$handoffRequestedAt = time();
$clock->synchronizeTurnHandoff($game, $previousTurn);
$handoffStart = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
$handoffDeadline = strtotime((string)($game['turn_deadline_at'] ?? '')) ?: 0;
$assert(
    $handoffStart >= $handoffRequestedAt
        && $handoffStart <= $handoffRequestedAt + 1,
    'Tic Tac Toe turn handoff must start immediately from the authoritative server timestamp.'
);
$assert(
    MatchPreparationClockService::TICTACTOE_TURN_HANDOFF_SEC === 0,
    'Tic Tac Toe must not retain the historical artificial handoff delay.'
);
$assert($handoffDeadline - $handoffStart === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'Receiving player must receive a fresh full timeout.');
$assert((int)($game['clock_revision'] ?? 0) === 2, 'Turn handoff must advance the authoritative clock revision once.');

$guardStart = time() + 3;
$game['turn_started_at'] = gmdate('c', $guardStart);
$game['turn_starts_at'] = gmdate('c', $guardStart);
$game['turn_deadline_at'] = gmdate('c', $guardStart + MatchPreparationClockService::MOVE_TIMEOUT_SEC);
$handoffBlocked = false;
try {
    $clock->assertActionAllowed($game);
} catch (RuntimeException $e) {
    $handoffBlocked = str_contains($e->getMessage(), 'не начался');
}
$assert($handoffBlocked, 'The receiving player must not act before authoritative turn_starts_at.');

$public = $clock->enrichPublicGame($game, ['time_left' => 17, 'move_timeout_sec' => 17]);
$assert(($public['launch_phase'] ?? '') === 'active', 'Public state must expose launch phase.');
$assert((int)($public['move_timeout_sec'] ?? 0) === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'Public state must use the authoritative timeout.');
$assert((int)($public['time_left'] ?? 0) === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'Future handoff must display the full timeout, not legacy elapsed time.');
$assert(isset($public['server_now_ms'], $public['turn_starts_at_ms'], $public['turn_deadline_ms']), 'Public state must expose one server time anchor and turn timestamps.');

$game['turn_started_at'] = gmdate('c', time() - 1);
$game['turn_starts_at'] = $game['turn_started_at'];
$game['turn_deadline_at'] = gmdate('c', time() - 1 + MatchPreparationClockService::MOVE_TIMEOUT_SEC);
$clock->assertActionAllowed($game);

$legacy = [
    'status' => 'active',
    'player_ids' => ['player_a', 'player_b'],
    'turn' => 'player_a',
    'turn_started_at' => gmdate('c', time() - 5),
];
$clock->normalizeExisting($legacy);
$assert(($legacy['launch_phase'] ?? '') === 'active', 'Existing accepted games must not be reset into preparation.');
$assert((int)($legacy['clock_revision'] ?? 0) === 1, 'Existing games must receive a stable clock anchor without restart.');

$botGame = [
    'status' => 'active',
    'player_ids' => ['player_a', 'bot_test'],
    'turn' => 'player_a',
    'is_bot_game' => true,
    'bot_id' => 'bot_test',
    'created_at' => now_iso(),
    'updated_at' => now_iso(),
    'turn_started_at' => now_iso(),
];
$clock->initializeNewGame($botGame);
$clock->markReady($botGame, 'player_a', 'human-session', 'human-device');
$assert(isset($botGame['preparation_ready_devices']['bot_test']), 'Bot readiness must be server-owned automatically.');
$clock->advance($botGame);
$assert(($botGame['launch_phase'] ?? '') === 'countdown', 'Bot match must use the same preparation state machine.');

fwrite(STDOUT, "PhaseBMatchPreparationClockServiceTest: {$assertions} assertions passed\n");
