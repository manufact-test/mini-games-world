<?php
declare(strict_types=1);

/**
 * Regression: history DB reads must never hold the JSON gameplay write lock.
 * Scope is limited to the accepted API action owners; no wallet/game mutation
 * semantics are replaced by the read-only post-transaction projection.
 */
$source = file_get_contents(dirname(__DIR__) . '/api.php');
if (!is_string($source)) throw new RuntimeException('API source unavailable');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$transactionStart = strpos($source, '$result = $db->transaction(function');
$postTransaction = strpos($source, '// The legacy Profile/History endpoints');
$assert($transactionStart !== false && $postTransaction !== false
    && $transactionStart < $postTransaction,
    'Profile/History must preserve the existing JSON transaction owner.');
$inside = substr($source, $transactionStart, $postTransaction - $transactionStart);
$outside = substr($source, $postTransaction);
$assert(substr_count($inside, "'__history_snapshot' => [") === 2,
    'Both Profile and History capture one consistent transaction-owned snapshot.');
$assert(substr_count($inside, "'__history_user_id' => \$userId") === 2,
    'Both actions must keep authenticated runtime account context.');
$assert(!str_contains($inside, '->userHistory('),
    'No DB projection history read may run while app.lock is exclusive.');
$assert(str_contains($inside, '$weeklyMatch->applyDueForUser($data, $user);')
    && str_contains($inside, 'mgw_cleanup_games_if_due($data, $games, $forceCleanup);')
    && str_contains($inside, '$sessions->ensureSessionShape($user);'),
    'Weekly grants, cleanup and session behavior must remain in their established writer owner.');
$assert(str_contains($outside, "if (in_array(\$action, ['profile', 'history'], true))")
    && str_contains($outside, "unset(\$result['__history_snapshot'], \$result['__history_user_id']);")
    && str_contains($outside, "->userHistory(\n")
    && str_contains($outside, "\$action === 'profile' ? 8 : 24"),
    'History projection must run after the transaction with accepted per-route limits.');
$assert(str_contains($outside, 'json_lock_released=1')
    && str_contains($outside, "=== 'staging'"),
    'Privacy-safe latency measurements must be staging-only.');
$assert(str_contains($source, "'user' => \$users->publicUser(\$user)")
    && str_contains($source, "'shop' => \$shop->status(\$user)"),
    'Existing live user/shop read owners remain unchanged.');
echo "MGW_HISTORY_READ_OUTSIDE_GAMEPLAY_LOCK=PASS\n";
