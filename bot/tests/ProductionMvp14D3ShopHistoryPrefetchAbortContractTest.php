<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$speed = file_get_contents($root . '/app/assets/js/production-v101-speed-runtime-v102.js');
$context = file_get_contents($root . '/e2e/staging/support/d3-shared-context.mjs');
$scenario = file_get_contents($root . '/e2e/staging/d3-shared-invite.spec.mjs');
if (!is_string($speed) || !is_string($context) || !is_string($scenario)) {
    throw new RuntimeException('Cannot read D3 shop-history prefetch contract sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($speed, 'function prefetchShopOrders()')
    && str_contains($speed, "prefetchJson('/bot/shop-history.php', {})")
    && str_contains($speed, 'mgwPrefetch:true'),
    'Shop history must remain an explicit passive prefetch in the canonical speed runtime.');
$assert(str_contains($context, "new URL(request.url()).pathname === '/bot/shop-history.php'")
    && str_contains($context, "request.method() === 'POST'")
    && str_contains($context, "request.failure()?.errorText || '') === 'net::ERR_ABORTED'"),
    'The classifier must match only the exact aborted POST shop-history request.');
$assert(str_contains($context, 'report.allowBackgroundShopHistoryAbort && isExpectedBackgroundShopHistoryAbort(request)'),
    'The exact request must remain fatal outside the explicit D3 lifecycle.');
$assert(substr_count($scenario, 'diagnostics.allowBackgroundShopHistoryAbort = true;') === 2,
    'Both D3 players must arm the exact classifier before navigation.');
$assert(substr_count($scenario, 'ignoredBackgroundShopHistoryAborts).toBeLessThanOrEqual(1)') === 2,
    'At most one controlled shop-history abort may be exposed per player.');
$assert(str_contains($scenario, 'playerA.diagnostics.allowBackgroundShopHistoryAbort = false;')
    && str_contains($scenario, 'playerB.diagnostics.allowBackgroundShopHistoryAbort = false;'),
    'The classification must close after both players prove the same active game.');
$assert(str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([])')
    && str_contains($scenario, 'expect(playerB.diagnostics.failedRequests).toEqual([])')
    && str_contains($scenario, 'expect(playerA.diagnostics.serverErrors).toEqual([])')
    && str_contains($scenario, 'expect(playerB.diagnostics.serverErrors).toEqual([])'),
    'All other failed requests and same-origin server errors must remain fatal.');

fwrite(STDOUT, 'ProductionMvp14D3ShopHistoryPrefetchAbortContractTest: '
    . $assertions . " assertions passed\n");
