<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
$link = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
$terminal = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v115.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v121.js');
$background = file_get_contents($root . '/app/assets/js/screens/notifications-passive-v121.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$presence = file_get_contents($root . '/app/assets/js/presence-v115.js');
$opponentEndpoint = file_get_contents($root . '/bot/invite-opponents.php');
if (!is_string($entry) || !is_string($main) || !is_string($link) || !is_string($terminal)
    || !is_string($notifications) || !is_string($background) || !is_string($opponents)
    || !is_string($presence) || !is_string($opponentEndpoint)) {
    throw new RuntimeException('Missing integrated D1 v125 sources.');
}
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };

$presenceInit = strpos($main, 'initV115Presence();');
$terminalInit = strpos($main, 'initInviteTerminalActions();');
$inviteInit = strpos($main, 'initGameInvites();');
$bootstrap = strpos($main, 'const result = await api.bootstrap();');
$assert($presenceInit !== false && $terminalInit !== false && $inviteInit !== false && $bootstrap !== false
    && $presenceInit < $terminalInit && $terminalInit < $inviteInit && $inviteInit < $bootstrap,
    'Owner order must remain presence, terminal, invites, bootstrap.');
$assert(substr_count($entry, 'notification-window-owner-v121.js?v=121') === 1
    && str_contains($entry, 'notifications-passive-v121.js?v=121')
    && !str_contains($entry, 'notification-window-owner-v119.js?v=119'),
    'The isolated Bug B follow-up must retain one v121 notification owner and passive service.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
    && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The shell must publish only v122 opponent confirmation.');
$assert(!str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
    && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
    && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'Retired notification owners must remain excluded.');
$assert(str_contains($link, 'result?.opened_invite || null')
    && !str_contains($link, 'Понятно')
    && str_contains($link, 'announce:false'),
    'Deep-link entry must remain one actionable sheet.');
$assert(str_contains($terminal, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);")
    && !str_contains($terminal, 'Понятно'),
    'Decline and cancel must remain silent for the actor.');
$assert(str_contains($notifications, "window.addEventListener('pointerdown'")
    && str_contains($notifications, "window.addEventListener('pointerup'")
    && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
    && !str_contains($notifications, '.click()')
    && !str_contains($background, 'openNotificationsSheet('),
    'v121 must exclusively own real notification input and sheet rendering.');
$assert(str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 3')
    && str_contains($opponents, 'MIN_EMPTY_CONFIRMATION_MS = 3200')
    && str_contains($opponents, "payload?.storage_driver === 'json'")
    && str_contains($opponents, 'Number(payload?.unresolved_online_count || 0) === 0')
    && !str_contains($opponents, 'openSheet(')
    && !str_contains($presence, 'openSheet('),
    'v122 must confirm complete JSON-catalog plus presence empties without UI or presence ownership.');
$assert(str_contains($opponentEndpoint, 'StorageFactory::createJson(')
    && str_contains($opponentEndpoint, '$onlineOpponentIds')
    && str_contains($opponentEndpoint, '$unresolvedOnlineCount')
    && !str_contains($opponentEndpoint, 'DatabasePrimaryStateStorageAdapter'),
    'The endpoint must use the canonical JSON profile directory and reconcile live presence completeness.');
$assert(str_contains($main, 'state.stats = mergePresenceOnline(result.stats);')
    && substr_count($main, 'state.stats = mergePresenceOnline(result.stats);') === 2
    && str_contains($presence, 'window.__MGW_V115_PRESENCE_ONLINE__'),
    'Presence-owned online count must survive bootstrap and polling.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackIntegrationTest: {$assertions} assertions passed\n");
