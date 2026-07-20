<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
interface DatabaseConnectionInterface {}
final class RuntimePrimarySelectorEnabledTestStorage implements StorageAdapterInterface
{
    public function driver(): string { return 'database'; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
}
final class JsonStorageAdapter implements StorageAdapterInterface
{
    public function __construct(string $dataDir) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed { $data = []; return $callback($data); }
    public function readOnly(callable $callback): mixed { return $callback([]); }
}
final class RuntimePrimarySelectorEnabledTestDatabase implements DatabaseConnectionInterface {}
final class RuntimePrimaryStagingEvidenceV3Verifier
{
    public const MANIFEST_VERSION = 'v3-staging-db-primary-selector-evidence';
}
final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v1-guarded-staging-entrypoint-selector';
}
final class RuntimePrimaryPrivateConfigGuard
{
    public static int $calls = 0;
    public static function assertExternal(string $configFile, string $projectRoot): array
    {
        self::$calls++;
        return ['private_dir' => dirname($configFile)];
    }
}
final class DatabaseConfig
{
    public static int $calls = 0;
    public static function fromApplicationConfig(array $config): self
    {
        self::$calls++;
        return new self();
    }
    public function enabled(): bool { return true; }
    public function identityFingerprint(): string { return str_repeat('b', 64); }
}
final class RuntimePrimaryRepositoryCommitResolver
{
    public static function resolve(string $projectRoot): string { return str_repeat('a', 40); }
}
final class RuntimePrimarySelectorEnabledTestPolicy
{
    public int $assertCalls = 0;
    public function assertApproved(
        DatabaseConfig $databaseConfig,
        string $commit,
        string $privateDir,
        int $now
    ): void {
        $this->assertCalls++;
    }
    public function evidenceFile(): string { return '/private/evidence.json'; }
    public function expectedEvidenceFingerprint(): string { return str_repeat('c', 64); }
}
final class RuntimePrimaryStagingActivationConfig
{
    public static ?RuntimePrimarySelectorEnabledTestPolicy $policy = null;
    public static function fromApplicationConfig(array $config): RuntimePrimarySelectorEnabledTestPolicy
    {
        self::$policy ??= new RuntimePrimarySelectorEnabledTestPolicy();
        return self::$policy;
    }
}
final class RuntimePrimaryStagingActivationEvidenceLoader
{
    public static string $manifestVersion = 'v2-staging-db-primary-evidence';
    public function __construct(string $projectRoot, string $privateDir) {}
    public function load(string $path): array
    {
        return ['manifest' => ['manifest_version' => self::$manifestVersion]];
    }
}
final class RuntimePrimaryStagingEvidenceV3Gate
{
    public static int $calls = 0;
    public function __construct(string $projectRoot) {}
    public function verify(array $manifest): array
    {
        self::$calls++;
        return [
            'ok' => true,
            'evidence_fingerprint' => str_repeat('c', 64),
            'selector_evidence_fingerprint' => str_repeat('d', 64),
            'database_identity_fingerprint' => str_repeat('b', 64),
            'blockers' => [],
        ];
    }
}
final class PdoConnectionFactory
{
    public static int $calls = 0;
    public static RuntimePrimarySelectorEnabledTestDatabase $database;
    public static function create(DatabaseConfig $config): DatabaseConnectionInterface
    {
        self::$calls++;
        return self::$database ??= new RuntimePrimarySelectorEnabledTestDatabase();
    }
}
final class RuntimePrimaryRepositoryProjectorFactory
{
    public static int $calls = 0;
    public function __construct(array $config, DatabaseConnectionInterface $database) {}
    public function create(): object
    {
        self::$calls++;
        return new stdClass();
    }
}
final class RuntimePrimarySelectorEnabledTestResolution
{
    public function __construct(private StorageAdapterInterface $storage) {}
    public function storage(): StorageAdapterInterface { return $this->storage; }
    public function safeReport(): array
    {
        return [
            'resolved' => true,
            'application_entrypoint_routed' => false,
            'projection_outbox_enabled' => true,
            'read_only_readiness_audit' => true,
            'drift_check_passed' => true,
            'state_revision' => 5,
            'state_sha256' => str_repeat('e', 64),
            'database_identity_fingerprint' => str_repeat('b', 64),
            'evidence_fingerprint' => str_repeat('c', 64),
        ];
    }
}
final class RuntimePrimaryStagingStorageResolver
{
    public static int $calls = 0;
    public static RuntimePrimarySelectorEnabledTestStorage $storage;
    public function __construct(
        string $projectRoot,
        array $config,
        string $configFile,
        StorageAdapterInterface $jsonStorage,
        DatabaseConnectionInterface $database,
        object $projector
    ) {}
    public function resolve(): RuntimePrimarySelectorEnabledTestResolution
    {
        self::$calls++;
        self::$storage ??= new RuntimePrimarySelectorEnabledTestStorage();
        return new RuntimePrimarySelectorEnabledTestResolution(self::$storage);
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointSelectorConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointStorageContext.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEntrypointStorageSelector.php';

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

$config = [
    'environment' => 'staging',
    'data_dir' => '/private/json',
    'staging_db_primary_entrypoint_selector' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingEntrypointSelectorConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
    ],
];
$selector = new RuntimePrimaryStagingEntrypointStorageSelector(
    $projectRoot,
    $config,
    '/private/config.php',
    'api'
);

$assertThrows(
    static fn() => $selector->installIfEnabled(),
    'requires selector-aware evidence v3'
);
$assertTrue(PdoConnectionFactory::$calls === 0, 'V2 evidence must block before opening MySQL');
$assertTrue(RuntimePrimaryStagingStorageResolver::$calls === 0, 'V2 evidence must block before storage resolution');

RuntimePrimaryStagingActivationEvidenceLoader::$manifestVersion = RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION;
$installed = $selector->installIfEnabled();
$assertTrue($installed === true, 'Exact v3 selector path must install');
$assertTrue(RuntimePrimaryPrivateConfigGuard::$calls === 2, 'Private config guard must run for each enabled attempt');
$assertTrue(DatabaseConfig::$calls === 2, 'Database identity must be resolved before each enabled attempt');
$assertTrue(RuntimePrimaryStagingEvidenceV3Gate::$calls === 1, 'Only v3 evidence may reach the v3 gate');
$assertTrue(PdoConnectionFactory::$calls === 1, 'Successful selector path must open exactly one DB connection');
$assertTrue(RuntimePrimaryRepositoryProjectorFactory::$calls === 1, 'Successful selector path must create exactly one projector');
$assertTrue(RuntimePrimaryStagingStorageResolver::$calls === 1, 'Successful selector path must resolve storage exactly once');
$assertTrue(RuntimePrimaryEntrypointStorageContext::installed() === true, 'Successful selector path must install request context');
$assertTrue(
    RuntimePrimaryEntrypointStorageContext::storage() === RuntimePrimaryStagingStorageResolver::$storage,
    'Request context must preserve the exact resolved DB storage'
);
$report = RuntimePrimaryEntrypointStorageContext::safeReport();
$assertTrue(($report['entrypoint'] ?? '') === 'api', 'Request context must preserve API entrypoint');
$assertTrue(($report['evidence_manifest_version'] ?? '') === RuntimePrimaryStagingEvidenceV3Verifier::MANIFEST_VERSION, 'Request context must preserve v3 evidence');
$assertTrue(($report['selector_evidence_fingerprint'] ?? '') === str_repeat('d', 64), 'Request context must preserve selector fingerprint');
$assertTrue(($report['application_entrypoint_routed'] ?? false) === true, 'Request context must explicitly report request-local routing');

fwrite(STDOUT, "RuntimePrimaryStagingEntrypointStorageSelectorEnabledTest passed: {$assertions} assertions.\n");
