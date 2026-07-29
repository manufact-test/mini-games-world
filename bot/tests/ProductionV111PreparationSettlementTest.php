<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $sequence = 0;
        $sequence++;
        return $prefix . '_test_' . $sequence;
    }
}

require_once dirname(__DIR__) . '/services/MatchPreparationClockService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$clock = new MatchPreparationClockService();
$game = [
    'id' => 'ready-game',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 3,
    'board' => '---------',
    'player_ids' => ['100', '200'],
    'player_names' => ['100' => 'One', '200' => 'Two'],
    'symbols' => ['100' => 'X', '200' => 'O'],
    'turn' => '100',
    'status' => 'active',
    'created_at' => gmdate('c', time() - 2),
    'updated_at' => now_iso(),
    'turn_started_at' => now_iso(),
];

$beforePreparation = time();
$clock->initializeLaunch($game);
$preparationDeadline = strtotime((string)$game['preparation_deadline_at']);
$assert($game['launch_phase'] === 'preparing', 'A recent active game must enter preparing before its clock starts.');
$assert($preparationDeadline !== false && $preparationDeadline >= $beforePreparation + 10 && $preparationDeadline <= time() + 11, 'Preparation must have one bounded ten-second deadline.');
$assert($game['turn_starts_at'] === null && $game['turn_deadline_at'] === null, 'No turn clock may exist while devices are still preparing.');

$clock->markReady($game, '100', 'device-session-one');
$assert(count($game['v111_ready_devices']) === 1, 'The first authenticated device must be recorded once.');
$assert($game['v111_ready_devices']['100']['device'] === hash('sha256', 'device-session-one'), 'Readiness must store only a session hash.');
$assert(!str_contains(json_encode($game['v111_ready_devices'], JSON_THROW_ON_ERROR), 'device-session-one'), 'Raw device session IDs must never be persisted.');
$clock->startCountdownIfReady($game);
$assert($game['launch_phase'] === 'preparing', 'Countdown must not start before the second player is ready.');

$beforeCountdown = time();
$clock->markReady($game, '200', 'device-session-two');
$clock->startCountdownIfReady($game);
$startsAt = strtotime((string)$game['starts_at']);
$firstDeadline = strtotime((string)$game['turn_deadline_at']);
$assert($game['launch_phase'] === 'countdown' && count($game['v111_ready_devices']) === 2, 'Both ready devices must start one shared countdown.');
$assert($startsAt !== false && $startsAt >= $beforeCountdown + 3 && $startsAt <= time() + 4, 'The shared match start must be scheduled three seconds ahead.');
$assert($firstDeadline !== false && $firstDeadline - $startsAt === 60, 'The first deadline must be exactly 60 seconds after shared start.');
$assert($game['turn_started_at'] === $game['turn_starts_at'] && $game['turn_starts_at'] === $game['starts_at'], 'Both clients must receive the same first-turn start timestamp.');
$assert($game['v111_clock_turn'] === '100' && $game['v111_clock_revision'] === 1, 'The first synchronized clock must have one revision and current turn owner.');

$countdownBlocked = false;
try {
    $clock->assertLaunchReady($game);
} catch (RuntimeException $error) {
    $countdownBlocked = str_contains($error->getMessage(), 'обратного отсчёта');
}
$assert($countdownBlocked, 'Game actions must remain blocked during the countdown.');
$game['starts_at'] = gmdate('c', time() - 1);
$clock->activateIfDue($game);
$clock->assertLaunchReady($game);
$assert($game['launch_phase'] === 'active', 'The game becomes actionable only after the common start time.');

