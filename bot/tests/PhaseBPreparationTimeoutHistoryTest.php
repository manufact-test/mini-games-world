<?php
declare(strict_types=1);

if (!class_exists('UserService')) {
    final class UserService {}
}

require_once dirname(__DIR__) . '/services/HistoryService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$history = new HistoryService([], new UserService());

$baseGame = [
    'id' => 'game_timeout',
    'game_type' => 'tictactoe',
    'room' => 'match',
    'bet' => 10,
    'board_size' => 3,
    'player_ids' => ['u1', 'u2'],
    'player_names' => ['u1' => 'One', 'u2' => 'Two'],
    'status' => 'finished',
    'winner_id' => null,
    'loser_id' => null,
    'payout' => 0,
    'commission' => 0,
    'created_at' => '2026-08-08T10:00:00Z',
    'finished_at' => '2026-08-08T10:00:10Z',
];

$cancelledGame = $baseGame + ['finish_reason' => 'preparation_timeout'];
$cancelledGame['finish_reason'] = 'preparation_timeout';
$cancelledDb = [
    'games' => ['game_timeout' => $cancelledGame],
    'transactions' => [
        [
            'id' => 'tx_refund',
            'type' => 'balance_change',
            'category' => 'game_refund',
            'user_id' => 'u1',
            'room' => 'match',
            'amount' => 10,
            'game_id' => 'game_timeout',
            'finish_reason' => 'preparation_timeout',
            'match_started' => false,
            'created_at' => '2026-08-08T10:00:10Z',
        ],
        [
            'id' => 'tx_finish',
            'type' => 'game_finish',
            'game_id' => 'game_timeout',
            'room' => 'match',
            'winner_id' => null,
            'finish_reason' => 'preparation_timeout',
            'payout' => 0,
            'match_started' => false,
            'created_at' => '2026-08-08T10:00:10Z',
        ],
    ],
];

$cancelled = $history->formatHistory($cancelledDb, 'u1');
$assert(count($cancelled['operations']) === 1, 'Preparation refund balance_change and game_finish must dedupe into one visible refund operation.');
$assert(($cancelled['operations'][0]['title'] ?? '') === 'Возврат: соперник не подключился', 'Preparation timeout refund must not be titled as a draw.');
$assert(($cancelled['operations'][0]['description'] ?? '') === 'Match-комната · Крестики-нолики · соперник не подключился', 'Preparation timeout refund description must explain why the match did not start.');
$assert(($cancelled['operations'][0]['amount'] ?? 0) === 10, 'Preparation timeout history must show the exact returned stake.');
$assert(($cancelled['matches'][0]['result'] ?? '') === 'Матч не начался', 'Match history must distinguish a preparation timeout from a played draw.');
$assert(($cancelled['matches'][0]['tone'] ?? '') === 'zero', 'Cancelled preparation must remain neutral in match history.');
$assert(!str_contains(mb_strtolower((string)$cancelled['operations'][0]['title']), 'ничь'), 'Preparation timeout title must contain no draw wording.');
$assert(!str_contains(mb_strtolower((string)$cancelled['operations'][0]['description']), 'ничь'), 'Preparation timeout description must contain no draw wording.');

$drawGame = $baseGame;
$drawGame['id'] = 'game_draw';
$drawGame['finish_reason'] = 'draw';
$drawGame['payout'] = 10;
$drawDb = [
    'games' => ['game_draw' => $drawGame],
    'transactions' => [
        [
            'id' => 'tx_draw_refund',
            'type' => 'balance_change',
            'category' => 'game_refund',
            'user_id' => 'u1',
            'room' => 'match',
            'amount' => 10,
            'game_id' => 'game_draw',
            'finish_reason' => 'draw',
            'created_at' => '2026-08-08T10:01:00Z',
        ],
        [
            'id' => 'tx_draw_finish',
            'type' => 'game_finish',
            'game_id' => 'game_draw',
            'room' => 'match',
            'winner_id' => null,
            'finish_reason' => 'draw',
            'payout' => 0,
            'created_at' => '2026-08-08T10:01:00Z',
        ],
    ],
];

$draw = $history->formatHistory($drawDb, 'u1');
$assert(count($draw['operations']) === 1, 'Normal draw refund rows must continue deduping as before.');
$assert(($draw['operations'][0]['title'] ?? '') === 'Возврат при ничьей', 'Normal draw refund title must remain unchanged.');
$assert(($draw['operations'][0]['description'] ?? '') === 'Match-комната · Крестики-нолики · ничья', 'Normal draw refund description must remain unchanged.');
$assert(($draw['matches'][0]['result'] ?? '') === 'Ничья', 'Normal draw match result must remain unchanged.');

fwrite(STDOUT, "PhaseBPreparationTimeoutHistoryTest: {$assertions} assertions passed\n");
