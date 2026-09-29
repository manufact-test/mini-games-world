<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read MVP-24.3b source: ' . $path);
    return $content;
};
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$api = $read('bot/api.php');
$payments = $read('bot/services/PaymentService.php');
$shop = $read('bot/services/ShopService.php');
$policy = $read('bot/runtime/UnifiedGameZonePolicy.php');
$client = $read('app/assets/js/api/client.js');
$bootstrap = $read('bot/core/bootstrap.php');
$shopBridge = $read('bot/shop/ShopRuntimeBridge.php');
$paymentBridge = $read('bot/payments/PaymentRuntimeBridge.php');

foreach (['payment_plans', 'payment_create_draft', 'shop_order'] as $compatAction) {
    $assert(
        str_contains($api, "case '" . $compatAction . "':"),
        'Legacy API alias must remain as an explicit compatibility boundary: ' . $compatAction
    );
}

$assert(
    !str_contains($api, '$payments->createDraftFromAmount(')
        && !str_contains($api, '$payments->createDraft(')
        && !str_contains($api, '$shop->createOrder(')
        && !str_contains($api, '$shop->createCatalogOrder('),
    'Legacy API aliases must never call old commerce write services.'
);

$assert(
    !str_contains($api, '$payments->plans()')
        && !str_contains($api, 'notifyAdminsAboutPayment')
        && !str_contains($api, '$telegram = new TelegramService($config);'),
    'Legacy API must not advertise old payment plans or keep payment-notification write plumbing.'
);

$assert(
    substr_count($api, "'deprecated' => true") >= 2
        && str_contains($api, "'saved' => false")
        && str_contains($api, 'UnifiedGameZonePolicy::legacyArchiveMessage()'),
    'Legacy mutation aliases must terminate in explicit archive-only compatibility responses.'
);

$assert(
    str_contains($api, "'payments' => \$payments->status(\$data, \$user)")
        && str_contains($api, "'shop' => \$shop->status(\$user)"),
    'Compatibility responses must expose only existing read-only archive status.'
);

foreach (['history', 'shop_status', 'payment_status'] as $readAction) {
    $assert(
        str_contains($api, "case '" . $readAction . "':"),
        'Historical/read-only API surface must remain available: ' . $readAction
    );
}

$assert(
    str_contains($payments, "'mode' => 'archive_read_only'")
        && str_contains($shop, "'mode' => 'archive_read_only'")
        && str_contains($policy, 'Legacy Match/Gold операции доступны только для просмотра'),
    'Legacy service status must remain explicitly archive-only.'
);

foreach (['createDraftFromAmount', 'createDraft'] as $method) {
    $position = strpos($payments, 'public function ' . $method);
    $assert($position !== false, 'Payment compatibility method must remain callable: ' . $method);
    $slice = substr($payments, (int)$position, 420);
    $assert(
        str_contains($slice, 'UnifiedGameZonePolicy::rejectLegacyCommerceWrite();'),
        'Payment compatibility method must reject writes: ' . $method
    );
}

foreach (['createOrder', 'createCatalogOrder'] as $method) {
    $position = strpos($shop, 'public function ' . $method);
    $assert($position !== false, 'Shop compatibility method must remain callable: ' . $method);
    $slice = substr($shop, (int)$position, 700);
    $assert(
        str_contains($slice, 'UnifiedGameZonePolicy::rejectLegacyCommerceWrite();'),
        'Shop compatibility method must reject writes: ' . $method
    );
}

$assert(
    !str_contains($bootstrap, '$runtimeShopBridge = new ShopRuntimeBridge')
        && !str_contains($bootstrap, '$runtimeShopBridge->synchronizeCurrentJson()')
        && !str_contains($bootstrap, '$runtimePaymentBridge->synchronizeCurrentJson()'),
    'Live bootstrap must not own legacy Shop/Payment JSON-to-DB synchronization.'
);

$assert(
    str_contains($bootstrap, '$runtimePaymentBridge->normalizeApiData('),
    'Read-only payment archive projection must remain available without a write hook.'
);

foreach ([
    'shop' => $shopBridge,
    'payment' => $paymentBridge,
] as $bridgeName => $bridgeSource) {
    $position = strpos($bridgeSource, 'public function shouldSynchronizeApiAction');
    $assert($position !== false, ucfirst($bridgeName) . ' bridge synchronization gate must remain explicit.');
    $slice = substr($bridgeSource, (int)$position, 420);
    $assert(
        str_contains($slice, 'return false;')
            && !str_contains($slice, "=== 'shop_order'")
            && !str_contains($slice, "=== 'payment_create_draft'"),
        ucfirst($bridgeName) . ' bridge must never synchronize a live legacy commerce API action.'
    );
}

$assert(
    !str_contains($client, 'paymentCreateDraft')
        && !str_contains($client, 'shopOrder:')
        && !str_contains($client, 'shopStatus:')
        && str_contains($client, 'cosmeticStorePurchase:'),
    'Active Mini App client must stay detached from legacy commerce while modern cosmetics Store remains active.'
);

foreach ([
    'bot/ledger/LegacyFinancialArchiveImportService.php',
    'bot/ledger/LegacyFinancialArchiveDeltaService.php',
    'ops/ledger/LEGACY_FINANCIAL_ARCHIVE.md',
] as $archivePath) {
    $assert(is_file($root . '/' . $archivePath), 'Historical financial archive must be preserved: ' . $archivePath);
}

fwrite(STDOUT, "Mvp24LegacyApiCommerceCleanupContractTest: {$assertions} assertions passed\n");
