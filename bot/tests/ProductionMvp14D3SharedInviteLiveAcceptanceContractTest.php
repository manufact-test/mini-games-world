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

$spec = $read('e2e/staging/d3-shared-invite.spec.mjs');
$config = $read('e2e/staging/support/d3-shared-config.mjs');
$context = $read('e2e/staging/support/d3-shared-context.mjs');
$actions = $read('e2e/staging/support/d3-shared-actions.mjs');
$owner = $read('app/assets/js/games/game-invites-v110.js');
$linkEntry = $read('app/assets/js/games/invite-link-entry-v110r12.js');

$assert(str_contains($config, 'export const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
    && str_contains($config, 'export const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;')
    && str_contains($config, 'export function isActionResponse(route, action)'),
    'D3 shared config must retain the ordinary v110 route and exact invite endpoint owner.');

$assert(str_contains($context, "import { openOrdinaryStartReady } from './ordinary-start-readiness.mjs';")
    && str_contains($context, 'await openOrdinaryStartReady(page, {')
    && str_contains($context, 'label: `Player ${slot}`'),
    'D3 contexts must use the shared visible-home readiness helper.');

$assert(str_contains($context, 'const telegram = {};')
    && str_contains($context, "Object.defineProperty(telegram, 'WebApp', {")
    && str_contains($context, "Object.defineProperty(window, 'Telegram', {")
    && substr_count($context, 'configurable: false') >= 2
    && str_contains($context, 'shareMessage(preparedId, callback)'),
    'The Telegram SDK must not replace either level of the native-share mock.');

$assert(str_contains($actions, 'export async function installPreparedMessageHarness(page)')
    && str_contains($actions, "requestAction(request) !== 'create_link_draft'")
    && str_contains($actions, 'const response = await route.fetch();')
    && str_contains($actions, 'const effectivePreparedId = serverPreparedId || syntheticPreparedId')
    && str_contains($actions, 'prepared_message_id: effectivePreparedId')
    && str_contains($actions, 'stop: () => page.unroute(INVITES_ROUTE, handler)'),
    'The prepared-message harness must preserve the real draft and inject only a missing prepared ID.');

$assert(str_contains($spec, "window.__MGW_D3_TELEGRAM_SHARE__.mode = 'decline'")
    && str_contains($spec, "window.__MGW_D3_TELEGRAM_SHARE__.mode = 'sent'")
    && str_contains($spec, "expect(shareState.results).toEqual(['declined', 'sent'])")
    && str_contains($spec, "expect(counterA.count('create_link_draft')).toBe(1)")
    && str_contains($spec, "expect(counterA.count('confirm_shared')).toBe(1)"),
    'Native cancellation must be quiet, reusable, and confirm one real shared draft.');

$assert(str_contains($spec, '`${APP_ROUTE}&invite=${encodeURIComponent(token)}`')
    && str_contains($spec, "expect(counterB.count('open_link')).toBe(1)")
    && str_contains($spec, "const accepted = await clickInviteAction(playerB.page, 'accept', token)")
    && str_contains($spec, "started = await clickInviteAction(playerA.page, 'start', token)")
    && str_contains($spec, "expect(counterA.count('start')).toBe(1)"),
    'The recipient must open once, accept once, and start one shared game through the existing UI owner.');

$assert(str_contains($context, 'function isExpectedInviteSyncAbort(request)')
    && str_contains($context, 'request.url() === INVITES_ROUTE')
    && str_contains($context, "requestAction(request) === 'sync'")
    && str_contains($context, "String(request.failure()?.errorText || '') === 'net::ERR_ABORTED'")
    && str_contains($context, 'allowInviteSyncAbort: false')
    && str_contains($context, 'report.allowInviteSyncAbort && isExpectedInviteSyncAbort(request)')
    && str_contains($context, 'report.ignoredInviteSyncAborts += 1;')
    && str_contains($spec, 'playerA.diagnostics.allowInviteSyncAbort = true;')
    && str_contains($spec, 'playerB.diagnostics.allowInviteSyncAbort = true;')
    && str_contains($spec, 'playerA.diagnostics.allowInviteSyncAbort = false;')
    && str_contains($spec, 'playerB.diagnostics.allowInviteSyncAbort = false;')
    && str_contains($spec, 'expect(playerA.diagnostics.ignoredInviteSyncAborts).toBeLessThanOrEqual(1)')
    && str_contains($spec, 'expect(playerB.diagnostics.ignoredInviteSyncAborts).toBeLessThanOrEqual(1)'),
    'At most one invite sync abort per participant may be ignored inside the shared start-to-active window.');

$assert(str_contains($spec, "expect(String(gameA?.game?.id || '')).toBe(gameId)")
    && str_contains($spec, "expect(String(gameB?.game?.id || '')).toBe(gameId)")
    && str_contains($spec, "expect(gameA?.game?.status).toBe('active')")
    && str_contains($spec, "expect(gameB?.game?.status).toBe('active')"),
    'Both players must resolve to the same single active game.');

$assert(str_contains($context, "new URL(request.url()).pathname === '/bot/presence.php'")
    && str_contains($context, 'report.failedRequests.push({')
    && str_contains($context, 'action: requestAction(request)')
    && str_contains($context, 'response.status() >= 500')
    && str_contains($spec, 'expect(playerA.diagnostics.failedRequests).toEqual([])')
    && str_contains($spec, 'expect(playerB.diagnostics.failedRequests).toEqual([])'),
    'Every non-controlled request failure and every same-origin 5xx response must remain fatal.');

$assert(str_contains($owner, 'tg.shareMessage(preparedId')
    && str_contains($owner, 'restoreWarmShareDraft(attempt);')
    && str_contains($owner, "inviteRequest('confirm_shared'")
    && str_contains($linkEntry, "action:'open_link'"),
    'The acceptance must stay aligned with the retained canonical D3 product owners.');

fwrite(STDOUT, 'ProductionMvp14D3SharedInviteLiveAcceptanceContractTest: ' . $assertions . " assertions passed\n");
