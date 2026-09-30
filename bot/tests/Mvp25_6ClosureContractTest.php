<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};

$service = $read('bot/catalog/CosmeticStoreService.php');
$store = $read('app/assets/js/screens/store-screen.js');
$manifest = $read('app/runtime/client/version-manifest.php');
$doc = $read('docs/MVP25_6_MONETIZATION_DISABLED_COMPLETE_PRODUCT.md');

$assert(
    str_contains($service, 'private const EXTERNAL_BILLING_AVAILABLE = false;'),
    'MVP-25.6 closure requires external billing to remain disabled.'
);
$assert(
    str_contains($service, "['id' => 'coins', 'label' => 'Коины', 'available' => \$billingAvailable]"),
    'Store projection must publish coin top-up availability from the disabled billing owner.'
);
$assert(
    str_contains($service, "if (\$billingAvailable) {")
        && str_contains($service, "'packages' => \$packages")
        && str_contains($service, "'billing_available' => \$billingAvailable"),
    'Coin package catalogue must remain absent while billing is disabled.'
);
$assert(
    str_contains($store, "{ id:'coins', label:'Коины', available:false }"),
    'Cold Store shell must hide monetization before API hydration.'
);
$assert(
    str_contains($store, '.filter(tab => tab.available !== false);'),
    'Hydrated Store must hide server-disabled tabs.'
);
$assert(
    !str_contains($store, 'Скоро'),
    'Closed MVP-25.6 Store must contain no coming-soon monetization promise.'
);
$assert(
    str_contains($store, "return emptyState('Пополнение коинов недоступно');"),
    'Defensive coin surface must remain a finished disabled state.'
);
$assert(
    str_contains($manifest, './assets/js/screens/store-screen.js?v=69&intent_base=1')
        && str_contains($manifest, 'mvp25_6=monetization-disabled-complete-v1'),
    'Canonical v110 manifest must deliver the accepted MVP-25.6 Store identity.'
);
$assert(
    str_contains($doc, '**Status:** CLOSED / AUTOMATED + STAGING ACCEPTED / FROZEN'),
    'MVP-25.6 authoritative document must be closed.'
);
$assert(
    str_contains($doc, '822c45d7db2ccb327925fa66fd0376dd05ecdcc8'),
    'MVP-25.6 closure must record the exact accepted staging SHA.'
);
$assert(
    str_contains($doc, 'Staging Playwright E2E')
        && str_contains($doc, '36682313363')
        && str_contains($doc, 'SUCCESS'),
    'MVP-25.6 closure must record the exact post-merge staging proof.'
);
$assert(
    str_contains($doc, 'Real-money purchasing remains intentionally **not implemented** in MVP-25.'),
    'Closure must not reinterpret MVP-25.6 as a real-money integration.'
);

fwrite(STDOUT, "Mvp25_6ClosureContractTest: {$assertions} assertions passed\n");
