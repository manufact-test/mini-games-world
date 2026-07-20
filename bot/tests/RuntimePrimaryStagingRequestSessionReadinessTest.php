<?php
declare(strict_types=1);

interface StorageAdapterInterface
{
    public function driver(): string;
    public function transaction(callable $callback): mixed;
    public function readOnly(callable $callback): mixed;
}
interface DatabaseConnectionInterface
{
    public function driver(): string;
    public function fetchAll(string $sql, array $params = []): array;
}
interface RuntimePrimaryProjectionAuditorInterface
{
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array;
}
final class RuntimePrimaryProjectionOutboxSchemaInstaller
{
    public const TABLE = 'mgw_runtime_primary_projection_outbox';
}
final class RuntimePrimaryProjectionOutboxWriter
{
    public const PROJECTION_VERSION = 'v1-normalized-all-modules';
}

final class RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage implements StorageAdapterInterface
{
    public function __construct(public array $snapshot) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed { return $callback($this->snapshot); }
    public function readOnly(callable $callback): mixed
    {
        $copy = $this->snapshot;
        return $callback($copy);
    }
}

final class DatabasePrimaryStateStorageAdapter
{
    public const DRIVER = 'database';
    private int $revision;
    private array $snapshot;
    private string $sha;

    public function __construct(int $revision, array $snapshot)
    {
        $this->setState($revision, $snapshot);
    }
    public function driver(): string { return self::DRIVER; }
    public function status(): array
    {
        return ['ok' => true, 'driver' => self::DRIVER, 'revision' => $this->revision, 'state_sha256' => $this->sha];
    }
    public function readOnly(callable $callback): mixed
    {
        $copy = $this->snapshot;
        return $callback($copy);
    }
    public function setState(int $revision, array $snapshot): void
    {
        $this->revision = $revision;
        $this->snapshot = $snapshot;
        $this->sha = hash('sha256', self::canonicalJson($snapshot));
    }
    public function sha(): string { return $this->sha; }
    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

final class RuntimePrimaryStagingRequestSessionReadinessTestDatabase implements DatabaseConnectionInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $events = [];
    public function __construct(array $events)
    {
        foreach ($events as $event) $this->events[(int)$event['state_revision']] = $event;
        ksort($this->events, SORT_NUMERIC);
    }
    public function driver(): string { return 'mysql'; }
    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'where state_revision = :state_revision')) {
            $revision = (int)($params['state_revision'] ?? 0);
            return isset($this->events[$revision]) ? [$this->events[$revision]] : [];
        }
        if (str_contains($normalized, 'group by status')) {
            $groups = [];
            foreach ($this->events as $event) {
                $groups[(string)$event['status']][] = (int)$event['state_revision'];
            }
            ksort($groups, SORT_STRING);
            $rows = [];
            foreach ($groups as $status => $revisions) {
                $rows[] = [
                    'status' => $status,
                    'event_count' => count($revisions),
                    'min_revision' => min($revisions),
                    'max_revision' => max($revisions),
                ];
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected readiness test SQL: ' . $normalized);
    }
}

final class RuntimePrimaryStagingRequestSessionReadinessTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public function __construct(
        private bool $valid = true,
        private ?RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage $jsonToMutate = null,
        private ?DatabasePrimaryStateStorageAdapter $dbToMutate = null
    ) {}
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        if ($this->jsonToMutate !== null) $this->jsonToMutate->snapshot['drift'] = true;
        if ($this->dbToMutate !== null) $this->dbToMutate->setState($stateRevision + 1, ['db_drift' => true]);
        return [
            'ok' => $this->valid,
            'parity_ok' => $this->valid,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => str_repeat('f', 64),
        ];
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php';

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

$now = 1_800_000_000;
$session = RuntimePrimaryStagingRequestSessionConfig::fromApplicationConfig([
    'staging_db_primary_request_session' => [
        'enabled' => true,
        'contract_version' => RuntimePrimaryStagingRequestSessionConfig::CONTRACT_VERSION,
        'allowed_entrypoints' => ['api'],
        'baseline_revision' => 1,
        'max_revision_delta' => 3,
        'max_worker_ticks' => 3,
        'lease_seconds' => 60,
        'expires_at_utc' => gmdate(DATE_ATOM, $now + 900),
    ],
]);
$event = static function (int $revision, string $sha, string $status = 'completed'): array {
    return [
        'state_revision' => $revision,
        'state_sha256' => $sha,
        'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
        'status' => $status,
        'attempt_count' => $status === 'completed' ? 1 : 0,
        'lease_token' => '',
        'lease_expires_at_utc' => '',
        'last_error' => '',
    ];
};
$baseline = static function (RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage $json, string $stateSha): array {
    $jsonEvidence = RuntimePrimaryJsonEvidence::capture($json);
    return [
        'state_revision' => 1,
        'state_sha256' => $stateSha,
        'json_sha256' => $jsonEvidence['sha256'],
        'inventory_fingerprint' => $jsonEvidence['inventory_fingerprint'],
    ];
};

