<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read notification mark-read source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$speed = $read('app/assets/js/production-v101-speed-runtime-v102.js');
$historicalSpeed = $read('app/assets/js/production-v101-speed-runtime.js');
$entry = $read('app/assets/js/production-clean-entry-v110.js');
$v110 = $read('app/v110.php');
$manifest = $read('bot/helpers/staging-e2e-runtime-files.txt');

$branchStart = strpos($speed, "if (descriptor.id === 'notifications' && meta.markRead)");
$branchEnd = $branchStart !== false ? strpos($speed, "return cachedFetch", $branchStart) : false;
$branch = $branchStart !== false && $branchEnd !== false
    ? substr($speed, $branchStart, $branchEnd - $branchStart)
    : '';
$assert($branch !== '' && str_contains($branch, 'return authoritativeNotificationRead('),
    'Notification mark-read requests must use the authoritative network path.');
$assert(!str_contains($branch, 'cachedFetch(')
    && !str_contains($branch, 'runtime.inFlight')
    && !str_contains($branch, 'optimisticNotificationRead('),
    'Notification mark-read requests must not use cached, shared in-flight or optimistic responses.');

$functionStart = strpos($speed, 'async function authoritativeNotificationRead(');
$functionEnd = $functionStart !== false ? strpos($speed, 'async function fetchSnapshot(', $functionStart) : false;
$function = $functionStart !== false && $functionEnd !== false
    ? substr($speed, $functionStart, $functionEnd - $functionStart)
    : '';
$assert($function !== ''
    && str_contains($function, 'const snapshot = await fetchSnapshot(')
    && str_contains($function, "rememberCache(key, 'notifications', snapshot)")
    && str_contains($function, 'return responseFromSnapshot(snapshot);'),
    'The authoritative mark-read response must wait for the server, refresh cache and return that same snapshot.');

$assert(!str_contains($speed, 'optimisticReadNotifications')
    && !str_contains($speed, 'markReadInFlight')
    && !str_contains($speed, 'optimisticNotificationRead'),
    'The active v110 speed runtime must not contain the stale optimistic mark-read implementation.');
$assert(str_contains($historicalSpeed, 'optimisticNotificationRead')
    && str_contains($historicalSpeed, 'markReadInFlight'),
    'The historical v101 rollback runtime must remain unchanged and isolated from the active v110 graph.');

$assert(str_contains($entry, "production-v101-speed-runtime-v102.js?v=102")
    && !str_contains($entry, "production-v101-speed-runtime.js?v=102"),
    'The canonical clean entry must publish only the isolated corrected speed runtime.');
$assert(str_contains($v110, "production-clean-entry-v110.js?v=1121"),
    'The ordinary v110 entrypoint must publish the corrected canonical entry with a fresh URL.');
$assert(str_contains($manifest, "app/assets/js/production-clean-entry-v110.js\n")
    && str_contains($manifest, "app/assets/js/production-v101-speed-runtime-v102.js\n"),
    'Exact staging readiness must fingerprint the active clean entry and corrected speed runtime.');

fwrite(STDOUT, 'ProductionMvp14NotificationMarkReadAuthoritativeTest: ' . $assertions . " assertions passed\n");
