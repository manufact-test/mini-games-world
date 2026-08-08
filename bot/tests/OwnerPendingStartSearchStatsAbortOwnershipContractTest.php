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
    str_contains($context, "function isBackgroundStatsRequest(request)")
        && str_contains($context, "requestAction(request) === 'stats';"),
    'Diagnostics must classify only the primary API stats read.'
);
$assert(
    str_contains($context, 'const inFlightBackgroundStatsRequests = new Set();'),
    'Diagnostics must retain exact currently in-flight stats Request objects.'
);
$assert(
    str_contains($context, 'const startSearchStatsAbortRequests = new WeakSet();')
        && str_contains($context, 'report.beginStartSearchStatsAbortOwnership = () => {')
        && str_contains($context, 'for (const request of inFlightBackgroundStatsRequests)')
        && str_contains($context, 'startSearchStatsAbortRequests.add(request);'),
    'start_search must snapshot only stats requests already running at the transition boundary.'
);
$assert(
    str_contains($context, 'if (isBackgroundStatsRequest(request)) inFlightBackgroundStatsRequests.add(request);')
        && str_contains($context, 'inFlightBackgroundStatsRequests.delete(request);'),
    'Stats in-flight ownership must begin on request and end on completion/failure.'
);
$assert(
    str_contains($context, 'startSearchStatsAbortRequests.has(request) && isExpectedBackgroundStatsAbort(request)')
        && str_contains($context, 'report.ignoredStartSearchStatsAborts += 1;'),
    'Only an exact owned stats request with exact ERR_ABORTED classification may be accepted.'
);
$assert(
    !str_contains($context, 'allowStartSearchStatsAbort'),
    'Stats start_search ownership must not become a broad time-window allowance.'
);
$assert(
    str_contains($scenario, 'player.diagnostics.beginStartSearchStatsAbortOwnership();')
        && str_contains($scenario, "await player.page.locator('#startSearchBtn').click();"),
    'The stats snapshot must be bound to the real start_search pointer transition.'
);
$assert(
    str_contains($scenario, 'playerA.diagnostics.ignoredStartSearchStatsAborts).toBeLessThanOrEqual(1)')
        && str_contains($scenario, 'playerB.diagnostics.ignoredStartSearchStatsAborts).toBe(0)'),
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

fwrite(STDOUT, "OwnerPendingStartSearchStatsAbortOwnershipContractTest: {$assertions} assertions passed\n");
