<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
final class RuntimePrimaryStagingEvidenceV4Verifier
{
    public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';
}
final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v2-api-session-staging-entrypoint-selector';
}
final class RuntimePrimaryStagingRequestSessionConfig
{
    public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session';
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
$empty = RuntimePrimaryEntrypointStorageContext::safeReport();
$assertTrue(($empty['storage_driver'] ?? '') === 'json', 'Empty context must preserve JSON default');
$assertTrue(($empty['request_finalizer_registered'] ?? true) === false, 'Empty context must not claim finalizer');
$assertTrue(($empty['webhook_allowed'] ?? true) === false, 'Empty context must forbid webhook');
$assertThrows(static fn() => RuntimePrimaryEntrypointStorageContext::storage(), 'context is not installed');

$database = new RuntimePrimaryEntrypointStorageContextTestStorage('database');
$json = new RuntimePrimaryEntrypointStorageContextTestStorage('json');
$report = [
    'resolved' => true,
    'application_entrypoint_routed' => false,
    'projection_outbox_enabled' => true,
    'read_only_readiness_audit' => true,
    'drift_check_passed' => true,
    'dynamic_session_readiness' => true,
    'request_finalizer_registered' => true,
    'legacy_json_bridges_suppressed' => true,
    'webhook_allowed' => false,
    'production_changed' => false,
    'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
    'selector_contract_version' => RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
    'request_session_contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
    'baseline_state_revision' => 3,
    'state_revision' => 4,
    'maximum_state_revision' => 7,
    'remaining_session_revisions' => 3,
    'session_expires_at_utc' => gmdate(DATE_ATOM, time() + 600),
    'baseline_state_sha256' => str_repeat('a', 64),
    'state_sha256' => str_repeat('b', 64),
    'database_identity_fingerprint' => str_repeat('c', 64),
    'evidence_fingerprint' => str_repeat('d', 64),
    'selector_evidence_fingerprint' => str_repeat('e', 64),
    'request_session_evidence_fingerprint' => str_repeat('f', 64),
];

$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($json, 'api', $report),
    'only guarded db-primary storage'
);
$oldEvidence = $report;
$oldEvidence['evidence_manifest_version'] = 'v3-staging-db-primary-selector-evidence';
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $oldEvidence),
    'requires lifecycle evidence v4'
);
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'webhook', $report),
    'supports only api'
);
$missingFinalizer = $report;
$missingFinalizer['request_finalizer_registered'] = false;
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $missingFinalizer),
    'missing lifecycle resolution evidence'
);
$invalidBounds = $report;
$invalidBounds['remaining_session_revisions'] = 99;
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $invalidBounds),
    'revision bounds are invalid'
);
foreach ([
    'baseline_state_sha256' => 'baseline state fingerprint',
    'state_sha256' => 'state fingerprint',
    'database_identity_fingerprint' => 'database identity fingerprint',
    'evidence_fingerprint' => 'evidence fingerprint',
    'selector_evidence_fingerprint' => 'selector evidence fingerprint',
    'request_session_evidence_fingerprint' => 'request session evidence fingerprint',
] as $field => $message) {
    $invalid = $report;
    $invalid[$field] = '';
    $assertThrows(
        static fn() => RuntimePrimaryEntrypointStorageContext::install($database, 'api', $invalid),
        'valid ' . $message
    );
}

RuntimePrimaryEntrypointStorageContext::install($database, 'api', $report);
$assertTrue(RuntimePrimaryEntrypointStorageContext::installed() === true, 'Valid lifecycle context must install');
$assertTrue(RuntimePrimaryEntrypointStorageContext::storage() === $database, 'Context must preserve exact storage');
RuntimePrimaryEntrypointStorageContext::install($database, 'api', $report);
$assertTrue(RuntimePrimaryEntrypointStorageContext::storage() === $database, 'Same context install must be idempotent');
$safe = RuntimePrimaryEntrypointStorageContext::safeReport();
$assertTrue(($safe['entrypoint'] ?? '') === 'api', 'Safe report must preserve API entrypoint');
$assertTrue(($safe['application_entrypoint_routed'] ?? false) === true, 'Safe report must show request routing');
$assertTrue(($safe['evidence_manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION, 'Safe report must preserve v4');
$assertTrue(($safe['request_finalizer_registered'] ?? false) === true, 'Safe report must preserve finalizer registration');
$assertTrue(($safe['dynamic_session_readiness'] ?? false) === true, 'Safe report must preserve dynamic readiness');
$assertTrue(($safe['webhook_allowed'] ?? true) === false, 'Safe report must forbid webhook');

$other = new RuntimePrimaryEntrypointStorageContextTestStorage('database');
$assertThrows(
    static fn() => RuntimePrimaryEntrypointStorageContext::install($other, 'api', $report),
    'already installed for another storage or entrypoint'
);

fwrite(STDOUT, "RuntimePrimaryEntrypointStorageContextTest passed: {$assertions} assertions.\n");
