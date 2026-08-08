<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contextPath = $root . '/e2e/staging/support/d3-shared-context.mjs';
$actionsPath = $root . '/e2e/staging/support/d3-shared-actions.mjs';
$scenarioPath = $root . '/e2e/staging/d3-shared-invite.spec.mjs';

$context = file_get_contents($contextPath);
$actions = file_get_contents($actionsPath);
$scenario = file_get_contents($scenarioPath);
if (!is_string($context) || !is_string($actions) || !is_string($scenario)) {
    throw new RuntimeException('D3 accept-transition E2E sources are unavailable.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(
    str_contains($context, 'const inFlightInviteSyncRequests = new Set();'),
    'Diagnostics must retain the exact currently in-flight invite sync Request objects.'
);
$assert(
    str_contains($context, 'const acceptInviteSyncAbortRequests = new WeakSet();')
        && str_contains($context, 'report.beginAcceptInviteSyncAbortOwnership = () => {')
        && str_contains($context, 'for (const request of inFlightInviteSyncRequests)')
        && str_contains($context, 'acceptInviteSyncAbortRequests.add(request);'),
    'Accept ownership must snapshot only invite sync requests already running at the transition boundary.'
);
$assert(
    str_contains($context, 'acceptInviteSyncAbortRequests.has(request) && isExpectedInviteSyncAbort(request)')
        && str_contains($context, 'report.ignoredAcceptInviteSyncAborts += 1;'),
    'Only exact owned sync requests with the existing strict ERR_ABORTED classifier may be ignored.'
);
$assert(
    !str_contains($context, 'allowAcceptInviteSyncAbort'),
    'Accept ownership must not become a broad time-window allowance for later requests.'
);
$assert(
    str_contains($actions, 'clickInviteAction(page, action, token, beforePointerDown = null)')
        && str_contains($actions, "if (typeof beforePointerDown === 'function') beforePointerDown();\n  await button.click();"),
    'The exact ownership snapshot must happen immediately before the real pointer action.'
);
$assert(
    str_contains($scenario, "'accept',\n      token,\n      () => playerB.diagnostics.beginAcceptInviteSyncAbortOwnership(),")
        && str_contains($scenario, 'playerA.diagnostics.ignoredAcceptInviteSyncAborts).toBe(0)')
        && str_contains($scenario, 'playerB.diagnostics.ignoredAcceptInviteSyncAborts).toBeLessThanOrEqual(1)'),
    'D3 must scope the new ownership to Player B Accept, with A=0 and B<=1.'
);
$assert(
    str_contains($scenario, 'expect(playerA.diagnostics.failedRequests).toEqual([]);')
        && str_contains($scenario, 'expect(playerB.diagnostics.failedRequests).toEqual([]);')
        && str_contains($scenario, 'expect(playerA.diagnostics.serverErrors).toEqual([]);')
        && str_contains($scenario, 'expect(playerB.diagnostics.serverErrors).toEqual([]);'),
    'Final request and server-error assertions must remain strict for both players.'
);
$assert(
    !str_contains($context, "includes('ERR_ABORTED')")
        && !str_contains($scenario, 'retry')
        && !str_contains($scenario, 'waitForTimeout('),
    'The correction must not add broad abort ignores, retries, or sleeps.'
);

fwrite(STDOUT, "D3AcceptInviteSyncAbortOwnershipContractTest: {$assertions} assertions passed\n");
