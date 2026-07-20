<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/cutover/ProductionRuntimePrimaryContract.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$removeFixture = static function (string $path) use (&$removeFixture): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child)) $removeFixture($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$fixture = sys_get_temp_dir() . '/mgw-primary-contract-' . bin2hex(random_bytes(6));
mkdir($fixture . '/bot/handlers', 0700, true);
mkdir($fixture . '/bot/runtime', 0700, true);

try {
    file_put_contents(
        $fixture . '/bot/api.php',
        "<?php\n\$db = StorageFactory::createJson('/tmp/data');\n"
    );
    file_put_contents(
        $fixture . '/bot/handlers/WebhookHandler.php',
        "<?php\n\$db = StorageFactory::createJson('/tmp/data');\n"
    );

    $blocked = ProductionRuntimePrimaryContract::inspect($fixture);
    $assertTrue(($blocked['ready'] ?? true) === false, 'Direct JSON entrypoints must block production cutover');
    $assertTrue(count((array)($blocked['blockers'] ?? [])) >= 3, 'Blocked contract must explain missing coordinator wiring');
    $assertTrue(
        ($blocked['checks']['api']['direct_json_factory_absent'] ?? true) === false,
        'API direct JSON factory must remain explicit in the contract report'
    );
    $blockedFingerprint = (string)($blocked['contract_fingerprint'] ?? '');
    $assertTrue(
        preg_match('/^[a-f0-9]{64}$/', $blockedFingerprint) === 1,
        'Blocked contract must still produce a deterministic fingerprint'
    );

    file_put_contents(
        $fixture . '/bot/api.php',
        "<?php\n\$coordinator = new ProductionPrimaryRuntimeCoordinator();\n"
    );
    file_put_contents(
        $fixture . '/bot/handlers/WebhookHandler.php',
        "<?php\n\$coordinator = new ProductionPrimaryRuntimeCoordinator();\n"
    );
    file_put_contents(
        $fixture . '/bot/runtime/ProductionPrimaryRuntimeCoordinator.php',
        <<<'PHP'
<?php
final class ProductionPrimaryRuntimeCoordinator
{
    public const CONTRACT_VERSION = 'v1-db-primary-all-modules';
    public function executeApiRequest(array $payload): array { return []; }
    public function executeWebhookMutation(array $update): void {}
}
PHP
    );

    $ready = ProductionRuntimePrimaryContract::inspect($fixture);
    $assertTrue(($ready['ready'] ?? false) === true, 'Coordinator-backed entrypoints must satisfy the cutover contract');
    $assertTrue(($ready['blockers'] ?? ['unexpected']) === [], 'Ready contract must have no blockers');
    $assertTrue(
        ($ready['checks']['api']['coordinator_present'] ?? false) === true
            && ($ready['checks']['webhook_handler']['coordinator_present'] ?? false) === true,
        'Both production entrypoints must reference the DB-primary coordinator'
    );
    $assertTrue(
        ($ready['checks']['coordinator']['contract_ready'] ?? false) === true,
        'Coordinator must expose the exact versioned API and webhook contract'
    );
    $assertTrue(
        !hash_equals($blockedFingerprint, (string)($ready['contract_fingerprint'] ?? '')),
        'Contract fingerprint must change when DB-primary wiring becomes ready'
    );
} finally {
    $removeFixture($fixture);
}

fwrite(STDOUT, "ProductionRuntimePrimaryContractTest passed: {$assertions} assertions.\n");
