<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$main = file_get_contents($root . '/app/assets/js/main.js');
$entry = file_get_contents($root . '/app/v114.php');
$profile = file_get_contents($root . '/app/assets/js/screens/profile-screen.js');
$home = file_get_contents($root . '/app/assets/js/screens/home-screen.js');
$notifications = file_get_contents($root . '/app/assets/js/screens/notifications-screen-v99.js');
$invites = file_get_contents($root . '/app/assets/js/games/game-invites.js');

foreach (compact('main', 'entry', 'profile', 'home', 'notifications', 'invites') as $name => $source) {
    if (!is_string($source)) throw new RuntimeException("Cannot read {$name} source.");
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($main, "window.__MGW_BUILD__ = 'd1-bootstrap-authoritative-owner';"),
    'Unversioned app must publish the bootstrap-owned frontend build.');
$assert(str_contains($entry, './assets/js/main.js?v=d1-bootstrap-authoritative-owner')
    && str_contains($entry, 'X-MGW-Frontend-Build: d1-bootstrap-authoritative-owner'),
    'v121 reference entry must cache-bust and identify the bootstrap-owned build.');

$assert(str_contains($main, "import { initFirstInteractionReadinessEarly } from './first-interaction-readiness.js?v=d1';")
    && !str_contains($main, 'warmFirstInteractionData'),
    'Historical first-interaction warm-up must not remain a startup/preloader owner.');

$bootstrap = strpos($main, 'const result = await api.bootstrap();');
$ready = strpos($main, 'dispatchAppReady();', $bootstrap ?: 0);
$incoming = strpos($main, 'await openIncomingInviteFromTelegram();', $ready ?: 0);
$hide = strpos($main, 'hidePreloader();', $incoming ?: 0);
$assert($bootstrap !== false && $ready !== false && $incoming !== false && $hide !== false
    && $bootstrap < $ready && $ready < $incoming && $incoming < $hide,
    'Bootstrap and authoritative entry routing must complete before the global preloader is released.');

$assert(str_contains($profile, 'showProfileImmediately();')
    && strpos($profile, 'showProfileImmediately();') < strpos($profile, 'await Promise.all(['),
    'Profile first click must own its immediate surface before network refresh.');
$assert(str_contains($home, '<div class="small-note">Загружаем историю…</div>')
    && str_contains($home, '<div class="small-note">Загружаем матчи…</div>')
    && str_contains($home, 'const result = await api.history();'),
    'History first clicks must own visible loading states without a global readiness barrier.');
$assert(str_contains($notifications, 'openNotificationsShell();')
    && strpos($notifications, 'openNotificationsShell();') < strpos($notifications, 'const result = await api.notifications(true);'),
    'Notifications must own their loading shell before authoritative refresh.');
$assert(str_contains($invites, "renderPlayerPickerState('loading', [], context);")
    && strpos($invites, "renderPlayerPickerState('loading', [], context);") < strpos($invites, 'const result = await postJson(OPPONENTS_URL'),
    'Player picker must own its loading surface before the authoritative opponents request.');

$assert(!str_contains($main, 'setTimeout(')
    && !str_contains($main, 'Promise.race(')
    && !str_contains($main, 'AbortController'),
    'Startup ownership fix must not introduce a timing/retry/abort workaround.');

fwrite(STDOUT, "ProductionV121BootstrapPreloaderOwnershipContractTest: {$assertions} assertions passed\n");
