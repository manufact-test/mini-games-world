<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$guard = file_get_contents($root . '/app/assets/js/screens/notification-bell-first-click-v116.js');
if (!is_string($entry) || !is_string($guard)) {
    throw new RuntimeException('Missing desktop bell close-race sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($entry, 'notification-bell-first-click-v116.js?v=116')
        && substr_count($entry, 'notification-bell-first-click-v116.js?v=116') === 1
        && !str_contains($entry, 'notification-bell-first-click-v115.js?v=115'),
    'The staging entry must load exactly one fresh v116 bell close-race guard.'
);

$assert(
    str_contains($guard, "const RETRY_DELAYS_MS = [180, 520]")
        && str_contains($guard, "target?.closest('#notificationsOpen')")
        && str_contains($guard, '!event.isTrusted')
        && str_contains($guard, 'bell.click();'),
    'A trusted first click may receive only bounded canonical-owner retries.'
);

$assert(
    str_contains($guard, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($guard, 'blockAutomaticReopenUntil = performance.now() + STALE_REOPEN_BLOCK_MS;')
        && str_contains($guard, "new MutationObserver(() =>")
        && str_contains($guard, 'if (performance.now() < blockAutomaticReopenUntil)')
        && str_contains($guard, 'closeSheet();'),
    'Manual close must invalidate retries and close a late stale notification repaint.'
);

$assert(
    str_contains($guard, "target?.closest('[data-close-sheet]') || target?.id === 'sheetOverlay'")
        && str_contains($guard, "document.visibilityState !== 'visible'")
        && str_contains($guard, 'retryTimers.forEach(timer => window.clearTimeout(timer));'),
    'Close button, overlay close, hidden document and successful sheet opening must cancel pending retries.'
);

$assert(
    !str_contains($guard, 'preventDefault')
        && !str_contains($guard, 'stopImmediatePropagation')
        && !str_contains($guard, 'openSheet(')
        && !str_contains($guard, 'api.notifications')
        && !str_contains($guard, 'data-invite-action'),
    'The guard must not become a second notification renderer, network owner or invite-action owner.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackDesktopBellFirstClickTest: {$assertions} assertions passed\n");
