<?php
declare(strict_types=1);

final class RuntimePrimaryEntrypointStorageContext
{
    public static bool $installed = false;
    public static int $installCalls = 0;
    public static array $events = [];

    public static function installed(): bool { return self::$installed; }
    public static function install(mixed $storage, string $entrypoint, array $report): void
    {
        self::$events[] = 'context';
        self::$installCalls++;
        self::$installed = true;
    }
    public static function reset(): void
    {
        self::$installed = false;
        self::$installCalls = 0;
        self::$events = [];
    }
}

final class RuntimePrimaryStagingApiSessionCoordinatorTestSelector
{
    public function __construct(private bool $enabled) {}
    public function enabledFor(string $entrypoint): bool
    {
        return $this->enabled && $entrypoint === 'api';
    }
}
final class RuntimePrimaryStagingEntrypointSelectorConfig
{
    public static function fromApplicationConfig(array $config): RuntimePrimaryStagingApiSessionCoordinatorTestSelector
    {
        return new RuntimePrimaryStagingApiSessionCoordinatorTestSelector(
            (bool)($config['selector_enabled'] ?? false)
        );
    }
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

final class RuntimePrimaryStagingApiSessionCoordinatorTestPolicy
{
    public function assertApproved(
        DatabaseConfig $databaseConfig,
        string $commit,
        string $privateDir,
        int $now
    ): void {}
    public function evidenceFile(): string { return '/private/lifecycle-v4.json'; }
    public function expectedEvidenceFingerprint(): string { return str_repeat('c', 64); }
}

final class RuntimePrimaryStagingActivationConfig
{
    public static function fromApplicationConfig(array $config): RuntimePrimaryStagingApiSessionCoordinatorTestPolicy
    {
        return new RuntimePrimaryStagingApiSessionCoordinatorTestPolicy();
    }
}

final class RuntimePrimaryStagingActivationEvidenceLoader
{
    public function __construct(string $projectRoot, string $privateDir) {}
    public function load(string $path): array
    {
        return [
            'manifest' => [
                'manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
                'request_session_evidence' => [
                    'baseline' => [
                        'state_revision' => 1,
                        'state_sha256' => str_repeat('e', 64),
                        'json_sha256' => str_repeat('1', 64),
                        'inventory_fingerprint' => str_repeat('2', 64),
                    ],
                ],
            ],
        ];
    }
}

final class RuntimePrimaryStagingEvidenceV4Verifier
{
    public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';
}

final class RuntimePrimaryStagingEvidenceV4Gate
{
    public function __construct(string $projectRoot) {}
    public function verify(array $manifest): array
    {
        return [
            'ok' => true,
            'evidence_fingerprint' => str_repeat('c', 64),
            'database_identity_fingerprint' => str_repeat('b', 64),
            'selector_evidence_fingerprint' => str_repeat('d', 64),
            'request_session_evidence_fingerprint' => str_repeat('f', 64),
            'blockers' => [],
        ];
    }
}

final class RuntimePrimaryStagingRequestSessionConfig
{
    public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session';
    public static function fromApplicationConfig(array $config): self { return new self(); }
    public function assertEnabledForApi(int $baseline, int $current, int $now): void {}
    public function maximumRevision(): int { return 5; }
    public function remainingRevisions(int $current): int { return 5 - $current; }
    public function expiresAtUtc(): string { return gmdate(DATE_ATOM, time() + 600); }
    public function leaseSeconds(): int { return 60; }
}

final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v2-api-session-staging-entrypoint-selector';
}

final class RuntimePrimaryStagingApiSessionCoordinatorTestDatabase {}
final class PdoConnectionFactory
{
    public static int $calls = 0;
    public static function create(DatabaseConfig $config): RuntimePrimaryStagingApiSessionCoordinatorTestDatabase
    {
        self::$calls++;
        return new RuntimePrimaryStagingApiSessionCoordinatorTestDatabase();
    }
}

final class JsonStorageAdapter
{
    public function __construct(string $dataDir) {}
}

final class RuntimePrimaryRepositoryProjectorFactory
{
    public function __construct(array $config, RuntimePrimaryStagingApiSessionCoordinatorTestDatabase $database) {}
    public function create(): object { return new stdClass(); }
}

final class RuntimePrimaryProjectionAuditorAdapter
{
    public function __construct(object $projector) {}
}

final class RuntimePrimaryProjectionOutboxWriter {}
final class DatabasePrimaryStateStorageAdapter
{
    public function __construct(
        RuntimePrimaryStagingApiSessionCoordinatorTestDatabase $database,
        RuntimePrimaryProjectionOutboxWriter $outbox
    ) {}
}

