<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $n = 0;
        return $prefix . '_late_finish_' . (++$n);
    }
}

require_once dirname(__DIR__) . '/services/GameSettlementService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = new GameSettlementService(['commission_rate' => 0.10]);
$db = [
    'users' => [
        'u1' => [
            'id' => 'u1',
            'username' => 'One',
            'balance_match' => 90,
            'status' => 'playing',
            'current_game_id' => 'newer_game',
            'stats' => [],
        ],
    ],
    'transactions' => [],
    'system' => [],
];
$game = [
    'id' => 'cancelled_game',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'bank' => 20,
    'player_ids' => ['u1', 'bot_1'],
    'status' => 'active',
    'launch_phase' => 'preparation_timeout',
    'preparation_deadline_at' => gmdate('c', time() - 1),
    'winner_id' => null,
    'loser_id' => null,
    'finish_reason' => null,
    'payout_done' => false,
    'is_bot_game' => true,
    'bot_id' => 'bot_1',
    'created_at' => gmdate('c', time() - 20),
    'updated_at' => gmdate('c', time() - 1),
];

$service->cancelPreparation($db, $game);
$assert($db['users']['u1']['balance_match'] === 100, 'Preparation cancellation must restore the stake.');
$assert($db['users']['u1']['status'] === 'playing', 'Cancellation must preserve a newer playing status.');
$assert($db['users']['u1']['current_game_id'] === 'newer_game', 'Cancellation must preserve a newer current_game_id.');
$txCount = count($db['transactions']);

// A stale later caller may still attempt ordinary finish(). The payout_done
// idempotent path must never release the participant from a newer match.
$service->finish($db, $game, null, 'draw');

$assert($db['users']['u1']['balance_match'] === 100, 'Late finish must not refund the cancelled match twice.');
$assert(count($db['transactions']) === $txCount, 'Late finish must not append duplicate settlement rows.');
$assert($db['users']['u1']['status'] === 'playing', 'Late finish must not release a newer playing session.');
$assert($db['users']['u1']['current_game_id'] === 'newer_game', 'Late finish must not clear a newer current_game_id.');
$assert(($game['finish_reason'] ?? '') === 'preparation_timeout', 'Late finish must preserve preparation-timeout semantics.');

fwrite(STDOUT, "PhaseBPreparationCancellationLateFinishSafetyTest: {$assertions} assertions passed\n");
