<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$php = $read('app/v110.php');
$main = $read('app/assets/js/main-v110.js');
$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$clean = $read('app/assets/js/production-clean-entry-v110.js');
$presenceClient = $read('app/assets/js/production-v110-presence.js');
$stats = $read('app/assets/js/stats-owner-v110.js');
$entrypoints = $read('bot/runtime/ProductionPrimaryApplicationEntrypoints.php');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r12.js');
$terminal = $read('app/assets/js/games/invite-terminal-actions-v110r12.js');
$invites = $read('app/assets/js/games/game-invites-v110.js');
$lifecycle = $read('app/assets/js/production-v110-match-lifecycle.js');
$launch = $read('bot/helpers/WebAppLaunchUrl.php');
$welcome = $read('bot/helpers/UserWelcomeGuard.php');

$build = 'v110-mvp14r12-invite-notification-presence-stability';
$assert(str_contains($php, 'production-clean-entry-v110.js?v=1120')
    && str_contains($php, 'main-v110.js?v=1120')
    && str_contains($php, $build)
    && str_contains($main, "main-v110-handoff-shell.js?v=1120")
    && str_contains($main, $build)
    && str_contains($shell, $build)
    && str_contains($clean, $build),
    'Every active outer entry owner must publish one v1120 build identity.');
$assert(str_contains($launch, "private const ENTRY_PATH = '/app/v110.php?v=1120';")
    && str_contains($welcome, "Active canonical path: '/app/v110.php?v=1120'."),
    'Telegram menu, start and invite paths must all launch v1120.');
$assert(str_contains($shell, 'notifications-screen-v110r12.js?v=1120')
    && substr_count($shell, 'initNotificationsScreen();') === 1
    && str_contains($notifications, 'data-notifications-owner="r12"')
    && str_contains($notifications, 'sheetState.pinned')
    && str_contains($notifications, 'CLOSE_GUARD_MS = 1100'),
    'The clean release must keep one pinned notification owner with duplicate-open protection.');
$assert(str_contains($shell, 'invite-terminal-actions-v110r12.js?v=1120')
    && str_contains($terminal, 'const notificationSurface = isNotificationSurface(button);')
    && str_contains($terminal, 'closeSheet();')
    && !str_contains($terminal, 'Вы отклонили это приглашение.')
    && !str_contains($terminal, 'Вы отменили это приглашение.'),
    'Terminal invite actions must keep notification cards in place and close standalone sheets silently.');
$assert(!str_contains($shell, 'production-v110-opponent-picker-stability.js')
    && !str_contains($shell, 'initV110OpponentPickerStability')
    && !str_contains($invites, 'window.fetch ='),
    'The final release must not contain a global recent-opponent fetch wrapper.');
$assert(!str_contains($stats, 'ONLINE_DROP_GRACE_MS')
    && !str_contains($stats, 'pendingOnlineDrop')
    && str_contains($stats, 'if (sequence < runtime.applied) return false;'),
    'The final release must keep request ordering without UI count smoothing.');
$assert(str_contains($entrypoints, "'bot/presence.php' => 'presence'")
    && str_contains($presenceClient, "stats-owner-v110.js?v=1120")
    && str_contains($shell, 'production-v110-presence.js?v=1120')
    && str_contains($shell, 'stats-owner-v110.js?v=1120'),
    'Presence heartbeat and bootstrap statistics must share the guarded primary source and one stats module revision.');
$assert(str_contains($invites, 'tg.shareMessage(preparedId')
    && str_contains($invites, "String(errorCode || '') === 'USER_DECLINED'")
    && str_contains($invites, 'restoreWarmShareDraft(attempt);'),
    'Native editable Telegram sharing and silent cancellation reuse must remain unchanged.');
$homePosition = strpos($lifecycle, "showScreen('home');");
$requestPosition = strpos($lifecycle, 'const result = await api.leaveGame(String(snapshot.id));');
$assert($homePosition !== false && $requestPosition !== false && $homePosition < $requestPosition
    && !str_contains($lifecycle, 'openSheet('),
    'Manual game cancellation must still return home before the server release finishes.');

fwrite(STDOUT, "ProductionV110CleanR12ReleaseContractTest: {$assertions} assertions passed\n");
