<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/deferred-notification-first-frame.spec.mjs');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
if (!is_string($spec) || !is_string($notifications)) {
    throw new RuntimeException('Missing deferred notification first-frame sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($spec, "test('D1 notification toast and bell show one fresh actionable invitation'")
    && str_contains($spec, "action: 'create_direct'")
    && str_contains($spec, "inviteeId: 'stg_test_player_b'")
    && str_contains($spec, "gameType: 'tictactoe'")
    && str_contains($spec, "room: 'match'")
    && str_contains($spec, 'bet: 10'),
    'D1 must create one fixed staging-only direct invitation from A to B.');

$assert(str_contains($spec, "playerB.page.locator('#notificationToast.show')")
    && str_contains($spec, "notificationToast.locator('.notification-toast-copy strong')")
    && str_contains($spec, 'await notificationToast.click()'),
    'D1 must enter the notification sheet through the visible blue toast.');

$assert(str_contains($spec, 'async function beginFrameCapture(page, label)')
    && str_contains($spec, "loading: sheet?.querySelector('.notifications-loading') !== null")
    && str_contains($spec, 'async function finishFrameCapture(page)')
    && str_contains($spec, 'function expectFreshPendingFrames(frames, token, label)'),
    'D1 must capture the notification sheet render sequence including loading state.');

$assert(str_contains($spec, 'const visibleLoadedFrames = frames.filter')
    && str_contains($spec, 'frame.overlayActive')
    && str_contains($spec, "frame.heading === 'Уведомления'")
    && str_contains($spec, 'frame.loading !== true')
    && str_contains($spec, 'const firstVisibleLoadedFrame = visibleLoadedFrames[0]'),
    'D1 must evaluate the first visible loaded notification frame, not hidden historical DOM.');

$assert(str_contains($spec, 'Пока уведомлений нет|0 уведомлений')
    && str_contains($spec, 'first visible loaded frame must not be a false empty state')
    && str_contains($spec, 'first visible loaded frame must contain the exact current actionable invitation')
    && !str_contains($spec, 'must not render a stale terminal invitation'),
    'D1 must reject false-empty/current-invite replacement without banning legitimate terminal history.');

$assert(str_contains($spec, "playerB.page.locator('#sheet [data-close-sheet]').click()")
    && str_contains($spec, "document.dispatchEvent(new CustomEvent('mgw:notifications-refresh'))")
    && str_contains($spec, "playerB.page.locator('#sheetOverlay')).not.toHaveClass(/active/")
    && str_contains($spec, "playerB.page.locator('#notificationsOpen').click()"),
    'The sheet must remain closed through refresh/resume and reopen only from a deliberate bell click.');

$assert(str_contains($spec, 'blueToastVisible: true')
    && str_contains($spec, 'exactPendingCardVisible: true')
    && str_contains($spec, 'falseEmptyFrameObserved: false')
    && str_contains($spec, 'staleCurrentInviteFrameObserved: false')
    && str_contains($spec, 'remainedClosedAfterDismissal: true')
    && str_contains($spec, 'deliberateBellReopenFresh: true')
    && str_contains($spec, 'productionChanged: false')
    && str_contains($spec, 'livePaymentsUsed: false'),
    'The D1 evidence report must record every accepted staging-only UI guarantee.');

$assert(str_contains($notifications, '<div class="notifications-loading"><div>🔔</div><strong>Загружаем…</strong></div>')
    && str_contains($notifications, 'const result = await api.notifications(true);')
    && str_contains($notifications, 'renderNotifications(items);'),
    'The canonical owner must keep a loading state until a fresh notification response is rendered.');

$assert(str_contains($notifications, "if (!appReady || document.visibilityState !== 'visible') return false;")
    && str_contains($notifications, "return !document.getElementById('sheetOverlay')?.classList.contains('active');")
    && str_contains($notifications, 'openNotificationsSheet();'),
    'The blue toast must remain owned by the canonical notification screen and not compete with an open sheet.');

$assert(!str_contains($spec, 'setup_secret')
    && !str_contains($spec, 'staging_test_auth_secret')
    && !str_contains($spec, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($spec, 'mini-games-world.com'),
    'The D1 live scenario must contain no long-lived secret or production target.');

fwrite(STDOUT, "ProductionMvp14DeferredNotificationFirstFrameE2ETest: {$assertions} assertions passed\n");
