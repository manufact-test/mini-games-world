<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/storage/contracts/StorageTransactionInterface.php';
require_once $root . '/economy/UnifiedBalanceRuntimeState.php';
require_once $root . '/services/StagingAdminTestCoinGrantService.php';

if (!function_exists('make_id')) {
    function make_id(string $prefix): string { return $prefix . '_test_' . bin2hex(random_bytes(6)); }
}
if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate('c'); }
}

final class StagingAdminTestCoinMemoryStorage implements StorageTransactionInterface
{
    public function __construct(public array $data) {}

    public function transaction(callable $callback): mixed
    {
        $working = $this->data;
        $result = $callback($working);
        $this->data = $working;
        return $result;
    }

    public function readOnly(callable $callback): mixed
    {
        return $callback($this->data);
    }
}

$assertions = 0;
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};
$assertThrows = static function (callable $callback, string $message) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message . ': no exception was thrown');
};

$storage = new StagingAdminTestCoinMemoryStorage([
    'users' => [
        '972585905' => [
            'id' => '972585905',
            'telegram_id' => '972585905',
            'mgw_id' => 'MGW-TEST-OWNER',
            'username' => 'test_owner',
            'balance' => 18882,
        ],
    ],
    'transactions' => [],
]);
$service = new StagingAdminTestCoinGrantService(['environment' => 'staging'], $storage);
$token = 'admin-test-coins:1787600000000:972585905';
$first = $service->grant('@test_owner', 100000, 'telegram:972585905', 'Проверка игровой косметики', $token);
$assertSame(118882, $first['balance_after'], 'Staging grant must increase the canonical unified balance');
$assertSame(false, $first['replayed'], 'First staging grant must not be marked as replayed');
$assertSame(118882, $storage->data['users']['972585905']['balance'], 'Granted balance must be persisted in runtime state');
$assertSame('admin_test_coin_grant', $storage->data['transactions'][0]['category'] ?? null, 'Grant must append an auditable balance_change row');

$replay = $service->grant('972585905', 100000, 'telegram:972585905', 'Проверка игровой косметики', $token);
$assertSame(true, $replay['replayed'], 'Repeated request token must be idempotent');
$assertSame(118882, $storage->data['users']['972585905']['balance'], 'Idempotent replay must not double-credit the balance');
$assertSame(1, count($storage->data['transactions']), 'Idempotent replay must not append a second ledger row');

$assertThrows(
    static fn() => $service->grant('missing', 1000, 'telegram:972585905', 'Тест', 'admin-test-coins:1787600000001:972585905'),
    'Unknown player must fail closed'
);
$assertThrows(
    static fn() => $service->grant('972585905', 250001, 'telegram:972585905', 'Тест', 'admin-test-coins:1787600000002:972585905'),
    'Grant above the staging cap must fail closed'
);
$production = new StagingAdminTestCoinGrantService(['environment' => 'production'], $storage);
$assertThrows(
    static fn() => $production->grant('972585905', 1000, 'telegram:972585905', 'Тест', 'admin-test-coins:1787600000003:972585905'),
    'Production environment must reject test grants'
);

$endpoint = (string)file_get_contents($root . '/admin-test-coins.php');
$page = (string)file_get_contents(dirname($root) . '/app/admin.php');
$client = (string)file_get_contents(dirname($root) . '/app/assets/js/admin-shell.js');
$assertSame(true, str_contains($endpoint, 'AdminWebAuth::authorize') && str_contains($endpoint, "!== 'staging'"), 'Endpoint must require canonical admin auth and an exact staging guard');
$assertSame(true, str_contains($page, 'data-test-coins-api="../bot/admin-test-coins.php"') && str_contains($page, 'Пусто — начислить себе'), 'Web Admin must expose a clear self-top-up control');
$assertSame(true, str_contains($client, "action: 'grant_test_coins'") && !str_contains($client, 'localStorage'), 'Admin client must call the narrow endpoint without persistent browser auth state');

fwrite(STDOUT, "StagingAdminTestCoinGrantServiceTest: {$assertions} assertions passed\n");
