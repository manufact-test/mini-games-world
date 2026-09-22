<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('Cannot read ' . $path);
    return $value;
};

$storage = $read('bot/storage/StorageFactory.php');
$session = $read('bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php');
$api = $read('bot/api.php');
$admin = $read('app/assets/js/admin-tournaments.js');
$adminEntry = $read('app/admin.php');
$e2e = $read('e2e/staging/current-core-final.spec.mjs');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$sessionGate = strpos($storage, '!$requestSession->activeAt(time())');
$staleProbe = strpos($storage, 'stagingApiPrimaryNotificationSnapshotIsBehind($config)');
$assert($sessionGate !== false && $staleProbe !== false && $sessionGate < $staleProbe,
    'Expired bounded DB-primary rehearsal must leave the API path before the expensive stale-snapshot probe.');
$assert(str_contains($storage, "readOnlySections(\n                ['notifications']"),
    'Active stale-primary safety probe must read only rollback notifications, not every JSON section.');
$assert(str_contains($storage, "'read_at'=>\$mutableTimestamp(\$notification['read_at'] ?? null)")
        && str_contains($storage, "'hidden_at'=>\$mutableTimestamp(\$notification['hidden_at'] ?? null)"),
    'Stale-primary detection must include mutable notification read/hidden state.');
$assert(str_contains($storage, "(\$rollbackState['read_at'] ?? null) !== (\$primaryState['read_at'] ?? null)")
        && str_contains($storage, "(\$rollbackState['hidden_at'] ?? null) !== (\$primaryState['hidden_at'] ?? null)"),
    'Read/hidden divergence must force the staging API fallback before projection.');
$assert(str_contains($session, 'public function activeAt(int $now): bool')
        && str_contains($session, 'return $remaining > 0 && $remaining <= self::MAX_SESSION_SECONDS;'),
    'Bounded request session must expose a deterministic active-now predicate.');

$firstReady = strpos($api, '$initialReadyOwnsWindow = is_array($readyMatch)');
$attachedTerminal = strpos($api, '$progression->observeFinishedGame($attachedReadyGame);');
$assert($attachedTerminal !== false && $firstReady !== false && $attachedTerminal < $firstReady,
    'An attached finished first-round game must enter durable progression before stale Ready ownership is evaluated.');
$assert(str_contains($api, '$snapshot = $readiness->status($mgwId, $accountRef, $userId);')
        && str_contains($api, "\$readyMatch = is_array(\$snapshot['match'] ?? null)"),
    'Terminal reconciliation must refresh both readiness snapshot and local Ready match before launch decisions.');
$assert(str_contains($api, 'observeFinishedGame() is idempotent by game_id'),
    'Terminal reconciliation must document and rely on the durable idempotency boundary.');

$releaseHelper = strpos($admin, 'const releaseFocusBeforeHide = panel =>');
$hideReset = strpos($admin, 'resetPanel.hidden = true;');
$releaseReset = strpos($admin, 'releaseFocusBeforeHide(resetPanel);');
$assert($releaseHelper !== false && $releaseReset !== false && $hideReset !== false
        && $releaseReset < $hideReset,
    'Admin reset must blur a focused reset control before hiding its panel.');
$assert(str_contains($admin, 'restoreDraftControls();'),
    'Draft controls must remain explicitly interactive after reset rerender.');
$assert(str_contains($adminEntry, 'admin-tournaments.js?v=13')
        && str_contains($adminEntry, 'mvp21_8=corrective-v9'),
    'Admin reset corrective must publish a fresh Telegram WebView cache identity.');

$assert(str_contains($e2e, '[MGW_COLD_START_TIMING]')
        && str_contains($e2e, 'navigation_to_bootstrap_ms')
        && str_contains($e2e, 'navigation_to_first_usable_ms'),
    'Real staging E2E must measure the cold-start critical path.');
$assert(str_contains($e2e, "document.getElementById('preloader')?.classList.contains('hidden') === true"),
    'Cold-start measurement must end at first usable paint, not merely HTTP completion.');

if ($assertions < 13) {
    throw new RuntimeException('Corrective v9 contract is too shallow: ' . $assertions);
}
fwrite(STDOUT, "Mvp21_8CorrectiveV9ContractTest: {$assertions} assertions passed\n");
