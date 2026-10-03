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

$notifications = $read('app/assets/js/screens/notifications-screen-v110r13.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$manifestData = require $root . '/app/runtime/client/version-manifest.php';

$assert(
    !str_contains($notifications, "import { openStoreOrders } from './store-orders.js?v=36';"),
    'Notifications must not import retired legacy Store orders UI.'
);
$assert(
    !str_contains($notifications, 'openStoreOrders()'),
    'Notifications must not invoke retired legacy Store orders UI.'
);
$assert(
    str_contains($notifications, "if (link === 'store:orders') return 'store';"),
    'Historical store:orders deep links must converge to the current Store.'
);
$assert(
    !file_exists($root . '/app/assets/js/screens/store-orders.js'),
    'Retired legacy Store orders module must stay absent.'
);
$notificationTarget = (string)($manifestData['imports']['./assets/js/screens/notifications-screen-v110r13.js?v=1162&mvp18=friend-request-lifecycle'] ?? '');
$notificationVersion = [];
$assert(
    preg_match('~notifications-screen-v110r13\\.js\\?v=(\\d+)&mvp21_3=read-authority-local-time~', $notificationTarget, $notificationVersion) === 1
    && (int)$notificationVersion[1] >= 1166
    && str_contains($notificationTarget, 'mvp25_2=legacy-store-orders-retired-v1'),
    'Canonical manifest must publish the accepted-or-newer Notification Center identity while retaining the legacy Store-order retirement marker.'
);

fwrite(STDOUT, "Mvp25_2NotificationLegacyOrderDeepLinkContractTest: {$assertions} assertions passed\n");
