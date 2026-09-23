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

if ($assertions < 9) {
    throw new RuntimeException('MVP-21.3 manual acceptance UX corrective coverage is incomplete.');
}

fwrite(STDOUT, "Mvp21_3ManualAcceptanceUxCorrectiveTest: {$assertions} assertions passed\n");
