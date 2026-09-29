<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$manifest = $read('app/runtime/client/version-manifest.php');
$presence = $read('app/assets/js/production-v110-presence.js');
$reconnect = $read('app/assets/js/production-v110-reconnect-v174.js');
$spec = $read('e2e/staging/mvp25-4-reliability.spec.mjs');

$assert(
    str_contains($launch, "private const ENTRY_PATH = '/app/v110.php"),
    'MVP-25.4 reliability proof must target the real Telegram v110 entry.'
);
$assert(
    str_contains($manifest, 'main-v110-reconnect-v174.js')
        && str_contains($manifest, 'production-v110-presence.js'),
    'Canonical v110 graph must preserve the accepted presence/reconnect owners.'
);

$assert(
    str_contains($presence, "document.addEventListener('visibilitychange'")
        && str_contains($presence, "if (document.visibilityState === 'visible')")
        && str_contains($presence, "void resumePresence(true);")
        && str_contains($presence, "sendLifecycleBeacon('background');"),
    'Visibility lifecycle must distinguish foreground resume from background publication.'
);
$assert(
    str_contains($presence, "window.addEventListener('pageshow'")
        && str_contains($presence, "window.addEventListener('pagehide'")
        && str_contains($presence, 'sendLeaveBeacon();'),
    'Page lifecycle must preserve resume and true-leave ownership.'
);
$assert(
    str_contains($presence, "telegram.onEvent('activated', handleTelegramActivated)")
        && str_contains($presence, "telegram.onEvent('deactivated', handleTelegramDeactivated)"),
    'Telegram activation/deactivation must remain wired to the production presence owner.'
);
$assert(
    str_contains($presence, 'const RETRY_MS = 500;')
        && str_contains($presence, 'const REQUEST_TIMEOUT_MS = 4500;')
        && str_contains($presence, 'scheduleRetry();')
        && str_contains($presence, 'if (runtime.retryTimer || document.visibilityState !== \'visible\') return;'),
    'Temporary transport loss must use one bounded visible-only retry owner.'
);
$assert(
    str_contains($presence, 'runtime.pingController?.abort();')
        && str_contains($presence, 'runtime.statusController?.abort();'),
    'Background/forced resume must cancel stale in-flight presence work.'
);
$assert(
    str_contains($presence, "document.dispatchEvent(new CustomEvent('mgw:v110-presence-ready'))"),
    'Successful foreground recovery must publish the reconnect readiness signal.'
);

$assert(
    str_contains($reconnect, "document.addEventListener('mgw:v110-presence-ready'")
        && str_contains($reconnect, 'void resumeVisibleActiveGame();'),
    'Active-game recovery must subscribe to authoritative presence readiness.'
);
$assert(
    str_contains($reconnect, 'const result = await api.gameState(gameId);')
        && str_contains($reconnect, 'enterGame(result.game, result.me || null);'),
    'Visible active-game recovery must re-read authoritative game state through the accepted renderer.'
);
$assert(
    str_contains($reconnect, 'if (runtime.lastResumeGameId === gameId && now - runtime.lastResumeAt < 250) return false;'),
    'Duplicate foreground signals must remain collapsed instead of multiplying recovery requests.'
);

$assert(
    str_contains($spec, 'context.setOffline(true)')
        && str_contains($spec, "page.waitForEvent('requestfailed'")
        && str_contains($spec, 'context.setOffline(false)')
        && str_contains($spec, '[MGW25_4_RELIABILITY]'),
    'Canonical staging E2E must prove a real temporary network loss and recovery.'
);

fwrite(STDOUT, "Mvp25_4ReliabilityContractTest: {$assertions} assertions passed\n");
