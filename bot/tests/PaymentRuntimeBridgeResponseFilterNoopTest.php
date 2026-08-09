<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/database/DatabaseConfig.php';
require_once $root . '/storage/RuntimeStorageRouter.php';
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
                'realtime' => true,
                'economy' => true,
                'history' => true,
                'payments' => true,
            ],
        ],
    ],
];

$bridge = new PaymentRuntimeBridge($config);
$input = [
    'user' => ['id' => 'u1'],
    'game' => ['id' => 'g1', 'status' => 'active'],
    'session' => ['active' => true],
];

// No payment/topup projection is requested. This must return before repository()
// can try to open a real PDO connection; the test therefore proves the no-op
// behavior without mocking or weakening the payment repository contract.
$result = $bridge->normalizeApiData($input, 'game_state');
if ($result !== $input) {
    throw new RuntimeException('Unrelated API response must remain unchanged by payment normalization.');
}

$source = file_get_contents($root . '/payments/PaymentRuntimeBridge.php');
if (!is_string($source)) throw new RuntimeException('Cannot read PaymentRuntimeBridge source.');
$guardPos = strpos($source, 'if (!$hasPayments && !$hasTopups) return $data;');
$readPos = strpos($source, '$this->repository()->paymentRecords()');
if ($guardPos === false || $readPos === false || $guardPos >= $readPos) {
    throw new RuntimeException('Payment response-shape guard must precede payment DB reads.');
}

fwrite(STDOUT, "PaymentRuntimeBridgeResponseFilterNoopTest: 2 assertions passed\n");
