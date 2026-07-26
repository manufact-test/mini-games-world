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
$worker = $read('bot/runtime/RuntimePrimaryProjectionWorker.php');
$projector = $read('bot/runtime/RuntimePrimaryAllModuleProjector.php');
$profile = $read('app/assets/js/screens/profile-screen.js');
$requestGuard = $read('app/assets/js/api/request-guard.js');
$coordinator = $read('app/assets/js/interaction-latency-coordinator.js');
$mainCss = $read('app/assets/css/main.css');
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
    str_contains($atomic, "'final_full_module_audit_executed' => false")
        && str_contains($atomic, "'worker_parity_proof_reused' => true")
        && str_contains($atomic, '$tick[\'all_module_fingerprint\']')
        && !str_contains($atomic, 'captureAndAudit('),
    'Changed writes must reuse the worker parity proof instead of repeating all nine audits.'
);

$assertTrue(
    str_contains($worker, "'all_module_fingerprint' => \$allModuleFingerprint")
        && str_contains($worker, "'mutated_modules'")
        && str_contains($worker, "'unchanged_modules'"),
    'Projection worker must expose the exact completed parity proof to the atomic adapter.'
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
    str_contains($coordinator, 'APP_CONFIG.searchIntervalMs = 800;')
        && str_contains($coordinator, 'APP_CONFIG.gameIntervalMs = 450;')
        && str_contains($coordinator, "target.id === 'startSearchBtn'")
        && str_contains($coordinator, "target.id === 'cancelSearch'")
        && str_contains($coordinator, 'showScreen(\'search\')')
        && str_contains($coordinator, 'showScreen(\'home\')'),
    'Search start and cancellation must react in the same frame as the tap.'
);

$assertTrue(
    str_contains($coordinator, 'prefetchHistory()')
        && str_contains($coordinator, 'historyCache')
        && str_contains($coordinator, 'notificationsCache')
        && str_contains($coordinator, 'refreshCacheInBackground'),
    'History and notifications must use prefetched stale-while-revalidate data.'
);

$assertTrue(
    str_contains($coordinator, 'submitOptimisticTicTacToe')
        && str_contains($coordinator, "button.textContent = symbol === 'X' ? '✕' : '○'")
        && str_contains($coordinator, 'state.timers.game = clearTimer(state.timers.game)')
        && str_contains($coordinator, 'startGamePolling(game.id)'),
    'Tic-tac-toe must render the local move immediately and reconcile with the server.'
);

$assertTrue(
    str_contains($mainCss, 'transition:none !important')
        && str_contains($mainCss, 'animation:none !important')
        && !str_contains($mainCss, '.overlay{transition-duration:.08s}'),
    'Sheet opening and closing must not expose intermediate animation frames.'
);

$assertTrue(
    str_contains($main, "v90-mvp14-complete-interaction-latency-fix")
        && str_contains($main, "interaction-latency-coordinator.js?v=90")
        && str_contains($main, 'initInteractionLatencyCoordinator();')
        && str_contains($main, "profile-screen.js?v=89")
        && str_contains($main, "ui.js?v=89"),
    'Main module graph must publish and initialize the complete latency coordinator.'
);

$assertTrue(
    str_contains($index, 'data-build="v90-mvp14-complete-interaction-latency-fix"')
        && str_contains($index, 'main.css?v=90')
        && str_contains($index, 'main.js?v=90'),
    'Telegram WebView entrypoint must bust the previous module and stylesheet cache.'
);

fwrite(
    STDOUT,
    'ProductionHotPathLatencyFixContractTest: '
    . $assertions
    . " assertions passed\n"
);
