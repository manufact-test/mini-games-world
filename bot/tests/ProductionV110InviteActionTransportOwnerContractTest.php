<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ownerPath = $root . '/app/assets/js/production-v110-invite-action-transport-owner.js';
$entryPath = $root . '/app/assets/js/production-clean-entry-v110.js';
$routePath = $root . '/app/v110.php';

$owner = file_get_contents($ownerPath);
$entry = file_get_contents($entryPath);
$route = file_get_contents($routePath);
if (!is_string($owner) || !is_string($entry) || !is_string($route)) {
    throw new RuntimeException('V110 invite action transport owner sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($owner, "const LIFECYCLE_ACTIONS = new Set(['accept', 'start', 'decline', 'cancel']);"),
    'Invite transport owner must remain bounded to the four lifecycle mutations.'
);
$assert(
    str_contains($owner, "if (meta.action === 'sync' && runtime.lifecycleInFlight > 0) {")
        && str_contains($owner, 'throw ownedAbort();'),
    'Background invite sync must not create a network request while a lifecycle action owns transport.'
);
$assert(
    str_contains($owner, 'runtime.lifecycleInFlight += 1;')
        && str_contains($owner, 'runtime.lifecycleInFlight = Math.max(0, runtime.lifecycleInFlight - 1);'),
    'Lifecycle transport ownership must be acquired and released exactly around the authoritative request.'
);
$assert(
    str_contains($owner, "url.pathname !== INVITES_PATH")
        && str_contains($owner, "method !== 'POST'"),
    'Transport arbitration must remain scoped to same-origin POST /bot/invites.php requests.'
);
$assert(
    !str_contains($owner, 'setTimeout(')
        && !str_contains($owner, 'setInterval(')
        && !str_contains($owner, 'retry')
        && !str_contains($owner, 'sleep'),
    'Invite transport ownership must not introduce retries, sleeps, polling or timing patches.'
);
$assert(
    str_contains($entry, "import { initV110InviteActionTransportOwner } from './production-v110-invite-action-transport-owner.js?v=1105';"),
    'V110 entry must load the isolated invite action transport owner.'
);
$speedInit = strpos($entry, 'initV101SpeedRuntime();');
$ownerInit = strpos($entry, 'initV110InviteActionTransportOwner();');
$assert(
    $speedInit !== false && $ownerInit !== false && $speedInit < $ownerInit,
    'Invite transport owner must wrap the accepted speed runtime rather than bypass it.'
);
$assert(
    str_contains($route, "./assets/js/production-clean-entry-v110.js?v=1122")
        && str_contains($route, "X-MGW-Invite-Graph: v1136"),
    'V110 route must publish the new invite transport graph through a fresh cache boundary.'
);

fwrite(STDOUT, "ProductionV110InviteActionTransportOwnerContractTest: {$assertions} assertions passed\n");
