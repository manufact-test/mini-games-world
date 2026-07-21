<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
final class JsonStorageAdapter implements StorageAdapterInterface
{
    public function __construct(public string $dataDir) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
}
final class RuntimePrimaryStorageFactoryTestDatabaseStorage implements StorageAdapterInterface
{
    public function driver(): string { return 'database'; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
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

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php';
require $projectRoot . '/bot/storage/StorageFactory.php';

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$_SERVER['SCRIPT_FILENAME'] = '/private/worker.php';
$json = StorageFactory::createJson('/private/json-data');
$assertTrue($json instanceof JsonStorageAdapter, 'Non-entrypoint createJson must return JSON storage');
$assertTrue($json->dataDir === '/private/json-data', 'JSON fallback must preserve data directory');

$database = new RuntimePrimaryStorageFactoryTestDatabaseStorage();
RuntimePrimaryEntrypointStorageContext::install($database, 'api', [
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
    'baseline_state_revision' => 1,
    'state_revision' => 1,
    'maximum_state_revision' => 3,
    'remaining_session_revisions' => 2,
    'session_expires_at_utc' => gmdate(DATE_ATOM, time() + 600),
    'baseline_state_sha256' => str_repeat('a', 64),
    'state_sha256' => str_repeat('a', 64),
    'database_identity_fingerprint' => str_repeat('b', 64),
    'evidence_fingerprint' => str_repeat('c', 64),
    'selector_evidence_fingerprint' => str_repeat('d', 64),
    'request_session_evidence_fingerprint' => str_repeat('e', 64),
]);
$_SERVER['SCRIPT_FILENAME'] = '/private/api.php';
$resolved = StorageFactory::createJson('/ignored/json-data');
$assertTrue($resolved === $database, 'Installed API context must return the exact DB storage instance');
$resolvedAgain = StorageFactory::createJson('/ignored/again');
$assertTrue($resolvedAgain === $database, 'Repeated API createJson calls must reuse request-local DB storage');
$assertTrue($resolvedAgain->driver() === 'database', 'Context override must identify database storage');

fwrite(STDOUT, "RuntimePrimaryStorageFactoryEntrypointContextTest passed: {$assertions} assertions.\n");
