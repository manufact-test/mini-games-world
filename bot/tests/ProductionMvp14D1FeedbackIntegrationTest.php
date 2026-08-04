<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
$link = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
$terminal = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v115.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
$presence = file_get_contents($root . '/app/assets/js/presence-v115.js');
if (!is_string($entry) || !is_string($main) || !is_string($link) || !is_string($terminal)
    || !is_string($notifications) || !is_string($background)
    || !is_string($opponents) || !is_string($presence)) {
    throw new RuntimeException('Missing integrated D1 feedback v121 sources.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$presenceInit = strpos($main, 'initV115Presence();');
$terminalInit = strpos($main, 'initInviteTerminalActions();');
$inviteInit = strpos($main, 'initGameInvites();');
$bootstrap = strpos($main, 'const result = await api.bootstrap();');
$assert($presenceInit !== false && $terminalInit !== false && $inviteInit !== false && $bootstrap !== false
        && $presenceInit < $terminalInit && $terminalInit < $inviteInit && $inviteInit < $bootstrap,
    'Integrated owner order must remain presence, terminal boundary, invites, then bootstrap.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
        && str_contains($entry, 'notifications-passive-v121.js?v=121')
        && !str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'Integrated entry must publish one v121 input owner and one passive notification service.');
$assert(!str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'The integrated entry must exclude every retired notification owner.');
foreach (['opponents-native-fetch-v115.js?v=115', 'opponents-empty-cache-guard-v115.js?v=115'] as $script) {
    $assert(substr_count($entry, $script) === 1, "Integrated entry must publish {$script} exactly once.");
}
$assert(str_contains($entry, 'data-hotfix-build="v121-mvp14-notification-short-input-owner"')
        && str_contains($entry, './assets/js/main.js?v=115')
        && str_contains($entry, 'X-MGW-Frontend-Build: v121-mvp14-notification-short-input-owner'),
    'The package must expose the v121 shell while retaining the tested v115 main graph.');
$assert(str_contains($link, 'result?.opened_invite || null')
        && !str_contains($link, 'Понятно')
        && str_contains($link, 'announce:false'),
    'Deep-link entry must remain one actionable sheet without duplicate feedback.');
$assert(str_contains($terminal, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);")
        && !str_contains($terminal, 'Понятно'),
    'Decline and cancel must remain silent for the actor.');
$assert(str_contains($notifications, "window.addEventListener('pointerdown'")
        && str_contains($notifications, "window.addEventListener('pointerup'")
        && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && str_contains($notifications, "document.addEventListener('mgw:sheet-closed'")
        && !str_contains($notifications, '.click()')
        && !str_contains($background, 'openNotificationsSheet(')
        && !str_contains($opponents, 'openSheet(')
        && !str_contains($presence, 'openSheet('),
    'v121 must own the real input sequence, cache and close races without a second sheet owner.');
$assert(str_contains($background, 'refreshNotificationBadge(false)')
        && str_contains($background, 'showNotificationToast(item)'),
    'The passive background service must retain badge and toast delivery.');
$assert(str_contains($main, 'state.stats = mergePresenceOnline(result.stats);')
        && substr_count($main, 'state.stats = mergePresenceOnline(result.stats);') === 2
        && str_contains($presence, 'window.__MGW_V115_PRESENCE_ONLINE__'),
    'Presence-owned online count must survive bootstrap and polling.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackIntegrationTest: {$assertions} assertions passed\n");
