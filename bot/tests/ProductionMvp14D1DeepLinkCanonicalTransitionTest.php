<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$entry = file_get_contents($root . '/app/assets/js/games/invite-link-entry-v115.js');
if (!is_string($notifications) || !is_string($entry)) throw new RuntimeException('Missing deep-link canonical sources.');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void { $assertions++; if (!$condition) throw new RuntimeException($message); };
$assert(str_contains($entry, "publishInviteLinkLifecycle('mgw:invite-link-opening', token, false)")
    && str_contains($entry, "publishInviteLinkLifecycle('mgw:invite-link-resolved', token, opened)")
    && str_contains($entry, 'detail:{ item, unreadCount, announce:false }'),
    'Invite entry must publish one explicit silent lifecycle.');
$assert(str_contains($notifications, 'let silentInviteToken = incomingInviteToken()')
    && str_contains($notifications, 'isSilentInviteNotification(item)')
    && str_contains($notifications, 'rememberNotificationId(id)')
    && str_contains($notifications, 'dismissNotificationToast()'),
    'Canonical notification owner must consume the matching notification silently.');
$assert(!str_contains($notifications, '__MGW_INVITE_LINK_OPENING__')
    && !str_contains($notifications, 'MutationObserver')
    && !str_contains($notifications, 'setInterval(() => {\n    hideToastNow')
    && !str_contains($notifications, 'visibility:hidden!important'),
    'Deep-link behavior must not use a global flag, DOM observer, polling hider or CSS patch.');
fwrite(STDOUT, "ProductionMvp14D1DeepLinkCanonicalTransitionTest: {$assertions} assertions passed\n");
