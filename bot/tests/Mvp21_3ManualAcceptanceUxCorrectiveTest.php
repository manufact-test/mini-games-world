<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read source: ' . $path);
    return $content;
};

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$tournaments = $read('app/assets/js/screens/tournaments-screen-v1.js');
$admin = $read('app/assets/js/admin-tournaments.js');
$notifications = $read('app/assets/js/screens/notifications-screen-v110r13.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$bridge = $read('bot/tournaments/TournamentParticipantNotificationBridge.php');
$tournamentEndpoint = $read('bot/admin-tournaments.php');
$adminWebAuth = $read('bot/helpers/AdminWebAuth.php');
$page = $read('app/admin.php');

$assert(str_contains($tournaments, 'Начало турнира · по вашему времени'),
    'Player tournament card must label the start as device-local time.');
$assert(!str_contains($tournaments, "timeZoneName:'short'"),
    'Player tournament card must not append GMT/UTC offset jargon to the local clock.');
$assert(str_contains($admin, 'по времени этого устройства')
        && !str_contains($admin, "timeZoneName:'short'"),
    'Admin schedule copy must show local device time without GMT suffix.');

$assert(str_contains($bridge, 'ваше местное время')
        && !str_contains($bridge, "$start->format('d.m.Y H:i') . ' UTC'"),
    'New schedule-assigned bell copy must not expose canonical UTC as the participant clock.');

$assert(str_contains($notifications, 'localizeLegacyTournamentAssignedMessage')
        && str_contains($notifications, 'по вашему времени'),
    'Existing already-delivered UTC assignment notice must be localized client-side.');
$assert(str_contains($notifications, 'invalidateNotificationReads();')
        && substr_count($notifications, 'invalidateNotificationReads();') >= 3,
    'Notification mutations must invalidate older in-flight notification reads.');
$assert(str_contains($notifications, 'for (const [key, entry] of localAuthority.entries())')
        && str_contains($notifications, 'item:{ ...entry.item, read:true }'),
    'Local notification authority must advance to read state instead of re-inserting stale unread items.');

$assert(str_contains(
        $manifest,
        "./assets/js/screens/notifications-screen-v110r13.js?v=1163&mvp21_3=read-authority-local-time"
    ),
    'Active import map must publish the notification corrective under a fresh URL.');
$assert(str_contains($manifest, 'mvp21_3=schedule-local-time-v2'),
    'Active import map must publish the player local-time corrective.');

$assert(str_contains($admin, 'const PASSIVE_REFRESH_MS = 8000;')
        && str_contains($admin, "passive_refresh:true")
        && str_contains($admin, "document.addEventListener('visibilitychange'")
        && str_contains($admin, "window.addEventListener('focus'"),
    'Tournament Admin must refresh live while visible and immediately after returning to the WebView.');
$assert(str_contains($admin, "typeof telegram.showConfirm === 'function'")
        && str_contains($admin, 'await confirmAction(`Назначить старт турнира'),
    'Tournament schedule confirmation must use the Telegram-native confirmation path before browser fallback.');
$assert(str_contains($tournamentEndpoint, '$passiveRefresh')
        && str_contains($tournamentEndpoint, '&& !$passiveRefresh'),
    'Passive Tournament Admin refresh must not run scheduled-notification write reconciliation.');
$assert(str_contains($adminWebAuth, 'public const MAX_AGE_SECONDS = 4 * 60 * 60;'),
    'Web Admin authorization must remain usable for a practical live tournament administration session.');
$assert(str_contains($page, 'admin-tournaments.js?v=16')
        && str_contains($page, 'mvp21_manual=admin-ui-v2'),
    'Admin page must publish the corrected Tournament Admin client under a fresh cache-busting URL.');

if ($assertions < 14) {
    throw new RuntimeException('MVP-21.3 manual acceptance UX corrective coverage is incomplete.');
}

fwrite(STDOUT, "Mvp21_3ManualAcceptanceUxCorrectiveTest: {$assertions} assertions passed\n");
