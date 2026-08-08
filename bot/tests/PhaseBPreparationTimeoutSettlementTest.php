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
        return $prefix . '_test_' . $n;
    }
}

require_once dirname(__DIR__) . '/services/GameSettlementService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = new GameSettlementService(['commission_rate' => 0.10]);
$stats = [
    'games_played' => 7,
    'match_games_this_week' => 2,
    'wins' => 3,
    'losses' => 2,
    'draws' => 2,
    'bot_games_played' => 1,
    'bot_wins' => 1,
    'bot_losses' => 0,
    'bot_draws' => 0,
    'bot_win_streak' => 1,
];

$makeGame = static function (array $players, bool $bot = false): array {
    return [
        'id' => 'game_timeout',
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 10,
        'bank' => 20,
        'player_ids' => $players,
        'status' => 'active',
        'launch_phase' => 'preparation_timeout',
        'preparation_deadline_at' => gmdate('c', time() - 1),
        'winner_id' => null,
        'loser_id' => null,
        'finish_reason' => null,
        'payout_done' => false,
        'is_bot_game' => $bot,
        'bot_id' => $bot ? 'bot_1' : null,
        'bot_difficulty' => $bot ? 'medium' : null,
        'created_at' => gmdate('c', time() - 20),
        'updated_at' => gmdate('c', time() - 1),
    ];
};

$db = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'username' => 'One',
            'balance_match' => 90,
            'status' => 'playing',
            'current_game_id' => 'game_timeout',
            'stats' => $stats,
        ],
        'u2' => [
            'id' => 'u2',
            'username' => 'Two',
            'balance_match' => 90,
            'status' => 'playing',
            'current_game_id' => 'game_timeout',
            'stats' => $stats,
        ],
    ],
    'transactions' => [],
    'system' => ['fees_match' => 123],
];
$game = $makeGame(['u1', 'u2']);
$service->cancelPreparation($db, $game);

$assert($db['users']['u1']['balance_match'] === 100 && $db['users']['u2']['balance_match'] === 100, 'Each human stake must be restored exactly once.');
$assert($db['users']['u1']['status'] === 'idle' && $db['users']['u2']['status'] === 'idle', 'Cancelled match must release current players.');
$assert($db['users']['u1']['current_game_id'] === null && $db['users']['u2']['current_game_id'] === null, 'Cancelled match must clear matching current_game_id.');
$assert($db['users']['u1']['stats'] === $stats && $db['users']['u2']['stats'] === $stats, 'Preparation cancellation must not count as a played match or alter result stats.');
$assert(($db['system']['fees_match'] ?? null) === 123, 'Preparation cancellation must not charge commission.');
$assert(($game['status'] ?? '') === 'finished' && ($game['launch_phase'] ?? '') === 'cancelled', 'Timed-out preparation must end as cancelled, not active/draw.');
$assert(($game['finish_reason'] ?? '') === 'preparation_timeout', 'Cancellation must retain preparation_timeout reason.');
$assert(!empty($game['preparation_cancelled_at']) && !empty($game['payout_done']), 'Cancellation must persist an idempotent settlement marker.');
$assert(($game['commission'] ?? -1) === 0 && ($game['payout'] ?? -1) === 0, 'Cancellation must not create winnings or commission.');

$refundRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'balance_change'));
$finishRows = array_values(array_filter($db['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'game_finish'));
$assert(count($refundRows) === 2, 'Human-vs-human cancellation must write one refund row per human.');
$assert(count($finishRows) === 1, 'Cancellation must write exactly one game_finish row.');
foreach ($refundRows as $row) {
    $assert(($row['category'] ?? '') === 'game_refund', 'Preparation refund must use the existing safe refund category.');
    $assert(($row['amount'] ?? 0) === 10, 'Preparation refund amount must equal the stake exactly.');
    $assert(($row['finish_reason'] ?? '') === 'preparation_timeout' && ($row['match_started'] ?? true) === false, 'Refund row must explicitly say the match never started.');
}
$assert(($finishRows[0]['finish_reason'] ?? '') === 'preparation_timeout' && ($finishRows[0]['match_started'] ?? true) === false, 'Finished row must distinguish cancellation from a draw.');

$balancesBeforeRepeat = [$db['users']['u1']['balance_match'], $db['users']['u2']['balance_match']];
$txCountBeforeRepeat = count($db['transactions']);
$service->cancelPreparation($db, $game);
$assert([$db['users']['u1']['balance_match'], $db['users']['u2']['balance_match']] === $balancesBeforeRepeat, 'Repeated cancellation must never refund twice.');
$assert(count($db['transactions']) === $txCountBeforeRepeat, 'Repeated cancellation must never duplicate transaction rows.');

$botDb = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'username' => 'One',
            'balance_match' => 90,
            'status' => 'playing',
            'current_game_id' => 'game_timeout',
            'stats' => $stats,
        ],
    ],
    'transactions' => [],
    'system' => [],
];
$botGame = $makeGame(['u1', 'bot_1'], true);
$service->cancelPreparation($botDb, $botGame);
$botRefundRows = array_values(array_filter($botDb['transactions'], static fn(array $tx): bool => ($tx['type'] ?? '') === 'balance_change'));
$assert($botDb['users']['u1']['balance_match'] === 100, 'Human stake must be restored in a bot match cancellation.');
$assert(count($botRefundRows) === 1 && ($botRefundRows[0]['user_id'] ?? '') === 'u1', 'Bot identity must never receive a refund row.');
$assert(count($botDb['transactions']) === 2, 'Bot cancellation must contain one human refund and one finished row only.');
$assert($botDb['users']['u1']['stats'] === $stats, 'Bot preparation cancellation must not alter bot/game stats.');

$mismatchDb = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'username' => 'One',
            'balance_match' => 90,
            'status' => 'playing',
            'current_game_id' => 'newer_game',
            'stats' => $stats,
        ],
    ],
    'transactions' => [],
    'system' => [],
];
$mismatchGame = $makeGame(['u1', 'bot_1'], true);
$service->cancelPreparation($mismatchDb, $mismatchGame);
$assert($mismatchDb['users']['u1']['balance_match'] === 100, 'Stake restoration must not depend on current session pointer.');
$assert($mismatchDb['users']['u1']['status'] === 'playing' && $mismatchDb['users']['u1']['current_game_id'] === 'newer_game', 'Cancellation must not release a player from a newer game.');

$earlyDb = [
    'users' => [
        'u1' => ['id' => 'u1', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'game_timeout', 'stats' => $stats],
    ],
    'transactions' => [],
];
$earlyGame = $makeGame(['u1', 'bot_1'], true);
$earlyGame['launch_phase'] = 'preparing';
$service->cancelPreparation($earlyDb, $earlyGame);
$assert($earlyDb['users']['u1']['balance_match'] === 90 && count($earlyDb['transactions']) === 0, 'Preparing phase must not settle before the state machine declares timeout.');

$futureDb = [
    'users' => [
        'u1' => ['id' => 'u1', 'balance_match' => 90, 'status' => 'playing', 'current_game_id' => 'game_timeout', 'stats' => $stats],
    ],
    'transactions' => [],
];
$futureGame = $makeGame(['u1', 'bot_1'], true);
$futureGame['preparation_deadline_at'] = gmdate('c', time() + 30);
$service->cancelPreparation($futureDb, $futureGame);
$assert($futureDb['users']['u1']['balance_match'] === 90 && count($futureDb['transactions']) === 0, 'Future preparation deadline must never be settled early even if phase is malformed.');

fwrite(STDOUT, "PhaseBPreparationTimeoutSettlementTest: {$assertions} assertions passed\n");
