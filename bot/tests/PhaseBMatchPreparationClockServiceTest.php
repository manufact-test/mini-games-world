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
$assert((int)($game['clock_revision'] ?? 0) === 0, 'Tic-Tac-Toe first-turn clock must not start behind the launch overlay.');
$startsAt = strtotime((string)($game['starts_at'] ?? '')) ?: 0;
$assert($startsAt > time(), 'Shared starts_at must be in the future.');
$assert(empty($game['turn_starts_at']), 'Tic-Tac-Toe turn start must remain unset during countdown.');
$assert(empty($game['turn_deadline_at']), 'Tic-Tac-Toe turn deadline must remain unset during countdown.');

$game['starts_at'] = gmdate('c', time() - 1);
$game['starts_epoch_ms'] = (time() - 1) * 1000;
$clock->advance($game);
$assert(($game['launch_phase'] ?? '') === 'active', 'Countdown must activate only after starts_at.');
$assert((int)($game['clock_revision'] ?? 0) === 1, 'First playable turn must own clock revision one after countdown.');
$turnStartsAt = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
$deadlineAt = strtotime((string)($game['turn_deadline_at'] ?? '')) ?: 0;
$assert($turnStartsAt > 0, 'First playable turn must receive an authoritative start after countdown.');
$assert($deadlineAt - $turnStartsAt === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'First player must receive the full move timeout after countdown.');
$clock->assertActionAllowed($game);

$previousTurn = 'player_a';
$game['turn'] = 'player_b';
$handoffRequestedAt = time();
$clock->synchronizeTurnHandoff($game, $previousTurn);
$handoffStart = strtotime((string)($game['turn_starts_at'] ?? '')) ?: 0;
$handoffDeadline = strtotime((string)($game['turn_deadline_at'] ?? '')) ?: 0;
$assert(
    $handoffStart >= $handoffRequestedAt && $handoffStart <= $handoffRequestedAt + 1,
    'Tic-Tac-Toe handoff must start immediately from the authoritative server commit without an artificial pause.'
);
$assert($handoffDeadline - $handoffStart === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'Receiving player must receive a fresh full timeout.');
$assert((int)($game['clock_revision'] ?? 0) === 2, 'Turn handoff must advance the authoritative clock revision once.');

$guardStart = time() + 3;
$game['turn_started_at'] = gmdate('c', $guardStart);
$game['turn_starts_at'] = gmdate('c', $guardStart);
$game['turn_starts_epoch_ms'] = $guardStart * 1000;
$game['turn_deadline_at'] = gmdate('c', $guardStart + MatchPreparationClockService::MOVE_TIMEOUT_SEC);
$game['turn_deadline_epoch_ms'] = ($guardStart + MatchPreparationClockService::MOVE_TIMEOUT_SEC) * 1000;
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

$pastTurnStart = time() - 1;
$game['turn_started_at'] = gmdate('c', $pastTurnStart);
$game['turn_starts_at'] = $game['turn_started_at'];
$game['turn_starts_epoch_ms'] = $pastTurnStart * 1000;
$game['turn_deadline_at'] = gmdate('c', $pastTurnStart + MatchPreparationClockService::MOVE_TIMEOUT_SEC);
$game['turn_deadline_epoch_ms'] = ($pastTurnStart + MatchPreparationClockService::MOVE_TIMEOUT_SEC) * 1000;
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

