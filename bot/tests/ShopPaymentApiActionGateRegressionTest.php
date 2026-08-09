<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/storage/contracts/StorageAdapterInterface.php';
require_once $root . '/storage/RuntimeStorageRouter.php';
require_once $root . '/shop/ShopRuntimeBridge.php';
require_once $root . '/payments/PaymentRuntimeBridge.php';

$config = [
    'environment' => 'local',
    'storage_driver' => 'json',
    'database' => [
        'enabled' => true,
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'mgw_test',
        'user' => 'mgw_test',
        'password' => 'test-only',
        'charset' => 'utf8mb4',
    ],
    'feature_flags' => [
        'database_runtime' => [
            'enabled' => true,
            'modules' => [
                'accounts' => true,
                'economy' => true,
                'history' => true,
                'shop' => true,
                'payments' => true,
            ],
        ],
    ],
];

$shop = new ShopRuntimeBridge($config);
$payments = new PaymentRuntimeBridge($config);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

foreach (['bootstrap', 'profile', 'history', 'game_state', 'game_action', 'make_move', 'leave_game'] as $action) {
    $assert(!$shop->shouldSynchronizeApiAction($action), "Shop bridge must not run for {$action}.");
    $assert(!$payments->shouldSynchronizeApiAction($action), "Payment bridge must not run for {$action}.");
}

$assert($shop->shouldSynchronizeApiAction('shop_order'), 'Shop bridge must run for shop_order.');
$assert(!$shop->shouldSynchronizeApiAction('payment_create_draft'), 'Shop bridge must not run for payment_create_draft.');
$assert($payments->shouldSynchronizeApiAction('payment_create_draft'), 'Payment bridge must run for payment_create_draft.');
$assert(!$payments->shouldSynchronizeApiAction('shop_order'), 'Payment bridge must not run for shop_order.');

$GLOBALS['action'] = 'shop_order';
$assert($shop->shouldSynchronizeApiAction(''), 'Shop bridge empty bootstrap argument must resolve the real API action.');
$assert(!$payments->shouldSynchronizeApiAction(''), 'Payment bridge must reject a resolved shop_order action.');
$GLOBALS['action'] = 'payment_create_draft';
$assert(!$shop->shouldSynchronizeApiAction(''), 'Shop bridge must reject a resolved payment_create_draft action.');
$assert($payments->shouldSynchronizeApiAction(''), 'Payment bridge empty bootstrap argument must resolve the real API action.');
unset($GLOBALS['action']);

$gameResponse = [
    'user' => ['id' => 'u1'],
    'game' => ['id' => 'g1', 'status' => 'active'],
    'session' => ['active' => true],
];
$assert(
    $payments->normalizeApiData($gameResponse, 'game_state') === $gameResponse,
    'Unrelated game response must return before any payment repository read.'
);

fwrite(STDOUT, "ShopPaymentApiActionGateRegressionTest: {$assertions} assertions passed\n");
