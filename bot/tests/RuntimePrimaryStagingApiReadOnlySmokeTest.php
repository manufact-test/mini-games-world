<?php
declare(strict_types=1);

interface DatabaseConnectionInterface
{
    public function fetchAll(string $sql, array $params = []): array;
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}
final class RuntimePrimaryStagingEvidenceV4Verifier
{
    public const MANIFEST_VERSION = 'v4-staging-db-primary-api-lifecycle-evidence';
}
final class RuntimePrimaryStagingSelectorEvidence
{
    public const CONTRACT_VERSION = 'v2-api-only-staging-db-primary-entrypoint-selector';
}
final class RuntimePrimaryStagingRequestSessionConfig
{
    public const CONTRACT_VERSION = 'v1-api-only-bounded-request-session';
}
final class RuntimePrimaryAllModuleProjector
{
    public const CONTRACT_VERSION = 'v1-normalized-all-modules';
}
final class DatabasePrimaryStateStorageAdapter
{
    public function __construct(
        public array $state,
        public int $revision,
        public string $stateSha
    ) {}
    public function driver(): string { return 'database'; }
    public function status(): array
    {
        return [
            'ok' => true,
            'driver' => 'database',
            'revision' => $this->revision,
            'state_sha256' => $this->stateSha,
            'projection_outbox_enabled' => true,
        ];
    }
    public function readOnly(callable $callback): mixed
    {
        return $callback($this->state);
    }
}
final class RuntimePrimaryStagingApiReadOnlySmokeTestDatabase implements DatabaseConnectionInterface
{
    public function __construct(public array $rows) {}
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->rows;
    }
}
final class RuntimePrimaryEntrypointStorageContext
{
    public static ?DatabasePrimaryStateStorageAdapter $storage = null;
    public static array $reportOverride = [];
    public static function safeReport(): array
    {
        $storage = self::$storage;
        $default = [
            'installed' => $storage !== null,
            'entrypoint' => 'api',
            'storage_driver' => 'database',
            'request_finalizer_registered' => true,
            'dynamic_session_readiness' => true,
            'legacy_json_bridges_suppressed' => true,
            'webhook_allowed' => false,
            'production_changed' => false,
            'evidence_manifest_version' => RuntimePrimaryStagingEvidenceV4Verifier::MANIFEST_VERSION,
            'selector_contract_version' => RuntimePrimaryStagingSelectorEvidence::CONTRACT_VERSION,
            'request_session_contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
            'evidence_fingerprint' => str_repeat('a', 64),
            'selector_evidence_fingerprint' => str_repeat('b', 64),
            'request_session_evidence_fingerprint' => str_repeat('c', 64),
            'database_identity_fingerprint' => str_repeat('d', 64),
            'state_revision' => $storage?->revision ?? 0,
            'state_sha256' => $storage?->stateSha ?? '',
        ];
        return array_replace($default, self::$reportOverride);
    }
    public static function storage(): DatabasePrimaryStateStorageAdapter
    {
        if (self::$storage === null) throw new RuntimeException('missing storage');
        return self::$storage;
    }
}
final class RuntimePrimaryEntrypointBridgeGuard
{
    public static bool $allowed = false;
    public static function legacyJsonBridgeAllowed(): bool { return self::$allowed; }
}
final class RuntimePrimaryStagingApiRequestFinalizationHook
{
    public function __construct(private Closure $callback) {}
    public function __invoke(): void { ($this->callback)(); }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php';

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
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
    return $value;
};
$canonicalSha = static function (array $value) use ($canonicalize): string {
    return hash('sha256', json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
};
$eventRow = static function (int $revision, string $sha): array {
    return [
        'state_revision' => $revision,
        'projection_version' => RuntimePrimaryAllModuleProjector::CONTRACT_VERSION,
        'state_sha256' => $sha,
        'status' => 'completed',
        'attempt_count' => 1,
        'lease_token' => '',
        'lease_expires_at_utc' => '',
        'last_error' => '',
        'available_at_utc' => '2026-07-21T08:00:00+00:00',
        'created_at_utc' => '2026-07-21T08:00:00+00:00',
        'updated_at_utc' => '2026-07-21T08:00:00+00:00',
    ];
};
$finalReport = static function (int $revision, string $sha, int $ticks = 0): array {
    return [
        'attempted' => true,
        'completed' => true,
        'projection_event_status' => 'completed',
        'worker_tick_count' => $ticks,
        'final_state_revision' => $revision,
        'final_state_sha256' => $sha,
        'read_only_audit' => true,
        'legacy_json_bridges_suppressed' => true,
        'api_only' => true,
        'webhook_allowed' => false,
        'production_changed' => false,
    ];
};
$make = static function () use ($canonicalSha, $eventRow): array {
    $state = [
        'users' => ['100' => ['id' => '100', 'balance' => 50]],
        'games' => [],
        'system' => ['sequence' => 1],
    ];
    $sha = $canonicalSha($state);
    $storage = new DatabasePrimaryStateStorageAdapter($state, 1, $sha);
    $database = new RuntimePrimaryStagingApiReadOnlySmokeTestDatabase([
        $eventRow(1, $sha),
    ]);
    RuntimePrimaryEntrypointStorageContext::$storage = $storage;
    RuntimePrimaryEntrypointStorageContext::$reportOverride = [];
    RuntimePrimaryEntrypointBridgeGuard::$allowed = false;
    unset($GLOBALS['mgw_api_db_primary_finalization_report']);
    return [$storage, $database, $sha];
};
$hookFor = static function (string $sha, int $ticks = 0) use ($finalReport): RuntimePrimaryStagingApiRequestFinalizationHook {
    return new RuntimePrimaryStagingApiRequestFinalizationHook(
        static function () use ($finalReport, $sha, $ticks): void {
            $GLOBALS['mgw_api_db_primary_finalization_report'] = $finalReport(1, $sha, $ticks);
        }
    );
};

[$storage, $database, $sha] = $make();
$GLOBALS['mgw_api_db_primary_finalization_report'] = ['completed' => true, 'stale' => true];
$report = (new RuntimePrimaryStagingApiReadOnlySmoke(
    $storage,
    $database,
    [$hookFor($sha)],
    [static fn(array $data): array => $data]
))->run();
$assertTrue(($report['ok'] ?? false) === true, 'Exact read-only smoke must pass');
$assertTrue(($report['projection_contract_version'] ?? '') === RuntimePrimaryAllModuleProjector::CONTRACT_VERSION, 'Read-only smoke must expose exact projector version');
$assertTrue(($report['completed_events_lease_free'] ?? false) === true, 'Read-only smoke must prove completed events are lease-free');
$assertTrue(($report['worker_tick_count'] ?? -1) === 0, 'Read-only smoke must require zero worker ticks');
$assertTrue(($report['context_state_matched'] ?? false) === true, 'Read-only smoke must bind context to current state');
$assertTrue(($report['lifecycle_v4_verified'] ?? false) === true, 'Read-only smoke must verify lifecycle v4 context');
$assertTrue(($report['legacy_json_bridges_suppressed'] ?? false) === true, 'Read-only smoke must verify bridge suppression');
$assertTrue(($report['state_unchanged'] ?? false) === true, 'Read-only smoke must preserve state');
$assertTrue(($report['outbox_unchanged'] ?? false) === true, 'Read-only smoke must preserve outbox');
$assertTrue(($report['data_filters_unchanged'] ?? false) === true, 'Read-only smoke must preserve filtered payload');
$assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($report['outbox_fingerprint'] ?? '')) === 1, 'Read-only smoke must expose safe outbox fingerprint');