$tournamentGame = [
    'id' => 'game_tournament_phase_b_test',
    'status' => 'active',
    'game_type' => 'tictactoe',
    'match_source' => 'tournament',
    'launch_countdown_sec' => 10,
    'player_ids' => ['tour_a', 'tour_b'],
    'turn' => 'tour_a',
    'created_at' => now_iso(),
    'updated_at' => now_iso(),
    'turn_started_at' => now_iso(),
];
$clock->initializeNewGame($tournamentGame);
$assert(($tournamentGame['launch_phase'] ?? '') === 'preparing', 'Tournament game creation must wait for both clients to adopt the shared game.');
$clock->markReady($tournamentGame, 'tour_a', 'tour-session-a', 'tour-device-a');
$clock->advance($tournamentGame);
$assert(($tournamentGame['launch_phase'] ?? '') === 'preparing', 'First tournament client must not start the common countdown alone.');
$clock->markReady($tournamentGame, 'tour_b', 'tour-session-b', 'tour-device-b');
$clock->advance($tournamentGame);
$assert(($tournamentGame['launch_phase'] ?? '') === 'countdown', 'Both tournament clients must start one shared countdown only after adoption.');
$assert(count($tournamentGame['preparation_ready_devices'] ?? []) === 2, 'Tournament Phase-B adoption must contain exactly both real paired players.');
$tournamentStartsAtMs = (int)($tournamentGame['starts_epoch_ms'] ?? 0);
$nowMs = (int)round(microtime(true) * 1000);
$assert($tournamentStartsAtMs >= $nowMs + 9000, 'Tournament countdown must remain approximately ten seconds, not ordinary three seconds.');
$assert($tournamentStartsAtMs <= $nowMs + 11000, 'Tournament countdown must not exceed the bounded ten-second launch window.');
$tournamentPublic = $clock->enrichPublicGame($tournamentGame, []);
$assert((int)($tournamentPublic['launch_countdown_sec'] ?? 0) === 10, 'Public tournament game must expose the authoritative ten-second countdown.');
$assert((int)($tournamentPublic['time_left'] ?? 0) === MatchPreparationClockService::MOVE_TIMEOUT_SEC, 'Move timer must remain full while tournament countdown is running.');
$tournamentBlocked = false;
try {
    $clock->assertActionAllowed($tournamentGame);
} catch (RuntimeException $e) {
    $tournamentBlocked = str_contains($e->getMessage(), 'обратного отсчёта');
}
$assert($tournamentBlocked, 'Tournament field must stay locked until the countdown ends.');

// Corrective v5: client adoption, the 10-second launch countdown and first turn are separate clocks.
// Advance the same tournament game across T0 without waiting in real time and prove
// the first playable public frame receives a fresh full 60-second deadline.
$tournamentGame['starts_epoch_ms'] = (int)round(microtime(true) * 1000) - 1;
$tournamentGame['starts_at'] = gmdate('c', time() - 1);
$clock->advance($tournamentGame);
$assert(($tournamentGame['launch_phase'] ?? '') === 'active', 'Tournament countdown must promote to active at the shared server anchor.');
$tournamentActivePublic = $clock->enrichPublicGame($tournamentGame, []);
$assert((int)($tournamentActivePublic['time_left'] ?? 0) === MatchPreparationClockService::MOVE_TIMEOUT_SEC,
    'First playable tournament frame must receive the full 60-second turn.');
$turnStartMs = (int)($tournamentActivePublic['turn_starts_at_ms'] ?? 0);
$turnDeadlineMs = (int)($tournamentActivePublic['turn_deadline_ms'] ?? 0);
$assert($turnStartMs > 0 && $turnDeadlineMs - $turnStartMs === MatchPreparationClockService::MOVE_TIMEOUT_SEC * 1000,
    'Fresh active turn deadline must be exactly 60 seconds after its own start, independent of launch countdown.');

$ordinaryCountdown = [
    'id' => 'game_ordinary_countdown_regression',
    'status' => 'active',
    'game_type' => 'tictactoe',
    'player_ids' => ['ordinary_a', 'ordinary_b'],
    'turn' => 'ordinary_a',
    'created_at' => now_iso(),
    'updated_at' => now_iso(),
    'turn_started_at' => now_iso(),
];
$clock->initializeNewGame($ordinaryCountdown);
$clock->markReady($ordinaryCountdown, 'ordinary_a', 's-a', 'd-a');
$clock->markReady($ordinaryCountdown, 'ordinary_b', 's-b', 'd-b');
$clock->advance($ordinaryCountdown);
$ordinaryPublic = $clock->enrichPublicGame($ordinaryCountdown, []);
$assert((int)($ordinaryPublic['launch_countdown_sec'] ?? 0) === MatchPreparationClockService::COUNTDOWN_SEC, 'Ordinary matches must preserve the accepted three-second countdown.');

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
