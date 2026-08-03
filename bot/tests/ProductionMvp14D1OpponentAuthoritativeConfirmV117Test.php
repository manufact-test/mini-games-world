<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$guard = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
if (!is_string($entry) || !is_string($guard)) {
    throw new RuntimeException('Missing opponent confirmation sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'opponents-authoritative-confirm-v117.js?v=117') === 1,
    'The authoritative opponent confirmation guard must be published exactly once.');
$assert(str_contains($guard, 'RETRY_DELAYS_MS = [120, 260, 520]')
        && str_contains($guard, 'for (const delayMs of RETRY_DELAYS_MS)'),
    'An empty list must be confirmed through bounded delayed retries.');
$assert(str_contains($guard, "cache:'no-store'")
        && str_contains($guard, "headers.set('Cache-Control', 'no-cache')"),
    'Confirmation requests must bypass browser and CDN response caches.');
$assert(str_contains($guard, 'if (await hasPlayers(response)) return response;')
        && str_contains($guard, 'return latestResponse;'),
    'The first real player list must win, while a true empty result appears only after all confirmations.'
);
$assert(!str_contains($guard, 'openSheet('),
    'The guard must delay the existing request rather than becoming another player-picker renderer.');

fwrite(STDOUT, "ProductionMvp14D1OpponentAuthoritativeConfirmV117Test: {$assertions} assertions passed\n");
