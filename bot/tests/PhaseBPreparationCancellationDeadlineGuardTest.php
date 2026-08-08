<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix = 'id'): string {
        static $n = 0;
        return $prefix . '_deadline_guard_' . (++$n);
    }
}

require_once dirname(__DIR__) . '/services/GameSettlementService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = new GameSettlementService(['commission_rate' => 0.10]);
$baseUser = [
    'id' => 'u1',
    'username' => 'One',
    'balance_match' => 90,
    'status' => 'playing',
    'current_game_id' => 'game_timeout',
    'stats' => [],
];
$baseGame = [
    'id' => 'game_timeout',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'bank' => 20,
    'player_ids' => ['u1', 'bot_1'],
    'status' => 'active',
    'launch_phase' => 'preparation_timeout',
    'winner_id' => null,
    'loser_id' => null,
    'finish_reason' => null,
    'payout_done' => false,
    'is_bot_game' => true,
    'bot_id' => 'bot_1',
    'created_at' => gmdate('c', time() - 20),
    'updated_at' => gmdate('c', time() - 1),
];

$missingDb = ['users' => ['u1' => $baseUser], 'transactions' => [], 'system' => []];
$missingGame = $baseGame;
unset($missingGame['preparation_deadline_at']);
$service->cancelPreparation($missingDb, $missingGame);
$assert($missingDb['users']['u1']['balance_match'] === 90, 'Missing preparation deadline must never refund a stake.');
$assert(count($missingDb['transactions']) === 0, 'Missing preparation deadline must never create settlement transactions.');
$assert(($missingGame['launch_phase'] ?? '') === 'preparation_timeout', 'Missing deadline must leave timeout state unsettled for diagnosis.');
$assert(empty($missingGame['preparation_cancelled_at']), 'Missing deadline must not write an idempotent cancellation marker.');

$invalidDb = ['users' => ['u1' => $baseUser], 'transactions' => [], 'system' => []];
$invalidGame = $baseGame;
$invalidGame['preparation_deadline_at'] = 'not-a-date';
$service->cancelPreparation($invalidDb, $invalidGame);
$assert($invalidDb['users']['u1']['balance_match'] === 90, 'Invalid preparation deadline must never refund a stake.');
$assert(count($invalidDb['transactions']) === 0, 'Invalid preparation deadline must never create settlement transactions.');

$elapsedDb = ['users' => ['u1' => $baseUser], 'transactions' => [], 'system' => []];
$elapsedGame = $baseGame;
$elapsedGame['preparation_deadline_at'] = gmdate('c', time() - 1);
$service->cancelPreparation($elapsedDb, $elapsedGame);
$assert($elapsedDb['users']['u1']['balance_match'] === 100, 'A real elapsed deadline must still restore the stake.');
$assert(count($elapsedDb['transactions']) === 2, 'Elapsed bot-match cancellation must create one human refund and one finish row.');
$assert(($elapsedGame['launch_phase'] ?? '') === 'cancelled', 'Elapsed deadline must settle to cancelled.');

fwrite(STDOUT, "PhaseBPreparationCancellationDeadlineGuardTest: {$assertions} assertions passed\n");
