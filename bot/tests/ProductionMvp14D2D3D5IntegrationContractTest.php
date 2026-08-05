<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$entry = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$d3 = $read('bot/tests/ProductionMvp14D3SharedInviteAcceptanceTest.php');
$d5 = $read('bot/tests/PresenceServiceDocumentLeaseAcceptanceTest.php');
$e2e = $read('e2e/staging/d2-d3-d5-integration.spec.mjs');
$config = $read('e2e/playwright.config.mjs');

$assert(str_contains($entry, 'main-v110.js?v=1130')
    && str_contains($main, 'main-v110-handoff-shell.js?v=1130')
    && str_contains($shell, 'game-invites-v110.js?v=1130'),
    'The integrated ordinary Start graph must publish the exact D2 canonical owner as v1130.');

$assert(substr_count($shell, 'initGameInvites();') === 1
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && !str_contains($shell, 'initInviteTerminalActions')
    && !str_contains($shell, 'invite-terminal-actions-v110r12.js'),
    'The integration must keep one invite owner and one notification owner without the retired interceptor.');

$performStart = strpos($invites, 'async function performInviteAction(');
$performEnd = strpos($invites, 'async function createRematch(', $performStart ?: 0);
$perform = $performStart !== false && $performEnd !== false
    ? substr($invites, $performStart, $performEnd - $performStart)
    : '';
$assert($perform !== ''
    && !str_contains($perform, "action === 'decline' || action === 'cancel') {\n    closeSheet();")
    && !str_contains($perform, "toast('Приглашение отклонено.')")
    && !str_contains($perform, "toast('Приглашение отменено.')")
    && str_contains($perform, "new CustomEvent('mgw:notification-sync'")
    && str_contains($perform, 'showTerminalInvite(terminalInvite);'),
    'D2 must keep decline/cancel on the current canonical surface without actor confirmation.');

$assert(str_contains($invites, "card.closest('#sheet')?.querySelector('[data-notifications-owner=\"r12\"]')")
    && str_contains($invites, 'actions:[]')
    && str_contains($notifications, 'data-notification-type="${escapeHtml(item.type)}"')
    && str_contains($notifications, 'renderNotifications(visibleSheetItems());'),
    'The exact notification card must become a non-actionable terminal card in place.');

$assert(str_contains($d3, 'tg.shareMessage(preparedId')
    && str_contains($d3, "String(errorCode || '') === 'USER_DECLINED'")
    && str_contains($d3, "case 'open_link':")
    && str_contains($d3, 'game-invites-v110.js?v=1130'),
    'The already-green D3 contract must be carried unchanged except for the accepted v1130 graph.');

$assert(str_contains($d5, 'Two open documents for one account must count as one online player.')
    && str_contains($d5, 'A delayed leave from the old document must not turn the new document offline.')
    && str_contains($d5, 'Closing and expiring the final document must remove the account from online players.')
    && str_contains($d5, "!str_contains(\$statsOwner, 'ONLINE_DROP_GRACE_MS')"),
    'The already-green D5 functional lease proof must be present without UI smoothing.');

$assert(str_contains($config, 'Player A uses Share, player picker and cancellation through the live UI')
    && str_contains($config, 'grepInvert:supersededScenarios'),
    'The retired close-sheet E2E expectation must be excluded exactly by title.');

$assert(str_contains($e2e, 'const APP_ROUTE = `${STAGING_ORIGIN}/app/v110.php?v=1123`;')
    && str_contains($e2e, "test('D2-D3-D5 integration: Share, picker and cancellation keep terminal card in place'")
    && str_contains($e2e, "const authoritativeCancelledLabel = String(cancelled?.invite?.status_label || '').trim();")
    && str_contains($e2e, "expect(authoritativeCancelledLabel).toBe('Отменено');")
    && str_contains($e2e, "toHaveText(authoritativeCancelledLabel")
    && !str_contains($e2e, "toHaveText('Приглашение отменено'")
    && str_contains($e2e, "#sheet [data-invite-action]')).toHaveCount(0")
    && str_contains($e2e, "#notificationToast')).not.toHaveClass(/show/")
    && str_contains($e2e, 'async function notificationByInviteToken(player, inviteToken)')
    && str_contains($e2e, 'player.context.request.post(NOTIFICATIONS_ROUTE')
    && str_contains($e2e, 'items.find(candidate =>')
    && str_contains($e2e, 'const bNotification = await notificationByInviteToken(playerB, directToken);')
    && str_contains($e2e, 'otherParticipantTerminalStatusPresent: true'),
    'The live E2E must use the authoritative label and return one exact-token terminal notification through the authenticated BrowserContext request.');

$assert(!str_contains($e2e, 'const bNotifications = await expectPlayerRequest(')
    && !str_contains($e2e, 'Array.isArray(bNotifications.items)')
    && !str_contains($e2e, "fetch('/bot/notifications.php'")
    && str_contains($e2e, 'bNotification.availableTokens.join'),
    'The notification history must bypass the application page fetch and structured-clone boundaries.');

$assert(!str_contains($e2e, "not.toHaveClass(/active/")
    && str_contains($e2e, "await expect(overlay).toHaveClass(/active/"),
    'The replacement scenario must forbid the superseded close-sheet acceptance.');

$assert(str_contains($e2e, '[data-create-link-invite]')
    && str_contains($e2e, '[data-discard-draft]')
    && str_contains($e2e, '[data-open-player-picker]')
    && str_contains($e2e, '[data-direct-opponent="stg_test_player_b"]'),
    'The integration E2E must preserve Share and player-picker coverage while validating D2.');

fwrite(STDOUT, "ProductionMvp14D2D3D5IntegrationContractTest: {$assertions} assertions passed\n");
