<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contextPath = $root . '/e2e/staging/support/d3-shared-context.mjs';
$scenarioPath = $root . '/e2e/staging/owner-pending-passive.spec.mjs';

$context = file_get_contents($contextPath);
$scenario = file_get_contents($scenarioPath);
if (!is_string($context) || !is_string($scenario)) {
    throw new RuntimeException('Owner-pending start-search E2E sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($context, 'const inFlightBackgroundShopHistoryRequests = new Set();'),
    'Diagnostics must retain exact currently in-flight shop-history Request objects.'
);
$assert(
    str_contains($context, 'const startSearchShopHistoryAbortRequests = new WeakSet();')
        && str_contains($context, 'report.beginStartSearchShopHistoryAbortOwnership = () => {')
        && str_contains($context, 'for (const request of inFlightBackgroundShopHistoryRequests)')
        && str_contains($context, 'startSearchShopHistoryAbortRequests.add(request);'),
    'start_search must snapshot only shop-history requests already running at the transition boundary.'
);
$assert(
    str_contains($context, 'if (isBackgroundShopHistoryRequest(request)) inFlightBackgroundShopHistoryRequests.add(request);')
        && str_contains($context, 'inFlightBackgroundShopHistoryRequests.delete(request);'),
    'Shop-history in-flight ownership must begin on request and end on completion/failure.'
);
$assert(
    str_contains($context, 'startSearchShopHistoryAbortRequests.has(request) && isExpectedBackgroundShopHistoryAbort(request)')
        && str_contains($context, 'report.ignoredStartSearchShopHistoryAborts += 1;'),
    'Only an exact owned shop-history request with exact ERR_ABORTED classification may be accepted.'
);
$assert(
    !str_contains($context, 'allowStartSearchShopHistoryAbort'),
    'Shop-history start_search ownership must not become a broad time-window allowance.'
);
$assert(
    str_contains($scenario, 'player.diagnostics.beginStartSearchInviteBackgroundAbortOwnership();')
        && str_contains($scenario, 'player.diagnostics.beginStartSearchShopHistoryAbortOwnership();')
        && str_contains($scenario, "await player.page.locator('#startSearchBtn').click();"),
    'The shop-history snapshot must be bound to the same real start_search pointer transition.'
);
$assert(
    str_contains($scenario, 'playerA.diagnostics.ignoredStartSearchShopHistoryAborts).toBeLessThanOrEqual(1)')
        && str_contains($scenario, 'playerB.diagnostics.ignoredStartSearchShopHistoryAborts).toBe(0)'),
    'The correction must stay bounded to Player A <= 1 and Player B = 0.'
);
$assert(
    str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([]);')
        && str_contains($scenario, 'expect(playerB.diagnostics.failedRequests).toEqual([]);')
        && str_contains($scenario, 'expect(playerA.diagnostics.serverErrors).toEqual([]);')
        && str_contains($scenario, 'expect(playerB.diagnostics.serverErrors).toEqual([]);')
        && !str_contains($context, "includes('ERR_ABORTED')")
        && !str_contains($scenario, 'retry')
        && !str_contains($scenario, 'waitForTimeout('),
    'All unrelated failures remain strict and the fix must not add broad abort ignores, retries, or sleeps.'
);

fwrite(STDOUT, "OwnerPendingStartSearchShopHistoryAbortOwnershipContractTest: {$assertions} assertions passed\n");