$beforeHandoff = time();
$game['turn'] = '200';
$clock->synchronizeTurnHandoff($game, '100');
$handoffStart = strtotime((string)$game['turn_starts_at']);
$handoffDeadline = strtotime((string)$game['turn_deadline_at']);
$assert($handoffStart !== false && $handoffStart >= $beforeHandoff + 1 && $handoffStart <= time() + 2, 'A changed turn must start slightly in the future for both clients.');
$assert($handoffDeadline !== false && $handoffDeadline - $handoffStart === 60, 'Every next turn must receive a fresh exact 60-second deadline.');
$assert($game['v111_clock_turn'] === '200' && $game['v111_clock_revision'] === 2, 'Turn handoff must advance the clock revision exactly once.');
$sameStart = $game['turn_starts_at'];
$clock->synchronizeTurnHandoff($game, '200');
$assert($game['turn_starts_at'] === $sameStart && $game['v111_clock_revision'] === 2, 'Repeated same-turn snapshots must not restart the clock.');

$projection = $clock->enrichPublicGame($game, ['id' => 'ready-game', 'time_left' => 57, 'move_timeout_sec' => 60]);
$assert(isset($projection['server_now_ms'], $projection['turn_starts_at_ms'], $projection['turn_deadline_ms']), 'Public state must carry one server time anchor and exact turn timestamps.');
$assert($projection['time_left'] === 60, 'A future synchronized handoff must override the legacy 57-second projection with 60.');

$baseGame = static function (string $id, string $phase, string $deadline): array {
    return [
        'id' => $id,
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'bank' => 20,
        'board_size' => 3,
        'board' => '---------',
        'player_ids' => ['100', '200'],
        'turn' => '100',
        'status' => 'active',
        'launch_phase' => $phase,
        'preparation_deadline_at' => $deadline,
        'payout_done' => false,
        'created_at' => gmdate('c', time() - 4),
        'updated_at' => now_iso(),
        'turn_started_at' => gmdate('c', time() + 20),
    ];
};
$earlyDb = [
    'users' => [
        '100' => ['id' => '100', 'username' => 'one', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'early'],
        '200' => ['id' => '200', 'username' => 'two', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'early'],
    ],
    'games' => ['early' => $baseGame('early', 'preparing', gmdate('c', time() + 8))],
    'transactions' => [],
];
$earlyThrown = false;
try {
    $clock->settlePreparationTimeout($earlyDb, $earlyDb['games']['early']);
} catch (RuntimeException $error) {
    $earlyThrown = str_contains($error->getMessage(), 'ещё продолжается');
}
$assert($earlyThrown, 'Preparation cannot settle before its deadline.');
$assert($earlyDb['users']['100']['balance_match'] === 90 && $earlyDb['users']['200']['balance_match'] === 90, 'Early settlement must not alter balances.');

$db = [
    'users' => [
        '100' => ['id' => '100', 'username' => 'one', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'expired'],
        '200' => ['id' => '200', 'username' => 'two', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'expired'],
    ],
    'games' => ['expired' => $baseGame('expired', 'preparation_timeout', gmdate('c', time() - 1))],
    'transactions' => [],
];
$settled = $clock->settlePreparationTimeout($db, $db['games']['expired']);
$assert($settled['status'] === 'finished' && $settled['launch_phase'] === 'cancelled', 'Expired preparation must finish as a cancelled match.');
$assert($settled['finish_reason'] === 'preparation_timeout' && !empty($settled['v111_preparation_refund_done']), 'Timeout settlement must record its idempotency marker.');
$assert($db['users']['100']['balance_match'] === 100 && $db['users']['200']['balance_match'] === 100, 'Both human stakes must be restored exactly once.');
$assert($db['users']['100']['status'] === 'idle' && $db['users']['200']['status'] === 'idle', 'Both players must be released after preparation timeout.');
$refundRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['category'] ?? '') === 'game_preparation_refund'));
$finishRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish'));
$assert(count($refundRows) === 2 && count($finishRows) === 1, 'Settlement must create two balance refunds and one finish record.');
$transactionCount = count($db['transactions']);
$clock->settlePreparationTimeout($db, $db['games']['expired']);
$assert($db['users']['100']['balance_match'] === 100 && $db['users']['200']['balance_match'] === 100, 'Repeated settlement must not duplicate balance refunds.');
$assert(count($db['transactions']) === $transactionCount, 'Repeated settlement must not append duplicate transactions.');

fwrite(STDOUT, "ProductionV111PreparationSettlementTest: {$assertions} assertions passed\n");