final class RuntimePrimaryStagingRequestSessionReadiness
{
    public function __construct(
        JsonStorageAdapter $jsonStorage,
        RuntimePrimaryStagingApiSessionCoordinatorTestDatabase $database,
        DatabasePrimaryStateStorageAdapter $storage,
        RuntimePrimaryProjectionAuditorAdapter $auditor,
        RuntimePrimaryStagingRequestSessionConfig $session
    ) {}
    public function assertReady(array $baseline): array
    {
        return [
            'current_state_revision' => 1,
            'current_state_sha256' => str_repeat('e', 64),
        ];
    }
}

final class RuntimePrimaryProjectionWorker
{
    public function __construct(
        RuntimePrimaryStagingApiSessionCoordinatorTestDatabase $database,
        object $projector,
        int $leaseSeconds
    ) {}
}

final class RuntimePrimaryProjectionWorkerAdapter
{
    public function __construct(RuntimePrimaryProjectionWorker $worker) {}
}

final class RuntimePrimaryStagingRequestFinalizer
{
    public function __construct(
        RuntimePrimaryStagingApiSessionCoordinatorTestDatabase $database,
        RuntimePrimaryProjectionWorkerAdapter $worker,
        RuntimePrimaryProjectionAuditorAdapter $auditor,
        RuntimePrimaryStagingRequestSessionConfig $session
    ) {}
}

final class RuntimePrimaryStagingApiRequestFinalizationHook
{
    public static int $constructCalls = 0;
    public function __construct(
        DatabasePrimaryStateStorageAdapter $storage,
        RuntimePrimaryStagingRequestFinalizer $finalizer,
        array $report
    ) {
        self::$constructCalls++;
        RuntimePrimaryEntrypointStorageContext::$events[] = 'hook';
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiSessionCoordinator.php';

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

$disabledConfig = [
    'environment' => 'staging',
    'data_dir' => '/private/json',
    'selector_enabled' => false,
];
$disabled = new RuntimePrimaryStagingApiSessionCoordinator(
    $projectRoot,
    $disabledConfig,
    '/private/config.php'
);
$assertThrows(
    static fn() => $disabled->install(),
    'requires the exact api selector latch'
);
$assertTrue(RuntimePrimaryPrivateConfigGuard::$calls === 0, 'Missing selector latch must block before private config');
$assertTrue(DatabaseConfig::$calls === 0, 'Missing selector latch must block before DB config');
$assertTrue(PdoConnectionFactory::$calls === 0, 'Missing selector latch must block before MySQL');

$config = [
    'environment' => 'staging',
    'data_dir' => '/private/json',
    'selector_enabled' => true,
];
$coordinator = new RuntimePrimaryStagingApiSessionCoordinator(
    $projectRoot,
    $config,
    '/private/config.php'
);

RuntimePrimaryEntrypointStorageContext::reset();
RuntimePrimaryStagingApiRequestFinalizationHook::$constructCalls = 0;
$GLOBALS['mgw_api_success_hooks'] = 'invalid';
unset($GLOBALS['mgw_api_db_primary_finalization_hook']);
$assertThrows(
    static fn() => $coordinator->install(),
    'success hook registry is invalid'
);
$assertTrue(
    RuntimePrimaryStagingApiRequestFinalizationHook::$constructCalls === 1,
    'Coordinator must fully prepare finalizer hook before validating publication'
);
$assertTrue(
    RuntimePrimaryEntrypointStorageContext::$installCalls === 0,
    'Invalid hook registry must fail before installing DB request context'
);
$assertTrue(
    !isset($GLOBALS['mgw_api_db_primary_finalization_hook']),
    'Invalid hook registry must not publish finalization hook'
);

RuntimePrimaryEntrypointStorageContext::reset();
RuntimePrimaryStagingApiRequestFinalizationHook::$constructCalls = 0;
$existingHook = static function (): void {};
$GLOBALS['mgw_api_success_hooks'] = [$existingHook];
unset($GLOBALS['mgw_api_db_primary_finalization_hook']);
$result = $coordinator->install();
$published = $GLOBALS['mgw_api_db_primary_finalization_hook'] ?? null;
$assertTrue(($result['ok'] ?? false) === true, 'Valid coordinator installation must succeed');
$assertTrue(
    RuntimePrimaryEntrypointStorageContext::$events === ['hook', 'context'],
    'Coordinator must prepare hook before installing request context'
);
$assertTrue(
    RuntimePrimaryEntrypointStorageContext::$installCalls === 1,
    'Valid coordinator path must install request context exactly once'
);
$assertTrue(
    $published instanceof RuntimePrimaryStagingApiRequestFinalizationHook,
    'Valid coordinator path must publish prepared finalization hook'
);
$assertTrue(
    ($GLOBALS['mgw_api_success_hooks'][0] ?? null) === $published,
    'DB-primary finalizer must be first API success hook'
);
$assertTrue(
    ($GLOBALS['mgw_api_success_hooks'][1] ?? null) === $existingHook,
    'Existing API success hooks must retain order after finalizer'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiSessionCoordinatorTest passed: {$assertions} assertions.\n");
