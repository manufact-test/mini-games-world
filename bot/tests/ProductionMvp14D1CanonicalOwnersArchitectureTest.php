<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Missing architecture source: ' . $path);
    return $content;
};
$entry = $read('app/v114.php');
$main = $read('app/assets/js/main.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v99.js');
$home = $read('app/assets/js/screens/home-screen.js');
$readiness = $read('app/assets/js/first-interaction-readiness.js');
$invites = $read('app/assets/js/games/game-invites.js');
$inviteLink = $read('app/assets/js/games/invite-link-entry-v115.js');
$endpoint = $read('bot/invite-opponents.php');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$retired = [
    'app/assets/js/notification-deeplink-toast-policy-v131.js',
    'app/assets/js/notification-compat-click-guard-v127.js',
    'app/assets/js/screens/notification-window-owner-v121.js',
    'app/assets/js/screens/notifications-passive-v130.js',
    'app/assets/js/opponents-native-fetch-v115.js',
    'app/assets/js/opponents-empty-cache-guard-v115.js',
    'app/assets/js/opponents-authoritative-confirm-v122.js',
    'app/assets/js/opponents-fresh-user-action-v128.js',
    'app/assets/js/first-interaction-readiness-v103.js',
];
foreach ($retired as $file) $assert(!is_file($root . '/' . $file), 'Retired patch file still exists: ' . $file);
$assert(str_contains($entry, './assets/js/main.js?v=d1-bell-single-owner')
    && str_contains($entry, 'X-MGW-Frontend-Build: d1-bell-single-owner')
    && str_contains($entry, 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'),
    'Staging entry must publish the canonical no-cache graph.');
foreach (['notification-compat-click-guard', 'notification-window-owner', 'notifications-passive',
          'notification-deeplink-toast-policy', 'opponents-native-fetch', 'opponents-empty-cache-guard',
          'opponents-authoritative-confirm', 'opponents-fresh-user-action'] as $name) {
    $assert(!str_contains($entry, $name) && !str_contains($main, $name), 'Active graph still names retired layer: ' . $name);
}
$assert(substr_count($main, "./screens/notifications-screen-v99.js?v=d1-bell-single-owner") === 1
    && substr_count($main, 'initNotificationsScreen();') === 1,
    'Main must initialize one canonical notification owner.');
$assert(substr_count($notifications, "document.addEventListener('click', handleNotificationBellActivation, true)") === 1
    && substr_count($notifications, "document.addEventListener('click', handleNotificationToastActivation)") === 1
    && !str_contains($notifications, 'handleNotificationActivation')
    && !str_contains($home, "target.id === 'notificationsOpen'")
    && !str_contains($notifications, "window.addEventListener('pointerdown'")
    && !str_contains($notifications, "window.addEventListener('pointerup'")
    && !str_contains($notifications, 'MutationObserver')
    && str_contains($notifications, "let sheetState = 'closed'")
    && str_contains($notifications, 'let sheetGeneration = 0')
    && str_contains($notifications, 'let notificationToastGeneration = 0')
    && str_contains($notifications, 'generation !== notificationToastGeneration')
    && str_contains($notifications, 'notificationToastGeneration += 1')
    && str_contains($notifications, 'openNotificationsShell()')
    && str_contains($notifications, 'data-notifications-body'),
    'Notifications must own one click path and one explicit sheet state machine.');
$assert(str_contains($notifications, "document.addEventListener('mgw:invite-link-opening'")
    && str_contains($notifications, "document.addEventListener('mgw:invite-link-resolved'")
    && str_contains($notifications, 'event.detail?.announce !== false'),
    'Deep-link silence must be an explicit canonical transition.');
$assert(!str_contains($readiness, 'window.fetch =')
    && !str_contains($readiness, 'invite-opponents.php')
    && !str_contains($readiness, 'data-create-link-invite')
    && !str_contains($readiness, 'create_link_draft')
    && !str_contains($readiness, 'openTelegramShare'),
    'Readiness may not own opponents transport or Share.');
$assert(substr_count($invites, 'postJson(OPPONENTS_URL') === 1
    && str_contains($invites, "cache:'no-store'")
    && str_contains($invites, "result?.authoritative !== true")
    && str_contains($invites, 'new AbortController()')
    && str_contains($invites, 'data-player-picker-body')
    && str_contains($invites, 'data-player-picker-state="loading"')
    && str_contains($invites, 'data-player-picker-state="loaded"')
    && str_contains($invites, 'data-player-picker-state="empty"')
    && str_contains($invites, 'data-player-picker-state="error"')
    && !str_contains($invites, 'window.fetch ='),
    'Player picker must own one fresh request and one explicit UI state machine.');
$assert(str_contains($endpoint, 'new DatabasePrimaryStateStorageAdapter(')
    && str_contains($endpoint, '$storage->readOnly(')
    && str_contains($endpoint, "'authoritative' => true")
    && str_contains($endpoint, "'storage_driver' => \$storage->driver()"),
    'Opponent endpoint must remain the authoritative DB-primary reader.');
$assert(str_contains($inviteLink, "publishInviteLinkLifecycle('mgw:invite-link-opening'")
    && str_contains($inviteLink, "publishInviteLinkLifecycle('mgw:invite-link-resolved'")
    && str_contains($inviteLink, 'announce:false'),
    'Invite-link entry must publish explicit silent lifecycle intent.');
fwrite(STDOUT, "ProductionMvp14D1CanonicalOwnersArchitectureTest: {$assertions} assertions passed\n");
