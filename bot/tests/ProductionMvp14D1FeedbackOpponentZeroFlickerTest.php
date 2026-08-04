<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$native = file_get_contents($root . '/app/assets/js/opponents-native-fetch-v115.js');
$guard = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
$confirm = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$endpoint = file_get_contents($root . '/bot/invite-opponents.php');
if (!is_string($entry) || !is_string($native) || !is_string($guard) || !is_string($confirm) || !is_string($endpoint)) throw new RuntimeException('Missing v123 opponent sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(str_contains($entry, '$telegramScript . "\\n  " . $importMap')
    && str_contains($entry, '. "\\n  " . $nativeFetchGuard')
    && str_contains($entry, '$mainScript . "\\n  " . $opponentsGuard . "\\n  " . $opponentsConfirm')
    && substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1, 'Rendered HTML must preserve native, cache and v122 confirmation order.');
$assert(str_contains($native, 'window.__MGW_NATIVE_FETCH_V115__ = window.fetch.bind(window)')
    && str_contains($guard, 'window.__MGW_NATIVE_FETCH_V115__')
    && str_contains($confirm, 'window.__MGW_NATIVE_FETCH_V115__'), 'Both guards must preserve the same authoritative network path.');
$assert(str_contains($guard, 'cachedItems.length > 0') && str_contains($guard, "cache:'no-store'") && !str_contains($guard, 'openSheet('), 'The cache guard may bypass stale zero but cannot render UI.');
$assert(str_contains($confirm, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]')
    && str_contains($confirm, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
    && str_contains($confirm, "headers.set('Cache-Control', 'no-cache')"), 'Transient empty snapshots must remain loading through bounded confirmation.');
$assert(str_contains($confirm, 'payload?.authoritative === true')
    && str_contains($confirm, "payload?.storage_driver === 'database'")
    && str_contains($confirm, "throw new Error('Authoritative opponent list was not confirmed.')"), 'Only two DB-primary empty responses may finish as empty.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
    && str_contains($endpoint, '$storage->readOnly(')
    && str_contains($endpoint, "'authoritative' => true")
    && str_contains($endpoint, "'storage_driver' => \$storage->driver()"), 'The staging endpoint must provide a read-only DB-primary sample.');
$assert(!str_contains($confirm, 'openSheet(') && !str_contains($confirm, 'data-invite-action') && !str_contains($confirm, 'online_players'), 'v122 must remain transport-only.');
fwrite(STDOUT, "ProductionMvp14D1FeedbackOpponentZeroFlickerTest: {$assertions} assertions passed\n");
