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
$assert(
    str_contains(
        $manifest,
        './assets/js/screens/notifications-screen-v110r13.js?v=1166&mvp21_3=read-authority-local-time&mvp22_1=support-ticket-deeplink-smooth-v2&mvp25_2=legacy-store-orders-retired-v1'
    ),
    'Canonical manifest must publish the fresh notification identity.'
);

fwrite(STDOUT, "Mvp25_2NotificationLegacyOrderDeepLinkContractTest: {$assertions} assertions passed\n");
