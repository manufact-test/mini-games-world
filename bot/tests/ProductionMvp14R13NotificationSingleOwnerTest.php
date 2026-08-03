<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$canonical = file_get_contents($root . '/app/assets/js/screens/notifications-screen.js');
$prewarm = file_get_contents($root . '/app/assets/js/first-interaction-readiness.js');
if (!is_string($main) || !is_string($canonical) || !is_string($prewarm)) {
    throw new RuntimeException('Missing notification ownership source.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$canonicalInit = strpos($main, 'initNotificationsScreen();');
$prewarmInit = strpos($main, 'initFirstInteractionReadinessEarly();');
$assert($canonicalInit !== false && $prewarmInit !== false && $canonicalInit < $prewarmInit,
    'The canonical notifications capture listener must register before the generic prewarm listener.');

$assert(str_contains($main, "./screens/notifications-screen.js?v=86")
    && str_contains($main, "./first-interaction-readiness.js?v=93")
    && str_contains($main, "window.__MGW_BUILD__ = 'v97-mvp14-notification-single-owner'"),
    'The notification ownership fix must use new CDN identities and a visible build marker.');

$assert(str_contains($canonical, "const trigger = event.target.closest('#notificationsOpen')")
    && str_contains($canonical, 'event.stopImmediatePropagation();')
    && str_contains($canonical, 'openNotificationsSheet();'),
    'The canonical screen must own the notifications click and stop later capture listeners.');

$assert(str_contains($canonical, 'const result = await api.notifications(true);')
    && str_contains($canonical, 'renderNotifications(items);')
    && str_contains($canonical, 'data-invite-action=')
    && str_contains($canonical, 'data-invite-token='),
    'The canonical owner must fetch the fresh marked-read list and render invitation actions.');

$assert(str_contains($prewarm, "if (target.id === 'notificationsOpen' && notificationsSnapshot)")
    && str_contains($prewarm, 'renderNotificationsSheet(notificationsSnapshot.items || [])'),
    'The legacy prewarm path remains detectable but must be unreachable for a notifications click after canonical registration.');

$assert(substr_count($main, 'initNotificationsScreen();') === 1
    && substr_count($main, 'initFirstInteractionReadinessEarly();') === 1,
    'Each notification/prewarm initializer must run exactly once.');

$assert(!str_contains($main, 'lemonchiffon-gerbil-545102.hostingersite.com')
    && !str_contains($main, 'mini-games-world.com'),
    'The UI ownership fix must not introduce a production target.');

fwrite(STDOUT, "ProductionMvp14R13NotificationSingleOwnerTest: {$assertions} assertions passed\n");
