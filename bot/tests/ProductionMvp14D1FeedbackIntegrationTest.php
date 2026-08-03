<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$entry = file_get_contents($root . '/app/v114.php');
$main = file_get_contents($root . '/app/assets/js/main.js');
$link = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
$terminal = file_get_contents($root . '/app/assets/js/games/invite-terminal-actions-v115.js');
$empty = file_get_contents($root . '/app/assets/js/screens/notification-empty-frame-guard-v115.js');
$bell = file_get_contents($root . '/app/assets/js/screens/notification-bell-first-click-v116.js');
$opponents = file_get_contents($root . '/app/assets/js/opponents-empty-cache-guard-v115.js');
$presence = file_get_contents($root . '/app/assets/js/presence-v115.js');
if (!is_string($entry) || !is_string($main) || !is_string($link) || !is_string($terminal)
    || !is_string($empty) || !is_string($bell) || !is_string($opponents) || !is_string($presence)) {
    throw new RuntimeException('Missing integrated D1 feedback sources.');
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
$assert(
    $presenceInit !== false && $terminalInit !== false && $inviteInit !== false && $bootstrap !== false
        && $presenceInit < $terminalInit
        && $terminalInit < $inviteInit
        && $inviteInit < $bootstrap,
    'Integrated owner order must be presence, silent terminal boundary, canonical invites, then bootstrap.'
);

$assert(
    substr_count($main, "./presence-v115.js?v=115") === 1
        && substr_count($main, "./games/invite-terminal-actions-v115.js?v=115") === 1
        && substr_count($main, "./games/invite-link-entry-v115.js?v=115") === 1
        && substr_count($main, 'openIncomingInviteFromTelegram();') === 1
        && !str_contains($main, 'openIncomingInviteIfPresent'),
    'The integrated runtime must expose each active owner exactly once and remove the stale link-entry path.'
);

$publishedScripts = [
    'notification-empty-frame-guard-v115.js?v=115',
    'notification-bell-first-click-v116.js?v=116',
    'opponents-native-fetch-v115.js?v=115',
    'opponents-empty-cache-guard-v115.js?v=115',
];
foreach ($publishedScripts as $script) {
    $assert(substr_count($entry, $script) === 1, "Integrated entry must publish {$script} exactly once.");
}
$assert(!str_contains($entry, 'notification-bell-first-click-v115.js?v=115'),
    'The stale bell retry guard must not remain active beside v116.');

$assert(
    str_contains($entry, 'data-hotfix-build="v115-mvp14-d1-feedback-integration"')
        && str_contains($entry, './assets/js/main.js?v=115')
        && str_contains($entry, 'X-MGW-Frontend-Build: v115-mvp14-d1-feedback-integration'),
    'The combined package must keep one coherent v115 browser cache identity with a fresh v116 bell guard.'
);

$assert(
    str_contains($link, 'result?.opened_invite || null')
        && !str_contains($link, 'Понятно')
        && str_contains($link, 'announce:false'),
    'Deep-link entry must render one actionable sheet without duplicate terminal or toast feedback.'
);

$assert(
    str_contains($terminal, "const TERMINAL_ACTIONS = new Set(['decline', 'cancel']);")
        && !str_contains($terminal, 'Приглашение отменено')
        && !str_contains($terminal, 'Приглашение отклонено')
        && !str_contains($terminal, 'Понятно'),
    'Decline and cancel must remain silent for the actor in the integrated build.'
);

$assert(
    !str_contains($empty, 'stopImmediatePropagation')
        && !str_contains($bell, 'stopImmediatePropagation')
        && !str_contains($bell, 'openSheet(')
        && str_contains($bell, "document.addEventListener('mgw:sheet-closed'")
        && str_contains($bell, 'closeSheet();')
        && !str_contains($opponents, 'openSheet(')
        && !str_contains($presence, 'openSheet('),
    'Guards must not compete for rendering ownership, while v116 may close only a stale notification repaint.'
);

$assert(
    str_contains($main, 'state.stats = mergePresenceOnline(result.stats);')
        && substr_count($main, 'state.stats = mergePresenceOnline(result.stats);') === 2
        && str_contains($presence, 'window.__MGW_V115_PRESENCE_ONLINE__'),
    'Presence-owned online count must survive bootstrap and background stats polling.'
);

fwrite(STDOUT, "ProductionMvp14D1FeedbackIntegrationTest: {$assertions} assertions passed\n");
