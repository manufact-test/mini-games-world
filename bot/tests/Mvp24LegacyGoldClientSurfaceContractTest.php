<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read MVP-24 source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$shell = $read('app/assets/js/main-v110-handoff-shell.js');
$home = $read('app/assets/js/screens/home-screen.js');
$mainCss = $read('app/assets/css/main.css');
$store = $read('app/assets/js/screens/store-screen.js');
$api = $read('app/assets/js/api/client.js');
$server = $read('bot/api.php');

$assert(
    !str_contains($shell, "screens/store-order.js")
        && !str_contains($shell, "screens/store-orders.js")
        && !str_contains($shell, 'initStoreOrder(')
        && !str_contains($shell, 'initStoreOrders('),
    'Active Mini App shell must not import or initialize the legacy Gold certificate/order UI.'
);
$assert(
    !str_contains($mainCss, 'screens/store-order.css')
        && !str_contains($mainCss, 'screens/store-orders.css')
        && !is_file($root . '/app/assets/js/screens/store-order.js')
        && !is_file($root . '/app/assets/js/screens/store-orders.js')
        && !is_file($root . '/app/assets/css/screens/store-order.css')
        && !is_file($root . '/app/assets/css/screens/store-orders.css'),
    'Legacy Gold order JS/CSS owners must be absent from the active client tree.'
);
$assert(
    !str_contains($home, 'result.topups')
        && !str_contains($home, 'data-history-tab="topups"')
        && !str_contains($home, "?'Match':'Gold'")
        && !str_contains($home, 'amount_rub'),
    'Player balance history must no longer expose legacy RUB/Match/Gold top-up history.'
);
$assert(
    str_contains($store, 'cosmeticStoreStatus')
        && str_contains($store, 'cosmeticStorePurchase')
        && str_contains($api, 'cosmeticStoreStatus')
        && str_contains($api, 'cosmeticStorePurchase'),
    'Modern cosmetics Store ownership must remain intact.'
);
$assert(
    str_contains($server, "case 'payment_create_draft':")
        && str_contains($server, "case 'shop_order':")
        && substr_count($server, 'UnifiedGameZonePolicy::rejectLegacyCommerceWrite();') >= 2,
    'Server must continue rejecting legacy commerce writes while client surfaces are removed.'
);

fwrite(STDOUT, "Mvp24LegacyGoldClientSurfaceContractTest: {$assertions} assertions passed\n");
