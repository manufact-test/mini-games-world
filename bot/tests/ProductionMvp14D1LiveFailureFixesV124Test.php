<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$owner = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v124.js');
$passive = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v124.js');
$reset = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
$picker = file_get_contents($root . '/e2e/staging/d1-bug-b-player-picker-v122.spec.mjs');
$immutable = file_get_contents($root . '/e2e/staging/frontend-immutable-core.spec.mjs');
if (!is_string($entry) || !is_string($owner) || !is_string($passive)
    || !is_string($reset) || !is_string($picker) || !is_string($immutable)) {
    throw new RuntimeException('Missing D1 v124 live-failure sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'notification-window-owner-v124.js?v=124') === 1
        && str_contains($entry, 'notifications-passive-v124.js?v=124'),
    'The shell must publish the v124 notification owner and passive service exactly once.');
$assert(str_contains($entry, 'data-hotfix-build="v124-mvp14-d1-live-failure-fixes"')
        && str_contains($entry, 'X-MGW-Frontend-Build: v124-mvp14-d1-live-failure-fixes'),
    'The no-cache shell must expose the exact v124 build identity.');
$assert(!str_contains($entry, 'notification-window-owner-v121.js?v=121')
        && !str_contains($entry, 'notifications-passive-v121.js?v=121'),
    'The active graph must retire both v121 notification resources.');

$assert(str_contains($owner, "window.addEventListener('pointerup'")
        && str_contains($owner, "window.addEventListener('touchend'")
        && str_contains($owner, "window.addEventListener('click'"),
    'Pointer, native touch and compatibility click must converge on one owner.');
$assert(str_contains($owner, 'function beginNewPhysicalInput()')
        && str_contains($owner, 'function claimGesture(triggerId)')
        && str_contains($owner, 'function isCompatibilityTail(triggerId)'),
    'The owner must deduplicate one gesture while allowing the next physical input immediately.');
$assert(str_contains($owner, 'activeTouch = {')
        && str_contains($owner, 'event.changedTouches')
        && str_contains($owner, 'TAP_MOVE_TOLERANCE_PX'),
    'Touch fallback must validate identity, movement and duration instead of opening on arbitrary touchend.');
$assert(substr_count($owner, 'openNotificationsSheet();') === 1,
    'All input paths must converge on one notification opening call.');

$assert(str_contains($passive, 'const requestStartedAt = Date.now();')
        && str_contains($passive, 'rememberBaselineNotifications(items, requestStartedAt)'),
    'A baseline request must retain its own start boundary.');
$assert(str_contains($passive, 'BASELINE_CLOCK_SAFETY_MS = 1500')
        && str_contains($passive, 'createdAt > threshold'),
    'Notifications created during an in-flight baseline must remain fresh.');
$assert(str_contains($passive, 'let pendingNotification = null;')
        && str_contains($passive, 'showPendingNotification();')
        && str_contains($passive, 'announceNotification(item)'),
    'A fresh item found before the UI is ready must remain pending rather than becoming historical.');
$assert(!str_contains($passive, 'openNotificationsSheet(')
        && !str_contains($passive, "addEventListener('click'"),
    'The passive service must not regain bell or toast opening ownership.');

$assert(str_contains($reset, 'DatabasePrimaryStateStorageAdapter(')
        && str_contains($reset, 'PdoConnectionFactory::create($databaseConfig)'),
    'The staging reset must synchronize identities into the same DB-primary state read by the picker.');
$assert(str_contains($reset, "'primary_state_synced'")
        && str_contains($reset, "'primary_state_driver'"),
    'The OIDC reset response must expose aggregate DB-primary synchronization evidence.');
$assert(str_contains($reset, '$data[\'users\'][$legacyUserId] = $user;')
        && str_contains($reset, "private const TEST_PLAYER_IDS = ['stg_test_player_a', 'stg_test_player_b'];"),
    'Only the two isolated staging identities may be copied into primary state.');
$assert(!str_contains($reset, 'production') || str_contains($reset, "'production_changed' => false"),
    'The reset must continue declaring that production is untouched.');

$assert(str_contains($picker, 'toBeGreaterThanOrEqual(1)')
        && !str_contains($picker, 'prefetchEmptyCalls, { timeout:15_000 }).toBeGreaterThanOrEqual(2)'),
    'The stress setup must reflect the actual post-main wrapper installation order.');
$assert(str_contains($picker, 'stressCalls <= 6')
        && str_contains($picker, 'stressCalls).toBeGreaterThanOrEqual(7)'),
    'The live picker test must still inject six transient empty snapshots before the authoritative response.');
$assert(str_contains($immutable, "EXPECTED_BUILD = 'v124-mvp14-d1-live-failure-fixes'")
        && str_contains($immutable, 'notifications-passive-v124.js?v=124')
        && str_contains($immutable, 'notification-window-owner-v124.js?v=124'),
    'The live immutable check must require the exact v124 graph.');

fwrite(STDOUT, "ProductionMvp14D1LiveFailureFixesV124Test: {$assertions} assertions passed\n");
