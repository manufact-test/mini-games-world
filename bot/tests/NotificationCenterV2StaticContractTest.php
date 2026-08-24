<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'api' => 'bot/notifications.php',
    'repository' => 'bot/notifications/RuntimeNotificationRepository.php',
    'policy' => 'bot/notifications/NotificationCenterV2Policy.php',
    'client' => 'app/assets/js/screens/notifications-screen-v110r13.js',
    'shell' => 'app/assets/js/main-v110-handoff-shell.js',
    'css' => 'app/assets/css/screens/notifications-v2.css',
    'locale' => 'app/locales/ru.json',
];
$sources = [];
foreach ($files as $key => $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) throw new RuntimeException('Unable to read ' . $path);
    $sources[$key] = $source;
}

$assertions = 0;
$contains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (!str_contains($haystack, $needle)) throw new RuntimeException($message . ': missing ' . $needle);
};
$notContains = static function (string $needle, string $haystack, string $message) use (&$assertions): void {
    $assertions++;
    if (str_contains($haystack, $needle)) throw new RuntimeException($message . ': forbidden ' . $needle);
};

$contains("NotificationCenterV2Policy::eventId", $sources['api'], 'API must expose stable event identity through the v2 policy');
$contains("NotificationCenterV2Policy::isExpired", $sources['api'], 'API feed must enforce explicit expiry retention');
$contains("readNotificationId", $sources['api'], 'API must support read-one synchronization');
$contains("deleteNotificationId", $sources['api'], 'API must support delete-one synchronization');
$contains("NotificationCenterV2Policy::hideOne", $sources['api'], 'Delete-one must be a soft hidden_at mutation');
$contains("'expires_at' => \$this->nullableTimestamp", $sources['repository'], 'DB rollback projection must preserve expiry in payload parity');

$contains("notifications-screen-v110r13.js", $sources['shell'], 'Telegram shell must activate the v2 notification owner');
$notContains("notifications-screen-v110r12.js", $sources['shell'], 'Telegram shell must not load the old notification owner in parallel');
$contains("notificationCenterBlockedByMatch()", $sources['client'], 'Notification modal must be guarded from active matches');
$contains("data-notifications-mark-all", $sources['client'], 'Client must expose explicit mark-all control');
$contains("readNotificationId:String(options.readNotificationId", $sources['client'], 'Client must send read-one mutations');
$contains("deleteNotificationId:String(options.deleteNotificationId", $sources['client'], 'Client must send delete-one mutations');
$contains("const eventId = String(item?.event_id", $sources['client'], 'Client dedupe must use stable event IDs');
$contains('if (eventId) return `event:${eventId}`', $sources['client'], 'Stable event ID must own non-invite dedupe identity');
$contains("safeDeepLink", $sources['client'], 'Client deep links must be allow-listed');
$contains("['home','profile','store','store:orders','friends:requests']", $sources['client'], 'Client must reject arbitrary external notification links');
$contains("item.type === 'friend_request'", $sources['client'], 'Friend request events must own a dedicated review action');
$contains('Посмотреть', $sources['client'], 'Friend request notification must describe navigation instead of implying immediate acceptance');
$notContains('>Добавить в друзья</button>', $sources['client'], 'Friend request notification must not claim that navigation accepts the request');
$contains("item?.type !== 'friend_request'", $sources['client'], 'Resolved request cards must not survive a server reconciliation through local cache authority');
$contains("detail:{ tab:'requests' }", $sources['client'], 'Friend request action must open the canonical requests tab');
$contains("isRetainedItem", $sources['client'], 'Expired notification cache entries must be removed from active UI');
$notContains("void rawNotifications(true).catch", $sources['client'], 'Opening the center must not silently auto-mark all notifications');

$contains('.notification-card.unread', $sources['css'], 'Unread state must be visibly distinct');
$contains('notification-card-actions', $sources['css'], 'V2 card actions must have owned styling');

$locale = json_decode($sources['locale'], true, 512, JSON_THROW_ON_ERROR);
foreach (['title','mark_all','open','delete','empty','loading','item_fallback','load_error','try_again','open_center','unread_count'] as $key) {
    $assertions++;
    if (!is_string($locale['notifications'][$key] ?? null) || trim($locale['notifications'][$key]) === '') {
        throw new RuntimeException('Missing Notification Center localization key: notifications.' . $key);
    }
}

fwrite(STDOUT, "NotificationCenterV2StaticContractTest: {$assertions} assertions passed\n");