$json = new RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage(['users' => ['u1' => []]]);
$dbStorage = new DatabasePrimaryStateStorageAdapter(1, ['users' => ['u1' => []]]);
$db = new RuntimePrimaryStagingRequestSessionReadinessTestDatabase([
    $event(1, $dbStorage->sha()),
]);
$report = (new RuntimePrimaryStagingRequestSessionReadiness(
    $json,
    $db,
    $dbStorage,
    new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(),
    $session,
    $now
))->assertReady($baseline($json, $dbStorage->sha()));
$assertTrue(($report['current_state_revision'] ?? 0) === 1, 'Baseline session readiness must pass');
$assertTrue(($report['revision_delta'] ?? -1) === 0, 'Baseline session must report zero revision delta');

$json = new RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage(['users' => ['u1' => []]]);
$baselineStorage = new DatabasePrimaryStateStorageAdapter(1, ['users' => ['u1' => []]]);
$dbStorage = new DatabasePrimaryStateStorageAdapter(3, ['users' => ['u1' => ['balance' => 20]]]);
$db = new RuntimePrimaryStagingRequestSessionReadinessTestDatabase([
    $event(1, $baselineStorage->sha()),
    $event(2, str_repeat('2', 64)),
    $event(3, $dbStorage->sha()),
]);
$report = (new RuntimePrimaryStagingRequestSessionReadiness(
    $json,
    $db,
    $dbStorage,
    new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(),
    $session,
    $now
))->assertReady($baseline($json, $baselineStorage->sha()));
$assertTrue(($report['current_state_revision'] ?? 0) === 3, 'Advanced completed session revision must pass');
$assertTrue(($report['revision_delta'] ?? 0) === 2, 'Advanced session must report exact revision delta');
$assertTrue(($report['remaining_session_revisions'] ?? -1) === 1, 'Advanced session must report remaining revision capacity');

$pendingDb = new RuntimePrimaryStagingRequestSessionReadinessTestDatabase([
    $event(1, $baselineStorage->sha()),
    $event(2, str_repeat('2', 64)),
    $event(3, $dbStorage->sha(), 'pending'),
]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $json,
        $pendingDb,
        $dbStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(),
        $session,
        $now
    ))->assertReady($baseline($json, $baselineStorage->sha())),
    'not cleanly completed'
);

$overflowStorage = new DatabasePrimaryStateStorageAdapter(5, ['sequence' => 5]);
$overflowEvents = [];
for ($revision = 1; $revision <= 5; $revision++) {
    $overflowEvents[] = $event(
        $revision,
        $revision === 1 ? $baselineStorage->sha() : ($revision === 5 ? $overflowStorage->sha() : str_repeat((string)$revision, 64))
    );
}
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $json,
        new RuntimePrimaryStagingRequestSessionReadinessTestDatabase($overflowEvents),
        $overflowStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(),
        $session,
        $now
    ))->assertReady($baseline($json, $baselineStorage->sha())),
    'exceeds the bounded request session'
);

$badBaseline = $baseline($json, $baselineStorage->sha());
$badBaseline['state_sha256'] = str_repeat('0', 64);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $json,
        $db,
        $dbStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(),
        $session,
        $now
    ))->assertReady($badBaseline),
    'not cleanly completed'
);

$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $json,
        $db,
        $dbStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(false),
        $session,
        $now
    ))->assertReady($baseline($json, $baselineStorage->sha())),
    'audit did not pass exact parity'
);

$jsonDrift = new RuntimePrimaryStagingRequestSessionReadinessTestJsonStorage(['users' => ['u1' => []]]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $jsonDrift,
        $db,
        $dbStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(true, $jsonDrift),
        $session,
        $now
    ))->assertReady($baseline($jsonDrift, $baselineStorage->sha())),
    'does not match the request session baseline'
);

$dbDriftStorage = new DatabasePrimaryStateStorageAdapter(3, ['users' => ['u1' => ['balance' => 20]]]);
$dbDrift = new RuntimePrimaryStagingRequestSessionReadinessTestDatabase([
    $event(1, $baselineStorage->sha()),
    $event(2, str_repeat('2', 64)),
    $event(3, $dbDriftStorage->sha()),
]);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingRequestSessionReadiness(
        $json,
        $dbDrift,
        $dbDriftStorage,
        new RuntimePrimaryStagingRequestSessionReadinessTestAuditor(true, null, $dbDriftStorage),
        $session,
        $now
    ))->assertReady($baseline($json, $baselineStorage->sha())),
    'state changed during request session readiness audit'
);

fwrite(STDOUT, "RuntimePrimaryStagingRequestSessionReadinessTest passed: {$assertions} assertions.\n");
