<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$native = file_get_contents($root . '/app/assets/js/opponents-native-fetch-v115.js');
$guard = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
$confirm = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$endpoint = file_get_contents($root . '/bot/invite-opponents.php');
if (!is_string($entry) || !is_string($native) || !is_string($guard)
    || !is_string($confirm) || !is_string($endpoint)) {
    throw new RuntimeException('Missing opponent zero-flicker v122 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($entry, '$telegramScript . "\\n  " . $importMap')
        && str_contains($entry, '. "\\n  " . $nativeFetchGuard')
        && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard . "\\n  " . $opponentsConfirm')
        && substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1,
    'Rendered HTML must capture native fetch before main, then install the cache and v122 confirmation guards after main.');
$assert(str_contains($native, 'window.__MGW_NATIVE_FETCH_V115__ = window.fetch.bind(window)')
        && str_contains($guard, 'const upstreamFetch = window.fetch.bind(window);')
        && str_contains($guard, 'window.__MGW_NATIVE_FETCH_V115__')
        && str_contains($confirm, 'window.__MGW_NATIVE_FETCH_V115__'),
    'Both transport guards must preserve the same direct network path.');
$assert(str_contains($guard, 'cachedItems.length > 0')
        && str_contains($guard, "cache:'no-store'")
        && !str_contains($guard, 'openSheet('),
    'The cache guard may bypass one stale cached zero but cannot render the picker.');
$assert(str_contains($confirm, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]')
        && str_contains($confirm, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
        && str_contains($confirm, 'for (const delayMs of RETRY_DELAYS_MS)')
        && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')"),
    'Transient empty snapshots must remain loading through bounded no-cache confirmation.');
$assert(str_contains($confirm, 'payload?.authoritative === true')
        && str_contains($confirm, "payload?.storage_driver === 'database'")
        && str_contains($confirm, "throw new Error('Authoritative opponent list was not confirmed.')"),
    'Only two marked DB-primary empty responses may finish as a real empty list.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
        && str_contains($endpoint, '$storage->readOnly(')
        && str_contains($endpoint, "'authoritative' => true")
        && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'The staging endpoint must provide a read-only DB-primary authoritative sample.');
$assert(!str_contains($confirm, 'openSheet(')
        && !str_contains($confirm, 'data-invite-action')
        && !str_contains($confirm, 'online_players'),
    'The v122 confirmation layer must stay transport-only.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackOpponentZeroFlickerTest: {$assertions} assertions passed\n");
