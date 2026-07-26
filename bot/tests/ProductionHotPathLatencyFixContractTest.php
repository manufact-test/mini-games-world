<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read contract source: ' . $path);
    }
    return $content;
};

$atomic = $read('bot/runtime/ProductionPrimaryAtomicStorageAdapter.php');
$projector = $read('bot/runtime/RuntimePrimaryAllModuleProjector.php');
$profile = $read('app/assets/js/screens/profile-screen.js');
$requestGuard = $read('app/assets/js/api/request-guard.js');
$main = $read('app/assets/js/main.js');
$index = $read('app/index.html');

$assertTrue(
    str_contains($atomic, 'baseline_full_module_audit_executed')
        && str_contains($atomic, "'baseline_full_module_audit_executed' => false")
        && !preg_match(
            '/private function captureLockedBaseline\([^}]+auditor->auditOnly/s',
            $atomic
        ),
    'Locked baseline must verify identity and queue without a full all-module audit.'
);

$assertTrue(
    str_contains($atomic, 'discardCleanupTimestampOnlyChange')
        && str_contains($atomic, "'housekeeping_only_change_discarded' => \$housekeepingOnlyChangeDiscarded")
        && str_contains($atomic, "unset(\$snapshot['system']['game_cleanup_at']);"),
    'Cleanup timestamp-only polling changes must be discarded before state persistence.'
);

$assertTrue(
    str_contains($projector, 'auditCandidateIsCurrent')
        && str_contains($projector, "'projection_skipped_in_parity'")
        && str_contains($projector, "'projection_applied'")
        && str_contains($projector, "'mutated_modules' => \$mutatedModules")
        && !str_contains($projector, '$audit = $this->auditOnly($snapshot, $stateRevision, $stateSha256);'),
    'Projection must skip current modules and avoid a duplicate global audit pass.'
);

$showPosition = strpos($profile, 'showProfileImmediately();');
$awaitPosition = strpos($profile, 'await Promise.all([');
$assertTrue(
    str_contains($profile, 'let profileLoading = false;')
        && str_contains($profile, 'if (profileLoading) return;')
        && $showPosition !== false
        && $awaitPosition !== false
        && $showPosition < $awaitPosition,
    'Profile must open immediately and coalesce repeated requests.'
);

$assertTrue(
    str_contains($profile, "../ui.js?v=89")
        && !str_contains($profile, "../ui.js?v=27"),
    'Profile must use the current avatar renderer instead of stale Telegram WebView cache.'
);

$assertTrue(
    str_contains($requestGuard, 'const GAME_STATE_MIN_GAP_MS = 700;')
        && str_contains($requestGuard, 'const SEARCH_STATE_MIN_GAP_MS = 1200;')
        && str_contains($requestGuard, 'jitterMs:hasGameId ? 60 : 100')
        && !str_contains($requestGuard, 'GAME_STATE_MIN_GAP_MS = 2400')
        && !str_contains($requestGuard, 'SEARCH_STATE_MIN_GAP_MS = 3500'),
    'Request guard must not impose multi-second game or search delays.'
);

$assertTrue(
    str_contains($main, "v89-mvp14-avatar-invite-regression-hotfix")
        && str_contains($main, "request-guard.js?v=88")
        && str_contains($main, "profile-screen.js?v=89")
        && str_contains($main, "ui.js?v=89"),
    'Main module graph must preserve latency fixes while publishing the newer hotfix version.'
);

$assertTrue(
    str_contains($index, 'data-build="v89-mvp14-avatar-invite-regression-hotfix"')
        && str_contains($index, 'main.js?v=89'),
    'Telegram WebView entrypoint must bust the previous module cache.'
);

fwrite(
    STDOUT,
    'ProductionHotPathLatencyFixContractTest: '
    . $assertions
    . " assertions passed\n"
);
