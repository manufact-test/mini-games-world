<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/services/HistoryService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$service = (new ReflectionClass(HistoryService::class))->newInstanceWithoutConstructor();
$userA = 'stg_test_player_a';
$userB = 'stg_test_player_b';
$newestId = 'game_000000_newest';

$game = static function (string $id, string $finishedAt) use ($userA, $userB): array {
    return [
        'id' => $id,
        'game_type' => 'tictactoe',
        'room' => 'match',
        'bet' => 100,
        'board_size' => 3,
        'board' => 'XXXOO----',
        'player_ids' => [$userA, $userB],
        'player_names' => [$userA => 'A', $userB => 'B'],
        'symbols' => [$userA => 'X', $userB => 'O'],
        'status' => 'finished',
        'winner_id' => $userA,
        'loser_id' => $userB,
        'finish_reason' => 'normal_win',
        'payout' => 180,
        'commission' => 20,
        'created_at' => $finishedAt,
        'updated_at' => $finishedAt,
        'finished_at' => $finishedAt,
    ];
};

$games = [];
for ($i = 0; $i < 13; $i++) {
    $id = sprintf('game_f%011d', $i);
    $games[$id] = $game($id, sprintf('2026-08-18T%02d:00:00+00:00', 10 + ($i % 10)));
}
$games[$newestId] = $game($newestId, '2026-08-19T10:25:30+00:00');

// DatabasePrimaryStateStorageAdapter canonicalizes associative maps with ksort().
// Reproduce that persisted order explicitly: the newest game ID sorts first, so
// the old array_reverse($db['games']) implementation pushed it behind 13 stale games.
ksort($games, SORT_STRING);
$legacyFirstTwelve = array_slice(array_values(array_reverse($games)), 0, 12);
$legacyIds = array_map(static fn(array $item): string => (string)$item['id'], $legacyFirstTwelve);
$assert(!in_array($newestId, $legacyIds, true), 'Fixture must reproduce the old reverse-key-order omission.');

$db = [
    'games' => $games,
    'transactions' => [
        [
            'id' => 'tx_a_entry',
            'type' => 'balance_change',
            'category' => 'game_entry',
            'user_id' => $userA,
            'game_id' => $newestId,
            'amount' => -100,
            'balance_after' => 0,
        ],
        [
            'id' => 'tx_b_entry',
            'type' => 'balance_change',
            'category' => 'game_entry',
            'user_id' => $userB,
            'game_id' => $newestId,
            'amount' => -100,
            'balance_after' => 0,
        ],
        [
            'id' => 'tx_a_win',
            'type' => 'balance_change',
            'category' => 'game_win',
            'user_id' => $userA,
            'game_id' => $newestId,
            'amount' => 180,
            'balance_after' => 180,
        ],
    ],
];

$historyA = $service->matchHistory($db, $userA, 12);
$historyB = $service->matchHistory($db, $userB, 12);

$assert(count($historyA) === 12, 'History limit must remain 12.');
$assert((string)($historyA[0]['id'] ?? '') === $newestId, 'Newest match must be first regardless of associative key order.');
$assert((int)($historyA[0]['economy']['ledger_delta'] ?? 0) === 80, 'Winner newest-match ledger delta must be +80.');
$assert((int)($historyA[0]['economy']['new_balance'] ?? -1) === 180, 'Winner newest-match balance must be 180.');
$assert((string)($historyB[0]['id'] ?? '') === $newestId, 'Newest match must be first for the loser too.');
$assert((int)($historyB[0]['economy']['ledger_delta'] ?? 0) === -100, 'Loser newest-match ledger delta must be -100.');
$assert((int)($historyB[0]['economy']['new_balance'] ?? -1) === 0, 'Loser newest-match balance must be 0.');

$source = file_get_contents($root . '/services/HistoryService.php');
$assert(is_string($source) && !str_contains($source, "foreach (array_reverse(\$db['games'] ?? []) as \$game)"), 'History must not treat associative game-key order as chronology.');
$assert(is_string($source) && str_contains($source, "['finished_at', 'updated_at', 'created_at']"), 'History chronology must use explicit match timestamps.');

fwrite(STDOUT, "Mvp17_5HistoryChronologyRegressionTest: {$assertions} assertions passed\n");
