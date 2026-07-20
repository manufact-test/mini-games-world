<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
final class RuntimePrimaryStagingEvidenceV3Verifier
{
    public const MANIFEST_VERSION = 'v3-staging-db-primary-selector-evidence';
}
final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v1-guarded-staging-entrypoint-selector';
}
final class RuntimePrimaryEntrypointStorageContextTestStorage implements StorageAdapterInterface
{
    public function __construct(private string $driver) {}
    public function driver(): string { return $this->driver; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertThrows = static function (callable $callback, string $messagePart) use (&$assertions): void {
    $assertions++;
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($messagePart))) return;
        throw new RuntimeException('Unexpected exception: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected exception was not thrown.');
};

$assertTrue(RuntimePrimaryEntrypointStorageContext::installed() === false, 'Context must start empty');
$assertTrue(RuntimePrimaryEntrypointStorageContext::storageOrNull() === null, 'Empty context must expose null storage');
$assertTrue(
    (RuntimePrimaryEntrypointStorageContext::safeReport()['storage_driver'] ?? '') === 'json',
    'Empty context must preserve the JSON default report'
);
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::storage(),
    'context is not installed'
);

$database = new RuntimePrimaryEntrypointStorageContextTestStorage('database');
$json = new RuntimePrimaryEntrypointStorageContextTestStorage('json');
$report = [
    'resolved' => true,
    'application_entrypoint_routed' => false,
    'projection_outbox_enabled' => true,
    'read_only_readiness_audit' => true,
    'drift_check_passed' => true,
    'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION,
    'selector_contract_version' => RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
    'selector_evidence_fingerprint' => str_repeat('a', 64),
    'state_revision' => 7,
    'state_sha256' => str_repeat('b', 64),
    'database_identity_fingerprint' => str_repeat('c', 64),
    'evidence_fingerprint' => str_repeat('d', 64),
];

$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($json, 'api', $report),
    'only guarded db-primary storage'
);
$missingV3 = $report;
$missingV3['evidence_manifest_version'] = 'v2-staging-db-primary-evidence';
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $missingV3),
    'requires selector-aware evidence v3'
);
$missingSelector = $report;
$missingSelector['selector_evidence_fingerprint'] = '';
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $missingSelector),
    'valid selector evidence fingerprint'
);
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'admin', $report),
    'supports only api or webhook'
);

RuntimePrimaryEntrypointStorageContext::install($database, 'api', $report);
$assertTrue(RuntimePrimaryEntrypointStorageContext::installed() === true, 'Valid context must install');
$assertTrue(RuntimePrimaryEntrypointStorageContext::storage() === $database, 'Context must preserve the exact storage instance');
RuntimePrimaryEntrypointStorageContext::install($database, 'api', $report);
$assertTrue(RuntimePrimaryEntrypointStorageContext::storage() === $database, 'Same context installation must be idempotent');
$safe = RuntimePrimaryEntrypointStorageContext::safeReport();
$assertTrue(($safe['entrypoint'] ?? '') === 'api', 'Context report must preserve the entrypoint');
$assertTrue(($safe['application_entrypoint_routed'] ?? false) === true, 'Installed context must report request-local routing');
$assertTrue(($safe['evidence_manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION, 'Context must report evidence v3');
$assertTrue(($safe['selector_contract_version'] ?? '') === RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION, 'Context must report selector contract');
$assertTrue(($safe['production_changed'] ?? true) === false, 'Context must not claim production mutation');

$other = new RuntimePrimaryEntrypointStorageContextTestStorage('database');
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($other, 'api', $report),
    'already installed for another storage or entrypoint'
);
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'webhook', $report),
    'already installed for another storage or entrypoint'
);

fwrite(STDOUT, "RuntimePrimaryEntrypointStorageContextTest passed: {$assertions} assertions.\n");
