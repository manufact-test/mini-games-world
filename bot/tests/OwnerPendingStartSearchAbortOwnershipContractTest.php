<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contextPath = $root . '/e2e/staging/support/d3-shared-context.mjs';
$scenarioPath = $root . '/e2e/staging/owner-pending-passive.spec.mjs';

$context = file_get_contents($contextPath);
$scenario = file_get_contents($scenarioPath);
if (!is_string($context) || !is_string($scenario)) {
    throw new RuntimeException('Owner-pending start_search E2E sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($context, 'const inFlightInviteSyncRequests = new Set();')
        && str_contains($context, 'const inFlightInviteWatchRequests = new Set();'),
    'Diagnostics must track only currently in-flight invite sync/watch requests for start_search ownership.'
);
$assert(
    str_contains($context, 'report.beginStartSearchInviteBackgroundAbortOwnership = () => {')
        && str_contains($context, 'for (const request of inFlightInviteSyncRequests)')
        && str_contains($context, 'for (const request of inFlightInviteWatchRequests)'),
    'start_search ownership must adopt already-running invite sync/watch requests at the exact transition boundary.'
);
$assert(
    str_contains($context, 'report.allowStartSearchInviteBackgroundAbort = true;')
        && str_contains($context, 'report.allowStartSearchInviteBackgroundAbort = false;'),
    'The start_search allowance must have an explicit bounded begin/end lifecycle.'
);
$assert(
    str_contains($context, 'page.on(\'requestfinished\', forgetInFlightInviteRequest);')
        && str_contains($context, 'forgetInFlightInviteRequest(request);'),
    'Completed or failed requests must leave the in-flight ownership sets.'
);
$assert(
    str_contains($context, 'startSearchInviteSyncAbortRequests.has(request) && isExpectedInviteSyncAbort(request)')
        && str_contains($context, 'startSearchInviteWatchAbortRequests.has(request) && isExpectedInviteWatchAbort(request)'),
    'Only exact sync/watch ERR_ABORTED requests owned by start_search may be classified as expected.'
);
$assert(
    str_contains($scenario, 'await startOrdinarySearchDuringCacheSafetyTransition(playerA);')
        && !str_contains($scenario, 'await playerA.page.locator(\'#startSearchBtn\').click();\n    const started = await startResponse;'),
    'The reproduced ordinary start_search click must use the exact cache-safety ownership helper.'
);
$assert(
    str_contains($scenario, 'ignoredStartSearchInviteSyncAborts).toBeLessThanOrEqual(1)')
        && str_contains($scenario, 'ignoredStartSearchInviteWatchAborts).toBeLessThanOrEqual(1)')
        && str_contains($scenario, 'playerB.diagnostics.ignoredStartSearchInviteSyncAborts).toBe(0)')
        && str_contains($scenario, 'playerB.diagnostics.ignoredStartSearchInviteWatchAborts).toBe(0)'),
    'Fresh start_search ownership must remain bounded to at most one sync and one watch abort for A and zero for B.'
);
$assert(
    str_contains($scenario, 'expect(playerA.diagnostics.ignoredInviteWatchAborts).toBeLessThanOrEqual(2);')
        && str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([]);')
        && str_contains($scenario, 'expect(playerA.diagnostics.serverErrors).toEqual([]);'),
    'Existing Home->game abort bound and strict final network/server assertions must remain unchanged.'
);
$assert(
    !str_contains($context, "includes('ERR_ABORTED')")
        && !str_contains($scenario, 'waitForTimeout(')
        && !str_contains($scenario, 'retry'),
    'The E2E root fix must not add broad abort ignores, sleeps, or retries.'
);

fwrite(STDOUT, "OwnerPendingStartSearchAbortOwnershipContractTest: {$assertions} assertions passed\n");
