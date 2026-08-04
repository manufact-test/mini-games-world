<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
$link = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
$terminal = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v115.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notification-window-owner-v119.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-authoritative-confirm-v122.js');
$presence = file_get_contents($root . '/app/assets/js/presence-v115.js');
if (!is_string($entry) || !is_string($main) || !is_string($link) || !is_string($terminal)
    || !is_string($notifications) || !is_string($opponents) || !is_string($presence)) {
    throw new RuntimeException('Missing integrated D1 opponent v122 sources.');
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
$assert(substr_count($main, "./presence-v115.js?v=115") === 1
        && substr_count($main, "./games/invite-terminal-actions-v115.js?v=115") === 1
        && substr_count($main, "./games/invite-link-entry-v115.js?v=115") === 1
        && substr_count($main, 'openIncomingInviteFromTelegram();') === 1,
    'Each non-opponent runtime owner must remain initialized exactly once.');
$assert(substr_count($entry, 'notification-window-owner-v119.js?v=119') === 1,
    'The isolated Bug B branch must retain the canonical v119 notification owner exactly once.');
$assert(substr_count($entry, 'opponents-authoritative-confirm-v122.js?v=122') === 1
        && !str_contains($entry, 'opponents-authoritative-confirm-v117.js?v=117'),
    'The entry must publish only the v122 authoritative opponent confirmation.');
$assert(!str_contains($entry, 'notification-empty-frame-guard-v115.js?v=115')
        && !str_contains($entry, 'notification-mobile-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-desktop-open-owner-v117.js?v=117')
        && !str_contains($entry, 'notification-bell-first-click-v116.js?v=116'),
    'No retired notification owner may return while changing the player picker.');
$assert(str_contains($entry, 'data-hotfix-build="v122-mvp14-opponents-authoritative-source"')
        && str_contains($entry, './assets/js/main.js?v=115')
        && str_contains($entry, 'X-MGW-Frontend-Build: v122-mvp14-opponents-authoritative-source'),
    'The package must expose the v122 shell while retaining the reviewed v115 main graph.');
$assert(str_contains($link, 'result?.opened_invite || null')
        && !str_contains($link, 'Понятно')
        && str_contains($link, 'announce:false'),
    'Deep-link entry must remain one actionable sheet without duplicate feedback.');
$assert(str_contains($terminal, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);")
        && !str_contains($terminal, 'Понятно'),
    'Decline and cancel must remain silent for the actor.');
$assert(str_contains($notifications, "window.addEventListener('click'")
        && str_contains($notifications, "document.addEventListener('mgw:notification-sync'")
        && !str_contains($notifications, 'openingSheet'),
    'The isolated Bug B branch must not alter accepted notification ownership.');
$assert(str_contains($opponents, 'REQUIRED_AUTHORITATIVE_EMPTY_RESPONSES = 2')
        && str_contains($opponents, "payload?.storage_driver === 'database'")
        && !str_contains($opponents, 'openSheet(')
        && !str_contains($presence, 'openSheet('),
    'The player picker must confirm DB-primary empties without becoming a second renderer or presence owner.');
$assert(str_contains($main, 'state.stats = mergePresenceOnline(result.stats);')
        && substr_count($main, 'state.stats = mergePresenceOnline(result.stats);') === 2
        && str_contains($presence, 'window.__MGW_V115_PRESENCE_ONLINE__'),
    'Presence-owned online count must survive bootstrap and polling.');

fwrite(STDOUT, "ProductionMvp14D1FeedbackIntegrationTest: {$assertions} assertions passed\n");
