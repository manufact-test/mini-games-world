<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read D3 live acceptance source: ' . $path);
    }
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$e2e = $read('e2e/staging/d3-shared-invite.spec.mjs');
$owner = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(str_contains($e2e, "import { openOrdinaryStartReady } from './support/ordinary-start-readiness.mjs';")
    && str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
    && str_contains($e2e, 'D3 native share cancellation is quiet and one shared link creates one match'),
    'D3 live acceptance must run through the ordinary v110 Start route and shared readiness helper.');

$assert(str_contains($e2e, 'async function installPreparedMessageHarness(page)')
    && str_contains($e2e, "requestAction(request) !== 'create_link_draft'")
    && str_contains($e2e, 'const response = await route.fetch();')
    && str_contains($e2e, 'payload?.ok !== true || !payload?.invite?.token')
    && str_contains($e2e, "const serverPreparedId = String(payload.invite.prepared_message_id || '')")
    && str_contains($e2e, 'const effectivePreparedId = serverPreparedId || syntheticPreparedId')
    && str_contains($e2e, 'prepared_message_id: effectivePreparedId')
    && str_contains($e2e, 'await page.unroute(INVITES_ROUTE, handler);'),
    'The staging harness must pass through the real draft and inject only a missing prepared message ID for create_link_draft.');

$assert(str_contains($e2e, 'expect(preparedHarness.evidence.serverPreparedIds).toHaveLength(1)')
    && str_contains($e2e, 'expect(preparedHarness.evidence.effectivePreparedIds).toEqual([firstShare.preparedId])')
    && str_contains($e2e, "preparedMessageSource: preparedHarness.evidence.serverPreparedIds[0] ? 'server' : 'staging_harness'")
    && str_contains($e2e, 'await preparedHarness?.stop();'),
    'The live report must disclose the prepared-message source and always remove the route harness.');

$assert(str_contains($e2e, 'shareMessage(preparedId, callback)')
    && str_contains($e2e, "window.__MGW_D3_TELEGRAM_SHARE__.mode = 'decline'")
    && str_contains($e2e, "window.__MGW_D3_TELEGRAM_SHARE__.mode = 'sent'")
    && str_contains($e2e, 'callback?.(false)')
    && str_contains($e2e, 'callback?.(true)'),
    'The live test must exercise both native cancellation and successful native sharing callbacks.');

$assert(str_contains($e2e, "await expect(playerA.page.locator('#toast')).not.toHaveClass(/show/)")
    && str_contains($e2e, "await expect(playerA.page.locator('#notificationToast')).not.toHaveClass(/show/)")
    && str_contains($e2e, "expect(shareState.results).toEqual(['declined', 'sent'])"),
    'Native cancellation must remain quiet and the same setup must be reusable.');

$assert(str_contains($e2e, "expect(String(shareState.calls[0]?.preparedId || '')).toBe(firstShare.preparedId)")
    && str_contains($e2e, "expect(String(shareState.calls[1]?.preparedId || '')).toBe(firstShare.preparedId)")
    && str_contains($e2e, "expect(counterA.count('create_link_draft')).toBe(1)")
    && str_contains($e2e, "expect(counterA.count('confirm_shared')).toBe(1)"),
    'Cancellation retry must reuse one prepared draft and confirm it once.');

$assert(str_contains($e2e, '`${APP_ROUTE}&invite=${encodeURIComponent(token)}`')
    && str_contains($e2e, 'counterB = createActionCounter(page)')
    && str_contains($e2e, "expect(counterB.count('open_link')).toBe(1)"),
    'The recipient must open the canonical shared link through exactly one open_link request observed from navigation start.');

$assert(str_contains($e2e, "const accepted = await clickInviteAction(playerB.page, 'accept', token)")
    && str_contains($e2e, "const started = await clickInviteAction(playerA.page, 'start', token)")
    && str_contains($e2e, "expect(counterA.count('start')).toBe(1)"),
    'The shared invitation must be accepted and started once through the existing UI owner.');

$assert(str_contains($e2e, "expect(String(gameA?.game?.id || '')).toBe(gameId)")
    && str_contains($e2e, "expect(String(gameB?.game?.id || '')).toBe(gameId)")
    && str_contains($e2e, "expect(gameA?.game?.status).toBe('active')")
    && str_contains($e2e, "expect(gameB?.game?.status).toBe('active')"),
    'Both players must resolve to the same single active game.');

$assert(str_contains($e2e, "failure()?.errorText || '') === 'net::ERR_ABORTED'")
    && str_contains($e2e, "new URL(request.url()).pathname === '/bot/presence.php'")
    && str_contains($e2e, 'report.failedRequests.push({')
    && str_contains($e2e, 'response.status() >= 500'),
    'Only the controlled presence resume abort may be ignored; all other request failures and 5xx responses remain fatal.');

$assert(str_contains($owner, 'tg.shareMessage(preparedId')
    && str_contains($owner, 'restoreWarmShareDraft(attempt);')
    && str_contains($owner, "inviteRequest('confirm_shared'")
    && str_contains($linkEntry, "action:'open_link'"),
    'The live acceptance must stay aligned with the retained canonical D3 product owners.');

fwrite(STDOUT, 'ProductionMvp14D3SharedInviteLiveAcceptanceContractTest: ' . $assertions . " assertions passed\n");
