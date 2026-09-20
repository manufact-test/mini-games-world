<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v117.js');
$picker = file_get_contents($root . '/app/assets/js/games/game-invites.js');
$stress = file_get_contents($root . '/e2e/staging/d1-followup-stress.spec.mjs');
if (!is_string($guard) || !is_string($picker) || !is_string($stress)) {
    throw new RuntimeException('Missing opponent first-paint sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$loadingPosition = strpos($picker, 'Загружаем соперников…');
$requestPosition = strpos($picker, 'const result = await postJson(OPPONENTS_URL, {});');
$assert($loadingPosition !== false && $requestPosition !== false && $loadingPosition < $requestPosition,
    'The canonical picker must render loading before starting the opponent request.');
$assert(str_contains($guard, 'await nextPaint();')
        && str_contains($guard, 'window.requestAnimationFrame(() => resolve())'),
    'The transport confirmation must yield one browser frame before the first request.');
$assert(strpos($guard, 'await nextPaint();') < strpos($guard, 'await upstreamFetch(input, init)'),
    'The first-paint yield must happen before any cached or network response can replace loading.');
$assert(str_contains($guard, 'RETRY_DELAYS_MS = [120, 260, 520]')
        && str_contains($guard, "cache:'no-store'")
        && str_contains($guard, 'if (await hasPlayers(response)) return response;'),
    'Bounded authoritative empty confirmation must remain unchanged after the first-paint yield.');
$assert(str_contains($stress, "frame.includes('Загружаем соперников')")
        && str_contains($stress, "frame.includes('Недавних соперников пока нет')")
        && str_contains($stress, 'stressCalls).toBeGreaterThanOrEqual(5)'),
    'The live stress suite must require loading, reject false empty state, and exercise repeated empty responses.');
$assert(!str_contains($guard, 'openSheet(')
        && !str_contains($guard, 'data-direct-opponent'),
    'The transport layer must not become a second picker renderer or action owner.');

fwrite(STDOUT, "ProductionMvp14D1OpponentFirstPaintV119Test: {$assertions} assertions passed\n");
