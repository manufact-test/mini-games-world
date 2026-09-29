<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $content;
};

$exists = static function (string $path) use ($root): bool {
    return is_file($root . '/' . $path);
};

$client = $read('app/assets/js/api/client.js');
$bootstrap = $read('bot/core/bootstrap.php');
$shopBridge = $read('bot/shop/ShopRuntimeBridge.php');
$paymentBridge = $read('bot/payments/PaymentRuntimeBridge.php');
$api = $read('bot/api.php');
$adminService = $read('bot/services/AdminService.php');
$paymentService = $read('bot/services/PaymentService.php');
$shopService = $read('bot/services/ShopService.php');
$terms = $read('site/legal/terms/index.html');
$routes = $read('.htaccess');
$russianPage = $read('site/russian-page.php');
$sitemap = $read('sitemap.xml');

$assert(
    !str_contains($client, 'paymentCreateDraft')
        && !str_contains($client, 'shopOrder:')
        && !str_contains($client, 'shopOrder('),
    'Active Mini App API client must not expose legacy payment/order write methods.'
);

$assert(
    !str_contains($bootstrap, '$runtimeShopBridge = new ShopRuntimeBridge')
        && !str_contains($bootstrap, '$runtimeShopBridge->synchronizeCurrentJson()')
        && !str_contains($bootstrap, '$runtimePaymentBridge->synchronizeCurrentJson()'),
    'Active bootstrap must not own legacy Shop/Payment JSON-to-DB synchronization.'
);

foreach ([
    'ShopRuntimeBridge' => $shopBridge,
    'PaymentRuntimeBridge' => $paymentBridge,
] as $name => $source) {
    $position = strpos($source, 'public function shouldSynchronizeApiAction');
    $assert($position !== false, $name . ' must keep an explicit synchronization gate.');
    $slice = substr($source, (int)$position, 420);
    $assert(
        str_contains($slice, 'return false;')
            && !str_contains($slice, "=== 'shop_order'")
            && !str_contains($slice, "=== 'payment_create_draft'"),
        $name . ' must remain archive-only for live API actions.'
    );
}

foreach (['payment_create_draft', 'shop_order'] as $action) {
    $position = strpos($api, "case '{$action}':");
    $assert($position !== false, "Legacy compatibility action {$action} must remain explicit.");
    $slice = substr($api, (int)$position, 1800);
    $assert(
        str_contains($slice, "'deprecated' => true")
            && str_contains($slice, "'saved' => false"),
        "Legacy compatibility action {$action} must reject new writes."
    );
}

$assert(
    str_contains($paymentService, 'Legacy commerce is archived')
        || str_contains($paymentService, 'archive')
        || str_contains($paymentService, 'read-only'),
    'PaymentService must retain an explicit archive/read-only boundary.'
);
$assert(
    str_contains($shopService, 'Legacy commerce is archived')
        || str_contains($shopService, 'archive')
        || str_contains($shopService, 'read-only'),
    'ShopService must retain an explicit archive/read-only boundary.'
);

foreach (['completeOrder', 'rejectOrder', 'applyPayment', 'rejectPayment', 'addGoldToUser'] as $compatMethod) {
    $position = strpos($adminService, 'public function ' . $compatMethod);
    $assert($position !== false, 'Archive compatibility method missing: ' . $compatMethod);
    $slice = substr($adminService, (int)$position, 280);
    $assert(
        str_contains($slice, 'UnifiedGameZonePolicy::legacyArchiveMessage()'),
        'Legacy Telegram Admin compatibility method must terminate at archive-only boundary: ' . $compatMethod
    );
}

foreach ([
    'bot/payments/RuntimePaymentRepository.php',
    'bot/shop/RuntimeShopRepository.php',
    'bot/ledger/LegacyFinancialArchiveImportService.php',
    'bot/ledger/LegacyFinancialArchiveDeltaService.php',
] as $archivePath) {
    $assert($exists($archivePath), 'Required historical/archive owner missing: ' . $archivePath);
}

$assert(
    str_contains($routes, 'blog/(?:gold-room|bot-and-match-coins)')
        && str_contains($routes, '/blog/ [R=301,L]'),
    'Retired public Gold/Match articles must redirect instead of publishing stale claims.'
);
$assert(
    !str_contains($russianPage, "'gold-room'")
        && !str_contains($russianPage, "'bot-and-match-coins'")
        && !str_contains($sitemap, '/blog/gold-room/')
        && !str_contains($sitemap, '/blog/bot-and-match-coins/'),
    'Retired public Gold/Match articles must not remain active routes or sitemap entries.'
);

$assert(
    str_contains($terms, 'единый баланс виртуальных MGW-коинов')
        && str_contains($terms, 'не подлежат выводу за реальные деньги')
        && str_contains($terms, 'have no cash value'),
    'Public terms must match the unified virtual-coin model and no-cash-value rule.'
);

foreach ([
    'bot/tests/Mvp24LegacyGoldClientSurfaceContractTest.php',
    'bot/tests/Mvp24MiniAppGoldStateCleanupContractTest.php',
    'bot/tests/Mvp24LegacyAdminCommerceReadOnlyContractTest.php',
    'bot/tests/Mvp24LegacyApiCommerceCleanupContractTest.php',
    'bot/tests/Mvp24PublicGoldSanitationContractTest.php',
] as $proof) {
    $assert($exists($proof), 'MVP-24 predecessor proof missing: ' . $proof);
}

fwrite(STDOUT, "Mvp24FinalGoldRemovalClosureContractTest: {$assertions} assertions passed\n");
