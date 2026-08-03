<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$native = file_get_contents($root . '/app/assets/js/opponents-native-fetch-v115.js');
$guard = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
if (!is_string($entry) || !is_string($native) || !is_string($guard)) {
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
        && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard')
        && str_contains($entry, 'str_replace($mainScript, $mainScript . "\\n  " . $opponentsGuard, $html);'),
    'Rendered HTML must capture native fetch before main readiness wrapping and install the empty-cache guard after main.'
);

$assert(
    str_contains($native, "window.__MGW_NATIVE_FETCH_V115__ = window.fetch.bind(window)")
        && str_contains($guard, 'const upstreamFetch = window.fetch.bind(window);')
        && str_contains($guard, 'window.__MGW_NATIVE_FETCH_V115__'),
    'The guard must preserve both the readiness wrapper and a direct authoritative network path.'
);

$assert(
    str_contains($guard, "url.pathname.endsWith(OPPONENTS_PATH)")
        && str_contains($guard, "method !== 'POST'")
        && str_contains($guard, 'cachedItems.length > 0')
        && str_contains($guard, "cache:'no-store'"),
    'Only an empty cached POST opponents response may fall through to an authoritative no-store request.'
);

$assert(
    str_contains($guard, 'return authoritativeResponse.ok ? authoritativeResponse : cachedResponse;')
        && str_contains($guard, 'return cachedResponse;'),
    'A successful authoritative response must replace the false zero snapshot while failures retain safe fallback behavior.'
);

$assert(
    !str_contains($guard, 'openSheet(')
        && !str_contains($guard, 'notifications-empty')
        && !str_contains($guard, 'data-invite-action')
        && !str_contains($guard, 'online_players'),
    'This isolated cache guard must not render UI, mutate invites or alter online presence.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackOpponentZeroFlickerTest: {$assertions} assertions passed\n");
