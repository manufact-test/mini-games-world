<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read contract source: ' . $path);
    return $content;
};

$atomic = $read('bot/runtime/ProductionPrimaryAtomicStorageAdapter.php');
$worker = $read('bot/runtime/RuntimePrimaryProjectionWorker.php');
$projector = $read('bot/runtime/RuntimePrimaryAllModuleProjector.php');
$profile = $read('app/assets/js/screens/profile-screen.js');
$requestGuard = $read('app/assets/js/api/request-guard.js');
$coordinator = $read('app/assets/js/interaction-latency-coordinator.js');
$residual = $read('app/assets/js/residual-ui-game-race-fix.js');
$readiness = $read('app/assets/js/first-interaction-readiness.js');
$invitesEndpoint = $read('bot/invites.php');
$mainCss = $read('app/assets/css/main.css');
$main = $read('app/assets/js/main.js');
$index = $read('app/index.html');

$assertTrue(
    str_contains($atomic, 'baseline_full_module_audit_executed')
        && str_contains($atomic, "'baseline_full_module_audit_executed' => false")
        && !preg_match('/private function captureLockedBaseline\([^}]+auditor->auditOnly/s', $atomic),
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
    str_contains($profile, 'hasProfileStats(state.profileStats)')
        && str_contains($profile, 'Number(stats.games_played)')
        && !str_contains($profile, 'stats.games_played ?? 0')
        && !str_contains($profile, 'stats.wins ?? 0'),
    'Profile must never paint fake zero statistics before the real snapshot is ready.'
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
        && str_contains($coordinator, "showScreen('search')")
        && str_contains($coordinator, "showScreen('home')"),
    'The proven v90 coordinator must retain immediate search navigation.'
);

$assertTrue(
    str_contains($residual, "target.id === 'balanceHistoryBtn'")
        && str_contains($residual, "target.id === 'matchHistoryBtn'")
        && str_contains($residual, "target.id === 'notificationsOpen'")
        && str_contains($residual, 'renderBalanceHistorySheet')
        && str_contains($residual, 'renderNotificationsSheet')
        && !str_contains($residual, 'Загружаем историю')
        && !str_contains($residual, 'Загружаем…'),
    'Cached history and notifications must render directly without a loading frame.'
);

$assertTrue(
    str_contains($readiness, 'export async function warmFirstInteractionData()')
        && str_contains($readiness, 'warmProfileSnapshot()')
        && str_contains($readiness, 'api.history()')
        && str_contains($readiness, 'api.notifications(false)')
        && str_contains($readiness, 'warmShopOrders()')
        && str_contains($readiness, 'refreshOpponentsNetwork(true)')
        && str_contains($readiness, 'Promise.allSettled(tasks)'),
    'The common preloader must warm every first-click data source before the app becomes interactive.'
);

$assertTrue(
    str_contains($readiness, "target.matches('[data-invite-friend]')")
        && str_contains($readiness, "target.matches('[data-invite-size], [data-invite-bet]')")
        && str_contains($readiness, "target.matches('[data-create-link-invite]')")
        && str_contains($readiness, 'prepareMessage:false')
        && str_contains($readiness, 'openTelegramShare(shareUrl, shareText)')
        && str_contains($readiness, 'openTelegramLink'),
    'Link drafts must be prepared before the tap and opened through the ready share URL.'
);

$assertTrue(
    str_contains($readiness, 'opponentsCache?.data')
        && str_contains($readiness, 'return jsonResponse(opponentsCache.data)')
        && str_contains($readiness, "url.pathname.endsWith('/bot/invite-opponents.php')"),
    'The player picker must receive a same-frame cached opponent response.'
);

$assertTrue(
    str_contains($invitesEndpoint, "array_key_exists('prepareMessage', \$payload)")
        && str_contains($invitesEndpoint, "\$result['invite']['prepared_message_id'] = \$prepareMessage")
        && str_contains($invitesEndpoint, '? mgw_prepare_invite_message(')
        && str_contains($invitesEndpoint, ": '';"),
    'Fast draft creation must skip the external Telegram prepared-message call when requested.'
);

$assertTrue(
    str_contains($residual, 'gameStateInFlightByKey')
        && str_contains($residual, 'gameActionPromiseByKey')
        && str_contains($residual, 'latestGameResultByKey')
        && str_contains($residual, 'generation !== generationFor(key)')
        && str_contains($residual, 'gameStateInFlightByKey.get(key)'),
    'Game-state requests must stay serialized per exact game/search key.'
);

$assertTrue(
    str_contains($residual, 'handleTicTacToeCell')
        && str_contains($residual, 'event.stopImmediatePropagation();')
        && str_contains($residual, "board[cell] === '-'")
        && str_contains($residual, 'renderAuthoritativeTicTacToe')
        && str_contains($residual, "button.textContent = symbol === 'X' ? '✕' : '○'"),
    'Tic-tac-toe must keep one early click owner and authoritative reconciliation.'
);

$readinessInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$residualInit = strpos($main, 'initResidualUiGameRaceFixEarly();');
$warmPosition = strpos($main, 'const firstInteraction = await warmFirstInteractionData();');
$guardPosition = strpos($main, 'if (!firstInteractionReady)');
$appReadyPosition = strpos($main, 'dispatchAppReady();');
$assertTrue(
    str_contains($main, "v92-mvp14-first-interaction-readiness-hotfix")
        && str_contains($main, "first-interaction-readiness.js?v=92")
        && str_contains($main, "profile-screen.js?v=92")
        && $readinessInit !== false
        && $residualInit !== false
        && $readinessInit < $residualInit
        && $warmPosition !== false
        && $guardPosition !== false
        && $appReadyPosition !== false
        && $warmPosition < $guardPosition
        && $guardPosition < $appReadyPosition,
    'V92 must install first and require readiness before app-ready is dispatched.'
);

$assertTrue(
    str_contains($mainCss, 'transition:none !important')
        && str_contains($mainCss, 'animation:none !important')
        && !str_contains($mainCss, '.overlay{transition-duration:.08s}'),
    'Sheet opening and closing must not expose intermediate animation frames.'
);

$assertTrue(
    str_contains($index, 'data-build="v92-mvp14-first-interaction-readiness-hotfix"')
        && str_contains($index, 'main.css?v=92')
        && str_contains($index, 'main.js?v=92'),
    'Telegram WebView entrypoint must bust every v91 module and stylesheet cache.'
);

fwrite(STDOUT, 'ProductionHotPathLatencyFixContractTest: ' . $assertions . " assertions passed\n");
