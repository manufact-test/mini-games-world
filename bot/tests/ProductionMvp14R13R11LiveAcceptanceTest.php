<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$spec = file_get_contents($root . '/e2e/staging/two-context.spec.mjs');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');
if (!is_string($spec) || !is_string($notifications) || !is_string($invites)) {
    throw new RuntimeException('Missing live R11 acceptance source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($spec, "locator('#profileOpen').click()")
    && str_contains($spec, "locator('#screen-profile')")
    && str_contains($spec, "locator('[data-back-home]').click()"),
    'The live suite must open and close the rendered profile screen.');

$assert(str_contains($spec, 'openNotificationToastAndWaitForAction')
    && str_contains($spec, "locator('#notificationToast')")
    && str_contains($spec, 'closeNotificationSheetAndAssertStable')
    && str_contains($spec, 'waitForTimeout(3_500)')
    && str_contains($spec, "not.toHaveClass(/show/)"),
    'The live suite must click the mobile notification toast and prove it stays dismissed.');

$assert(str_contains($spec, "actionResponse(INVITES_ROUTE, 'create_link_draft')") === false
    && str_contains($spec, "isActionResponse(INVITES_ROUTE, 'create_link_draft')")
    && str_contains($spec, "locator('[data-copy-invite-link]')")
    && str_contains($spec, "isActionResponse(INVITES_ROUTE, 'discard_draft')"),
    'The live suite must prepare and discard a real Share draft.');

$assert(str_contains($spec, "locator('[data-open-player-picker]').click()")
    && str_contains($spec, "data-direct-opponent=\"stg_test_player_b\"")
    && str_contains($spec, "isActionResponse(INVITES_ROUTE, 'create_direct')")
    && str_contains($spec, "data-invite-action=\"cancel\""),
    'The live suite must choose TEST PLAYER B and cancel the real direct invitation.');

$assert(str_contains($spec, 'cancelledInviteActionsRemoved: true')
    && str_contains($spec, 'productionChanged: false')
    && str_contains($spec, 'livePaymentsUsed: false'),
    'The safe report must preserve staging-only and no-payment evidence.');

$assert(str_contains($notifications, "ANNOUNCED_STORAGE_KEY = 'mgw_announced_notifications_v3'")
    && str_contains($notifications, "el.addEventListener('click'")
    && str_contains($notifications, 'dismissNotificationToast();')
    && str_contains($notifications, 'openNotificationsSheet();'),
    'The tested toast must remain backed by canonical notification ownership.');

$assert(str_contains($invites, "data-open-player-picker")
    && str_contains($invites, "data-create-link-invite")
    && str_contains($invites, "data-direct-opponent")
    && str_contains($invites, "data-invite-action=\"cancel\""),
    'The tested controls must remain backed by the production invite coordinator.');

$assert(!str_contains($spec, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($spec, 'mini-games-world.com')
    && !str_contains($spec, 'setup_secret'),
    'The live R11 acceptance scenario must contain no production target or long-lived secret.');

fwrite(STDOUT, "ProductionMvp14R13R11LiveAcceptanceTest: {$assertions} assertions passed\n");
