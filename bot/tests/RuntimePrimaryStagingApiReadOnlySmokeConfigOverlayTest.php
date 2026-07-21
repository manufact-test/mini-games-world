<?php
declare(strict_types=1);

final class RuntimePrimaryStagingApiReadOnlySmokeOverlaySelector
{
    public function __construct(private bool $enabled) {}
    public function enabled(): bool { return $this->enabled; }
}
final class RuntimePrimaryStagingEntrypointSelectorConfig
{
    public const CONTRACT_VERSION = 'v2-api-only-staging-db-primary-entrypoint-selector';
    public static function fromApplicationConfig(array $config): RuntimePrimaryStagingApiReadOnlySmokeOverlaySelector
    {
        return new RuntimePrimaryStagingApiReadOnlySmokeOverlaySelector(
            (bool)($config['staging_db_primary_entrypoint_selector']['enabled'] ?? false)
        );
    }
}
final class RuntimePrimaryStagingApiReadOnlySmokeOverlaySession
{
    public function __construct(private bool $enabled) {}
    public function enabled(): bool { return $this->enabled; }
    public function assertEnabledForApi(int $baseline, int $current, int $now): void
    {
        if (!$this->enabled || $baseline !== 3 || $current !== 3) {
            throw new RuntimeException('invalid session');
        }
    }
}
final class RuntimePrimaryStagingRequestSessionConfig
{
    public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session';
    public static function fromApplicationConfig(array $config): RuntimePrimaryStagingApiReadOnlySmokeOverlaySession
    {
        return new RuntimePrimaryStagingApiReadOnlySmokeOverlaySession(
            (bool)($config['staging_db_primary_request_session']['enabled'] ?? false)
        );
    }
}
final class RuntimePrimaryStagingApiReadOnlySmokeOverlayActivation
{
    public function __construct(private bool $enabled) {}
    public function safeSummary(): array { return ['enabled' => $this->enabled]; }
    public function assertApproved(
        DatabaseConfig $database,
        string $commit,
        string $privateDir,
        int $now
    ): void {
        if (!$this->enabled) throw new RuntimeException('activation disabled');
    }
}
final class RuntimePrimaryStagingActivationConfig
{
    public static function fromApplicationConfig(array $config): RuntimePrimaryStagingApiReadOnlySmokeOverlayActivation
    {
        return new RuntimePrimaryStagingApiReadOnlySmokeOverlayActivation(
            (bool)($config['staging_db_primary_activation']['enabled'] ?? false)
        );
    }
}
final class RuntimePrimaryPrivateConfigGuard
{
    public static string $privateDir = '';
    public static function assertExternal(string $configFile, string $projectRoot): array
    {
        return ['private_dir' => self::$privateDir];
    }
}
final class RuntimePrimaryStagingActivationEvidenceLoader
{
    public static string $version = 'v4-staging-db-primary-api-lifecycle-evidence';
    public function __construct(string $projectRoot, string $privateDir) {}
    public function load(string $path): array
    {
        return [
            'manifest' => [
                'manifest_version' => self::$version,
                'request_session_evidence' => [
                    'baseline' => [
                        'state_revision' => 3,
                        'state_sha256' => str_repeat('a', 64),
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
    public static bool $ok = true;
    public static string $databaseIdentity = '';
    public static string $commit = '';
    public function __construct(string $projectRoot) {}
    public function verify(array $manifest): array
    {
        return [
            'ok' => self::$ok,
            'blockers' => self::$ok ? [] : ['forced failure'],
            'database_identity_fingerprint' => self::$databaseIdentity,
            'repository_commit' => self::$commit,
            'evidence_fingerprint' => str_repeat('e', 64),
        ];
    }
}
final class DatabaseConfig
{
    public static bool $enabled = true;
    public static string $identity = '';
    public static function fromApplicationConfig(array $config): self { return new self(); }
    public function enabled(): bool { return self::$enabled; }
    public function identityFingerprint(): string { return self::$identity; }
}
final class RuntimePrimaryRepositoryCommitResolver
{
    public static string $commit = '';
    public static function resolve(string $projectRoot): string { return self::$commit; }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php';

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
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) $remove($child);
        else @unlink($child);
    }
    @rmdir($path);
};

$temp = sys_get_temp_dir() . '/mgw-read-only-overlay-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$configFile = $temp . '/config.php';
$evidenceFile = $temp . '/evidence-v4.json';
file_put_contents($configFile, "<?php return [];\n");
file_put_contents($evidenceFile, "{}\n");
RuntimePrimaryPrivateConfigGuard::$privateDir = $temp;
$identity = str_repeat('b', 64);
$commit = str_repeat('c', 40);
DatabaseConfig::$identity = $identity;
RuntimePrimaryStagingEvidenceV4Gate::$databaseIdentity = $identity;
RuntimePrimaryStagingEvidenceV4Gate::$commit = $commit;
RuntimePrimaryRepositoryCommitResolver::$commit = $commit;
$base = [
    'environment' => 'staging',
    'database' => ['enabled' => true],
    'data_dir' => '/private/json',
];
$original = $base;
$now = 1_800_000_000;

try {
    $result = (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
        $projectRoot,
        $base,
        $configFile,
        $evidenceFile,
        300
    ))->build($now);
    $overlay = (array)($result['config'] ?? []);
    $report = (array)($result['report'] ?? []);
    $assertTrue($base === $original, 'Overlay builder must not mutate the source config');
    $assertTrue(($overlay['staging_db_primary_activation']['enabled'] ?? false) === true, 'Overlay must enable activation in memory');
    $assertTrue(($overlay['staging_db_primary_entrypoint_selector']['allowed_entrypoints'] ?? []) === ['api'], 'Overlay selector must be API-only');
    $assertTrue(($overlay['staging_db_primary_request_session']['baseline_revision'] ?? 0) === 3, 'Overlay must use exact lifecycle baseline');
    $assertTrue(($overlay['staging_db_primary_request_session']['max_revision_delta'] ?? 0) === 1, 'Read-only smoke must use one-revision bound');
    $assertTrue(($overlay['staging_db_primary_request_session']['max_worker_ticks'] ?? 0) === 1, 'Read-only smoke must use one worker-tick ceiling');
    $assertTrue(($report['persistent_config_changed'] ?? true) === false, 'Overlay report must preserve persistent config');
    $assertTrue(($report['ttl_seconds'] ?? 0) === 300, 'Overlay report must preserve TTL');
    $assertTrue(($report['webhook_allowed'] ?? true) === false, 'Overlay must forbid webhook');

    $persistentSelector = $base;
    $persistentSelector['staging_db_primary_entrypoint_selector'] = ['enabled' => true];
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
            $projectRoot, $persistentSelector, $configFile, $evidenceFile, 300
        ))->build($now),
        'persistent selector latch to be disabled'
    );

    RuntimePrimaryStagingActivationEvidenceLoader::$version = 'v3-staging-db-primary-selector-evidence';
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
            $projectRoot, $base, $configFile, $evidenceFile, 300
        ))->build($now),
        'requires lifecycle evidence v4'
    );
    RuntimePrimaryStagingActivationEvidenceLoader::$version = RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION;

    RuntimePrimaryStagingEvidenceV4Gate::$databaseIdentity = str_repeat('d', 64);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
            $projectRoot, $base, $configFile, $evidenceFile, 300
        ))->build($now),
        'different database identity'
    );
    RuntimePrimaryStagingEvidenceV4Gate::$databaseIdentity = $identity;

    RuntimePrimaryStagingEvidenceV4Gate::$commit = str_repeat('f', 40);
    $assertThrows(
        static fn() => (new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
            $projectRoot, $base, $configFile, $evidenceFile, 300
        ))->build($now),
        'different checkout'
    );
    RuntimePrimaryStagingEvidenceV4Gate::$commit = $commit;

    $assertThrows(
        static fn() => new RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay(
            $projectRoot, $base, $configFile, $evidenceFile, 30
        ),
        'ttl must be between 60 and 600'
    );
} finally {
    $remove($temp);
}

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeConfigOverlayTest passed: {$assertions} assertions.\n");