[$storage, $database, $sha] = $make();
RuntimePrimaryEntrypointStorageContext::$reportOverride['evidence_manifest_version'] = 'v3-staging-db-primary-selector-evidence';
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'exact guarded lifecycle v4 request context'
);

[$storage, $database, $sha] = $make();
RuntimePrimaryEntrypointStorageContext::$reportOverride['state_revision'] = 2;
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'context no longer matches current db-primary state'
);

[$storage, $database, $sha] = $make();
RuntimePrimaryEntrypointBridgeGuard::$allowed = true;
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'exact guarded lifecycle v4 request context'
);

[$storage, $database, $sha] = $make();
$database->rows[0]['projection_version'] = 'v0-old-projector';
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'outbox completion chain is invalid'
);

[$storage, $database, $sha] = $make();
$database->rows[0]['lease_token'] = 'stale-lease';
$database->rows[0]['lease_expires_at_utc'] = '2026-07-21T08:05:00+00:00';
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'outbox completion chain is invalid'
);

[$storage, $database, $sha] = $make();
$database->rows[0]['last_error'] = 'old failure';
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha)], []
    ))->run(),
    'outbox completion chain is invalid'
);

[$storage, $database, $sha] = $make();
$noReportHook = new RuntimePrimaryStagingApiRequestFinalizationHook(static function (): void {});
$GLOBALS['mgw_api_db_primary_finalization_report'] = $finalReport(1, $sha, 0);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$noReportHook], []
    ))->run(),
    'finalization contract is incomplete'
);

[$storage, $database, $sha] = $make();
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$hookFor($sha, 1)], []
    ))->run(),
    'not read-only'
);

[$storage, $database, $sha] = $make();
$identityHook = $hookFor($sha);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage,
        $database,
        [$identityHook],
        [static function (array $data): array {
            $data['changed'] = true;
            return $data;
        }]
    ))->run(),
    'data filters changed'
);

[$storage, $database, $sha] = $make();
$database->rows = [];
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$identityHook], []
    ))->run(),
    'outbox revision chain is incomplete'
);

[$storage, $database, $sha] = $make();
$mutatingHook = new RuntimePrimaryStagingApiRequestFinalizationHook(
    static function () use ($database, $finalReport, $sha): void {
        $GLOBALS['mgw_api_db_primary_finalization_report'] = $finalReport(1, $sha, 0);
        $database->rows[0]['updated_at_utc'] = '2026-07-21T08:01:00+00:00';
    }
);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingApiReadOnlySmoke(
        $storage, $database, [$mutatingHook], []
    ))->run(),
    'changed db-primary state or outbox'
);

fwrite(STDOUT, "RuntimePrimaryStagingApiReadOnlySmokeTest passed: {$assertions} assertions.\n");
