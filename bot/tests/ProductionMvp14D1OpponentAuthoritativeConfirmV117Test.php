<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$guard = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
if (!is_string($entry) || !is_string($guard)) {
    throw new RuntimeException('Missing opponent confirmation v122 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The authoritative opponent confirmation v122 must be published exactly once.');
$assert(str_contains($guard, 'RETRY_DELAYS_MS = [80, 140, 240, 400, 650, 950]')
        && str_contains($guard, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
        && str_contains($guard, 'for (const delayMs of RETRY_DELAYS_MS)'),
    'An empty list must remain loading through bounded delayed confirmation.');
$assert(str_contains($guard, "cache:'no-store'")
        && str_contains($guard, "headers.set('Cache-Control', 'no-cache')"),
    'Confirmation requests must bypass browser and intermediary caches.');
$assert(str_contains($guard, 'if (snapshot.hasPlayers) return response;')
        && str_contains($guard, 'authoritativeEmptyResponses >= REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES'),
    'The first real player list must win while final empty requires two authoritative responses.');
$assert(str_contains($guard, 'payload?.authoritative === true')
        && str_contains($guard, "payload?.storage_driver === 'database'")
        && str_contains($guard, "throw new Error('Authoritative opponent list was not confirmed.')"),
    'Unmarked empty transport samples must never become the user-visible final state.');
$assert(!str_contains($guard, 'openSheet('),
    'The guard must delay the existing request rather than becoming a picker renderer.');

fwrite(STDOUT, "ProductionMvp14D1OpponentAuthoritativeConfirmV117Test: {$assertions} assertions passed\n");
