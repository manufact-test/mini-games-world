<?php
declare(strict_types=1);

if (!class_exists('ProductionCutoverRunner', false)) {
    final class ProductionCutoverRunner
    {
        public const BUILD = 'v103-mvp14-production-cutover';
        public const PACKAGE_VERSION = 'v1-mvp14-10e-cutover-recovery-package';
    }
}
if (!class_exists('ProductionCutoverReleaseSmokeService', false)) {
    final class ProductionCutoverReleaseSmokeService
    {
        public const CONTRACT_VERSION = 'v1-production-cutover-release-smoke';
    }
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/cutover/ProductionCutoverReleaseReceiptVerifier.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $needle) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($needle))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};
$write = static function (string $path, array $payload): void {
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json) || !chmod($path, 0600)) {
        throw new RuntimeException('Receipt fixture could not be written safely.');
    }
};

$root = sys_get_temp_dir() . '/mgw-release-receipt-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true) || !chmod($root, 0700)) {
    throw new RuntimeException('Receipt fixture directory could not be created.');
}
$file = $root . '/production-cutover-release-receipt.json';
$now = 1_800_000_000;
$plan = str_repeat('a', 64);
$source = str_repeat('b', 64);
$package = str_repeat('c', 64);
$runtimeContract = str_repeat('d', 64);
$databaseIdentity = str_repeat('e', 64);
$stateSha = str_repeat('1', 64);
$outbox = str_repeat('2', 64);
$allModules = str_repeat('3', 64);
$commit = str_repeat('f', 40);
$receipt = [
    'contract_version' => ProductionCutoverReleaseReceiptVerifier::CONTRACT_VERSION,
    'smoke_contract_version' => ProductionCutoverReleaseSmokeService::CONTRACT_VERSION,
    'ready' => true,
    'environment' => 'production',
    'build' => ProductionCutoverRunner::BUILD,
    'package_version' => ProductionCutoverRunner::PACKAGE_VERSION,
    'release_commit' => $commit,
    'package_fingerprint' => $package,
    'runtime_contract_fingerprint' => $runtimeContract,
    'plan_fingerprint' => $plan,
    'source_fingerprint' => $source,
    'database_identity_fingerprint' => $databaseIdentity,
    'cutover_state' => 'awaiting_release',
    'health_probe' => 'internal_cli_equivalent',
    'health_http_status' => 200,
    'health_ok' => true,
    'database_connected' => true,
    'schema_current' => true,
    'pending_migrations' => 0,
    'enabled_module_count' => 9,
    'read_only_api_smoke' => true,
    'all_module_regression' => true,
    'json_snapshot_unchanged' => true,
    'maintenance_enabled' => true,
    'financial_read_only' => true,
    'json_write_block_active' => true,
    'state_revision' => 1,
    'state_sha256' => $stateSha,
    'outbox_fingerprint' => $outbox,
    'all_module_fingerprint' => $allModules,
    'database_write_executed' => false,
    'persistent_config_changed' => false,
    'webhook_changed' => false,
    'cron_changed' => false,
    'production_changed' => false,
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
    'expires_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 300),
];
$state = [
    'plan_fingerprint' => $plan,
    'source_fingerprint' => $source,
];
$manifest = [
    'release_commit' => $commit,
    'package_fingerprint' => $package,
];
$contract = ['contract_fingerprint' => $runtimeContract];

try {
    $write($file, $receipt);
    $report = (new ProductionCutoverReleaseReceiptVerifier())->verify(
        $file,
        $state,
        $manifest,
        $contract,
        $databaseIdentity,
        $now
    );
    $assertTrue(($report['ready'] ?? false) === true, 'Exact fresh release receipt must pass');
    $assertTrue(($report['blockers'] ?? ['unexpected']) === [], 'Passing receipt must have no blockers');
    $assertTrue(
        preg_match('/\A[a-f0-9]{64}\z/', (string)($report['receipt_fingerprint'] ?? '')) === 1,
        'Receipt must expose exact SHA-256 fingerprint'
    );
    $assertTrue(($report['database_contacted'] ?? true) === false, 'Receipt verification must be offline');
    $assertTrue(($report['production_changed'] ?? true) === false, 'Receipt verification must be read-only');

    $bad = $receipt;
    $bad['enabled_module_count'] = 8;
    $write($file, $bad);
    $blocked = (new ProductionCutoverReleaseReceiptVerifier())->verify(
        $file,
        $state,
        $manifest,
        $contract,
        $databaseIdentity,
        $now
    );
    $assertTrue(($blocked['ready'] ?? true) === false, 'Eight-module receipt must block release');
    $assertTrue(
        in_array('release receipt check failed: all_nine_modules_exact', (array)($blocked['blockers'] ?? []), true),
        'Module blocker must be explicit'
    );

    $stale = $receipt;
    $stale['generated_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now - 901);
    $stale['expires_at_utc'] = gmdate('Y-m-d\TH:i:s\Z', $now + 1);
    $write($file, $stale);
    $staleReport = (new ProductionCutoverReleaseReceiptVerifier())->verify(
        $file,
        $state,
        $manifest,
        $contract,
        $databaseIdentity,
        $now
    );
    $assertTrue(($staleReport['ready'] ?? true) === false, 'Stale release receipt must block');

    $write($file, $receipt);
    if (!chmod($file, 0644)) throw new RuntimeException('Could not weaken fixture mode.');
    $assertThrows(
        static fn() => (new ProductionCutoverReleaseReceiptVerifier())->verify(
            $file,
            $state,
            $manifest,
            $contract,
            $databaseIdentity,
            $now
        ),
        'exact mode 0600'
    );
} finally {
    if (is_file($file) || is_link($file)) {
        if (!unlink($file)) throw new RuntimeException('Receipt fixture could not be removed.');
    }
    if (is_dir($root) && !rmdir($root)) {
        throw new RuntimeException('Receipt fixture directory could not be removed.');
    }
}

fwrite(
    STDOUT,
    "ProductionCutoverReleaseReceiptVerifierTest passed: {$assertions} assertions.\n"
);
