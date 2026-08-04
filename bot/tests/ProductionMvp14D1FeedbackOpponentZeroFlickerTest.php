<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$native = file_get_contents($root . '/app/assets/js/opponents-native-fetch-v115.js');
$guard = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
$confirm = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
if (!is_string($entry) || !is_string($native) || !is_string($guard) || !is_string($confirm)) {
    throw new RuntimeException('Missing opponent zero-flicker sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($entry, '$telegramScript . "\\n  " . $importMap')
        && str_contains($entry, '. "\\n  " . $nativeFetchGuard')
        && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard . "\\n  " . $opponentsConfirm')
        && substr_count($entry, 'opponents-authoritative-confirm-v117.js?v=117') === 1,
    'Rendered HTML must capture native fetch before main, then install the empty-cache and confirmation guards after main in order.'
);

$assert(
    str_contains($native, "window.__MGW_NATIVE_FETCH_V115__ = window.fetch.bind(window)")
        && str_contains($guard, 'const upstreamFetch = window.fetch.bind(window);')
        && str_contains($guard, 'window.__MGW_NATIVE_FETCH_V115__')
        && str_contains($confirm, 'window.__MGW_NATIVE_FETCH_V115__'),
    'Both guards must preserve the readiness wrapper and the same direct authoritative network path.'
);

$assert(
    str_contains($guard, "url.pathname.endsWith(OPPONENTS_PATH)")
        && str_contains($guard, 'cachedItems.length > 0')
        && str_contains($guard, "cache:'no-store'"),
    'The first guard must replace one false cached zero snapshot with a direct no-store response.'
);

$assert(
    str_contains($confirm, 'RETRY_DELAYS_MS = [120, 260, 520]')
        && str_contains($confirm, 'for (const delayMs of RETRY_DELAYS_MS)')
        && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')")
        && str_contains($confirm, 'if (await hasPlayers(response)) return response;'),
    'Repeated transient empty snapshots must be confirmed through bounded delayed no-cache requests before empty UI is allowed.'
);

$assert(
    !str_contains($guard, 'openSheet(')
        && !str_contains($confirm, 'openSheet(')
        && !str_contains($confirm, 'data-invite-action')
        && !str_contains($confirm, 'online_players'),
    'The transport guards must not render UI, mutate invites or alter online presence.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackOpponentZeroFlickerTest: {$assertions} assertions passed\n");
