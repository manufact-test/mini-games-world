<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$compat = file_get_contents($root . '/app/assets/js/notification-compat-click-guard-v127.js');
$fresh = file_get_contents($root . '/app/assets/js/opponents-fresh-user-action-v127.js');
$reset = file_get_contents($root . '/bot/services/StagingTestPlayerStateResetService.php');
if (!is_string($entry) || !is_string($compat) || !is_string($fresh) || !is_string($reset)) {
    throw new RuntimeException('Missing v127 real-user regression sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$compatPosition = strpos($entry, 'notification-compat-click-guard-v127.js?v=127');
$ownerPosition = strpos($entry, 'notification-window-owner-v121.js?v=121');
$freshPosition = strpos($entry, 'opponents-fresh-user-action-v127.js?v=127');
$confirmPosition = strpos($entry, 'opponents-authoritative-confirm-v122.js?v=122');

$assert($compatPosition !== false && $ownerPosition !== false && $compatPosition < $ownerPosition,
    'The compatibility-click observer must register before the canonical notification owner.');
$assert($freshPosition !== false && $confirmPosition !== false && $freshPosition > $confirmPosition,
    'The fresh manual-picker transport must be the final opponent fetch owner.');
$assert(str_contains($entry, 'notifications-passive-v121.js?v=121')
        && !str_contains($entry, 'notifications-passive-v124.js')
        && !str_contains($entry, 'notification-window-owner-v124.js'),
    'The rejected v124 notification baseline and blue-toast behavior must remain rolled back.');

$assert(str_contains($compat, "target?.id === 'sheetOverlay'")
        && str_contains($compat, 'event.stopImmediatePropagation();'),
    'A compatibility click retargeted to the new overlay must be consumed before it closes the sheet.');
$assert(str_contains($compat, 'queueMicrotask(() =>')
        && str_contains($compat, 'trigger.click();')
        && str_contains($compat, 'if (isNotificationsSheetOpen()) return;'),
    'A valid physical press may recover only when the canonical owner did not open the sheet.');
$assert(str_contains($compat, 'COMPATIBILITY_CLICK_DISTANCE_PX')
        && str_contains($compat, 'performance.now() > guard.expiresAt'),
    'Compatibility suppression must be short-lived and position-bound.');

$assert(str_contains($fresh, 'window.__MGW_NATIVE_FETCH_V115__')
        && str_contains($fresh, "cache:'no-store'")
        && str_contains($fresh, "headers.set('X-MGW-Opponents-Source', 'manual-picker-v127')"),
    'Manual player selection must bypass the boot-time response cache.');
$assert(str_contains($fresh, 'RETRY_DELAYS_MS = [140, 320, 620]')
        && str_contains($fresh, 'snapshot.hasPlayers')
        && str_contains($fresh, "payload?.storage_driver === 'database'"),
    'The picker must keep loading until a fresh player or confirmed DB-primary empty response arrives.');
$assert(!str_contains($fresh, 'return jsonResponse(') && !str_contains($fresh, 'opponentsCache'),
    'The manual picker must never render an old non-empty cache as the current player list.');

$assert(!str_contains($reset, 'synchronizePrimaryState(')
        && !str_contains($reset, "['status'] = 'idle'"),
    'The rejected v124 E2E reset must not write test identities or status into DB-primary state.');

fwrite(STDOUT, "ProductionMvp14D1RealUserRegressionsV127Test: {$assertions} assertions passed\n");
