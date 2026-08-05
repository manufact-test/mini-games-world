<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$e2e = file_get_contents($root . '/e2e/staging/d2-d3-d5-integration.spec.mjs');
if (!is_string($e2e)) throw new RuntimeException('Cannot read D2 integration E2E.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($e2e, 'async function notificationByInviteToken(player, inviteToken)')
    && str_contains($e2e, 'player.page.evaluate(() => ({')
    && str_contains($e2e, 'player.context.request.post(NOTIFICATIONS_ROUTE')
    && str_contains($e2e, 'items.find(candidate =>')
    && str_contains($e2e, "String(candidate?.invite_token || '') === String(inviteToken || '')"),
    'The authenticated BrowserContext request must reduce the authoritative response to one exact-token item.');
$assert(str_contains($e2e, 'const bNotification = await notificationByInviteToken(playerB, directToken);')
    && str_contains($e2e, 'bNotification.item')
    && str_contains($e2e, 'bNotification.availableTokens.join'),
    'The Node test must assert only the scalar matched item and expose bounded diagnostics.');
$assert(!str_contains($e2e, 'async function notificationByInviteToken(page, inviteToken)')
    && !str_contains($e2e, "fetch('/bot/notifications.php'"),
    'The terminal acceptance must not pass the notification payload through the application page fetch boundary.');
$assert(!str_contains($e2e, 'const bNotifications = await expectPlayerRequest(')
    && !str_contains($e2e, 'Array.isArray(bNotifications.items)'),
    'The large notification history must not cross the Playwright structured-clone boundary.');
$assert(str_contains($e2e, "toMatch(/cancelled|canceled/)")
    && str_contains($e2e, 'bNotification.item?.actions'),
    'The second player terminal status and non-actionable state must remain mandatory.');

fwrite(STDOUT, "ProductionMvp14D2TerminalNotificationScalarAcceptanceTest: {$assertions} assertions passed\n");
