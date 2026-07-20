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
final class RuntimePrimaryStagingEvidenceV3Verifier
{
    public const MANIFEST_VERSION = 'v3-staging-db-primary-selector-evidence';
}
final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v1-guarded-staging-entrypoint-selector';
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
RuntimePrimaryEntrypointStorageContext::install($database, 'webhook', [
    'resolved' => true,
    'application_entrypoint_routed' => false,
    'projection_outbox_enabled' => true,
    'read_only_readiness_audit' => true,
    'drift_check_passed' => true,
    'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION,
    'selector_contract_version' => RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
    'selector_evidence_fingerprint' => str_repeat('a', 64),
    'state_revision' => 3,
    'state_sha256' => str_repeat('b', 64),
    'database_identity_fingerprint' => str_repeat('c', 64),
    'evidence_fingerprint' => str_repeat('d', 64),
]);
$_SERVER['SCRIPT_FILENAME'] = '/private/webhook.php';
$resolved = StorageFactory::createJson('/ignored/json-data');
$assertTrue($resolved === $database, 'Installed entrypoint context must return the exact DB storage instance');
$resolvedAgain = StorageFactory::createJson('/ignored/again');
$assertTrue($resolvedAgain === $database, 'Repeated createJson calls must reuse the same request-local DB storage');
$assertTrue(($resolvedAgain->driver() ?? '') === 'database', 'Context override must identify database storage');

fwrite(STDOUT, "RuntimePrimaryStorageFactoryEntrypointContextTest passed: {$assertions} assertions.\n");
