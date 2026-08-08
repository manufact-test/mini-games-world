<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/app/assets/js/production-v101-speed-runtime-v102.js';
$source = file_get_contents($path);
if (!is_string($source)) {
    throw new RuntimeException('Cannot read production-v101-speed-runtime-v102.js');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$functionStart = strpos($source, 'function isBackgroundSafe(meta){');
$functionEnd = $functionStart === false ? false : strpos($source, '\n}\n\nfunction invalidateForMutation', $functionStart);
$assert($functionStart !== false && $functionEnd !== false, 'Background ownership function must remain explicit.');
$body = substr($source, (int)$functionStart, (int)$functionEnd - (int)$functionStart);

$watchGuard = "if (meta.pathname.endsWith('/bot/invite-watch.php')) return false;";
$syncGuard = "if (meta.pathname.endsWith('/bot/invites.php') && meta.action === 'sync') return false;";
$prefetchGuard = 'if (meta.prefetch) return true;';

$assert(str_contains($body, $watchGuard), 'Invite watch must own its lifecycle outside shared cache abort controllers.');
$assert(str_contains($body, $syncGuard), 'Authoritative invite sync must own its lifecycle outside shared cache abort controllers.');
$assert(strpos($body, $watchGuard) < strpos($body, $prefetchGuard), 'Invite watch exclusion must win even when the caller marks it as prefetch.');
$assert(strpos($body, $syncGuard) < strpos($body, $prefetchGuard), 'Invite sync exclusion must win before generic background classification.');
$assert(str_contains($body, "if (meta.pathname.endsWith('/bot/api.php') && meta.action === 'stats') return true;"), 'Ordinary cache-safe stats reads must remain abortable background work.');
$assert(str_contains($body, "if (meta.pathname.endsWith('/bot/notifications.php') && !meta.markRead) return true;"), 'Notification background ownership must remain unchanged.');
$assert(str_contains($source, "abortTracked(runtime.backgroundControllers);"), 'State-changing cache safety must still abort the remaining true cache/background work.');
$assert(!str_contains($source, 'ignoreInviteAbort') && !str_contains($source, 'retryInviteRead'), 'The fix must not hide aborts or add retries.');

fwrite(STDOUT, "ProductionV101InviteReadTransportOwnershipContractTest: {$assertions} assertions passed\n");
