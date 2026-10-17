<?php
declare(strict_types=1);

// A passive Profile/History read must never initiate full JSON->DB realtime,
// economy, weekly or rating catch-up while two real human players are taking
// turns. HistoryService already merges JSON-authoritative terminal games and
// transaction-based economy with the independent DB display read model.
$root = dirname(__DIR__);
$read = static fn(string $file): string => (string)file_get_contents($root . '/' . $file);
$asserts = 0;
$assert = static function (bool $ok, string $message) use (&$asserts): void {
    if (!$ok) throw new RuntimeException($message);
    $asserts++;
};

foreach ([
    'realtime/RealtimeRuntimeBridge.php',
    'ledger/EconomyRuntimeBridge.php',
    'weekly/WeeklyBonusRuntimeBridge.php',
    'ratings/PerGameRatingRuntimeBridge.php',
] as $file) {
    $src = $read($file);
    $assert(str_contains($src, "'profile',") && str_contains($src, "'history',"),
        "Passive Profile and History must be excluded from heavyweight projector: {$file}");
    $assert(str_contains($src, "'game_action',") && str_contains($src, "'game_state',"),
        "Gameplay must retain prior projection-skip behavior: {$file}");
}

$history = $read('services/HistoryService.php');
$api = $read('api.php');
$assert(str_contains($history, '$repository->read($userId, $limit)')
    && str_contains($history, '$this->mergeCurrentMatchPresentation($history, $db, $userId)'),
    'History continues to read the DB projection while preserving current JSON match overlay.');
$assert(str_contains($history, "['game_entry', 'game_win', 'game_refund']")
    && str_contains($history, "'ledger_delta' => $ledgerDelta"),
    'History economy remains based on actual JSON balance-change entries, never synthetic credits.');
$assert(str_contains($api, "'__history_snapshot' => [")
    && str_contains($api, "$result['history'] = $history->userHistory("),
    'History snapshot and asynchronous-from-game-writer presentation remain unchanged.');
$assert(str_contains($read('core/bootstrap.php'), '$runtimeRealtimeBridge->synchronizeCurrentJson();'),
    'The explicit realtime bridge remains available for authorized non-passive API work.');
echo "MGW_PASSIVE_PROFILE_HISTORY_PROJECTION=PASS ({$asserts} assertions)\n";
