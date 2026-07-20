<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/database/DatabaseConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionProjectorInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryModuleProjectorInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryAllModuleProjector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryEntrypointEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryRepositoryCommitResolver.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceVerifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Verifier.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingEvidenceV2Gate.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryPrivateConfigGuard.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryJsonEvidence.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSchemaInspector.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationConfig.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationEvidenceLoader.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingActivationGuard.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceManifestFixture.php';
require $projectRoot . '/bot/tests/support/RuntimePrimaryStagingEvidenceV2ManifestFixture.php';

final class RuntimePrimaryStagingActivationTestStorage implements StorageAdapterInterface
{
    public int $transactionCalls = 0;
    public function __construct(public array $snapshot) {}
    public function driver(): string { return 'json'; }
    public function transaction(callable $callback): mixed
    {
        $this->transactionCalls++;
        throw new RuntimeException('Activation guard must not open JSON write transactions.');
    }
    public function readOnly(callable $callback): mixed { return $callback($this->snapshot); }
}

final class RuntimePrimaryStagingActivationTestDatabase implements DatabaseConnectionInterface
{
    public int $executeCalls = 0;
    public array $stateRow;
    public array $eventRow;
    public array $queueRows;
    public bool $invalidOutboxType = false;

    public function __construct(array $snapshot, string $stateSha)
    {
        $canonical = self::canonicalJson($snapshot);
        $this->stateRow = [
            'singleton_id' => 1,
            'revision' => 1,
            'state_json' => $canonical,
            'state_sha256' => $stateSha,
            'created_at_utc' => '2026-07-20T17:00:00+00:00',
            'updated_at_utc' => '2026-07-20T17:10:00+00:00',
        ];
        $this->eventRow = [
            'state_revision' => 1,
            'state_sha256' => $stateSha,
            'projection_version' => RuntimePrimaryProjectionOutboxWriter::PROJECTION_VERSION,
            'status' => 'completed',
            'attempt_count' => 1,
            'lease_token' => '',
            'lease_expires_at_utc' => '',
            'last_error' => '',
        ];
        $this->queueRows = [[
            'status' => 'completed',
            'event_count' => 1,
            'min_revision' => 1,
            'max_revision' => 1,
        ]];
    }

    public function driver(): string { return 'mysql'; }
    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeCalls++;
        throw new RuntimeException('Activation guard attempted a database mutation.');
    }
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if ($normalized === 'show columns from mgw_runtime_primary_state') return $this->stateColumns();
        if ($normalized === 'show columns from mgw_runtime_primary_projection_outbox') return $this->outboxColumns();
        if (str_starts_with($normalized, "show table status like 'mgw_runtime_primary_state'")) return [['Engine' => 'InnoDB']];
        if (str_starts_with($normalized, "show table status like 'mgw_runtime_primary_projection_outbox'")) return [['Engine' => 'InnoDB']];
        if (str_starts_with($normalized, 'select singleton_id, revision, state_json, state_sha256')) return [$this->stateRow];
        if (str_starts_with($normalized, 'select state_revision, state_sha256, projection_version, status,')) return [$this->eventRow];
        if (str_starts_with($normalized, 'select status, count(*) as event_count,')) return $this->queueRows;
        throw new RuntimeException('Unexpected activation test query: ' . $normalized);
    }
    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }
    public function transaction(callable $callback): mixed { return $callback($this); }

    private function stateColumns(): array
    {
        return [
            ['Field' => 'singleton_id', 'Type' => 'tinyint unsigned', 'Null' => 'NO', 'Key' => 'PRI'],
            ['Field' => 'revision', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'state_json', 'Type' => 'longtext', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'state_sha256', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'created_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'updated_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
        ];
    }
    private function outboxColumns(): array
    {
        return [
            ['Field' => 'state_revision', 'Type' => 'bigint unsigned', 'Null' => 'NO', 'Key' => 'PRI'],
            ['Field' => 'event_id', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'projection_version', 'Type' => 'varchar(64)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'state_sha256', 'Type' => 'char(64)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'state_json', 'Type' => 'longtext', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'status', 'Type' => $this->invalidOutboxType ? 'int' : 'varchar(16)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'attempt_count', 'Type' => 'int unsigned', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'lease_token', 'Type' => 'varchar(64)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'lease_expires_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'last_error', 'Type' => 'varchar(500)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'available_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'created_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
            ['Field' => 'updated_at_utc', 'Type' => 'varchar(40)', 'Null' => 'NO', 'Key' => ''],
        ];
    }
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

final class RuntimePrimaryStagingActivationTestModule implements RuntimePrimaryModuleProjectorInterface
{
    public int $projectCalls = 0;
    public int $auditCalls = 0;
    public function __construct(private string $name, public bool $parity = true) {}
    public function module(): string { return $this->name; }
    public function project(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->projectCalls++;
        throw new RuntimeException('Activation audit must never project.');
    }
    public function audit(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->auditCalls++;
        $fingerprint = hash('sha256', $this->name . '|' . $stateSha256);
        return [
            'ok' => $this->parity,
            'parity' => $this->parity,
            'read_only' => true,
            'module' => $this->name,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'source_fingerprint' => $fingerprint,
            'database_fingerprint' => $fingerprint,
            'summary' => [],
            'blockers' => $this->parity ? [] : ['forced mismatch'],
        ];
    }
}

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
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};
$modules = [
    'accounts', 'realtime', 'economy', 'notifications', 'invites',
    'history', 'shop', 'payments', 'weekly_bonus',
];

$fixture = sys_get_temp_dir() . '/mgw-activation-guard-' . bin2hex(random_bytes(6));
$private = $fixture . '/private';
mkdir($private, 0700, true);
$commit = str_repeat('a', 40);
putenv('MGW_REHEARSAL_COMMIT_SHA=' . $commit);
try {
    $snapshot = [
        'users' => ['100' => ['id' => '100', 'balance' => 50]],
        'games' => [], 'queue' => [], 'invites' => [], 'notifications' => [],
        'transactions' => [], 'shop_orders' => [], 'payments' => [],
        'system' => ['sequence' => 1],
    ];
    $stateSha = hash('sha256', $canonicalJson($snapshot));
    $storage = new RuntimePrimaryStagingActivationTestStorage($snapshot);
    $database = new RuntimePrimaryStagingActivationTestDatabase($snapshot, $stateSha);
    $databaseConfigArray = [
        'database' => [
            'enabled' => true,
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'name' => 'mgw_stage',
            'user' => 'stage_user',
            'password' => 'private-password',
            'charset' => 'utf8mb4',
        ],
    ];
    $databaseIdentity = DatabaseConfig::fromApplicationConfig($databaseConfigArray)->identityFingerprint();
    $jsonEvidence = RuntimePrimaryJsonEvidence::capture($storage);
    $schemas = (new RuntimePrimaryStagingSchemaInspector($database))->inspect();
    $manifest = RuntimePrimaryStagingEvidenceV2ManifestFixture::valid($projectRoot, $commit, $databaseIdentity);
    $manifest['generated_at_utc'] = '2026-07-20T17:50:00+00:00';
    foreach (['before_sha256', 'after_first_sha256', 'after_second_sha256'] as $field) {
        $manifest['source_snapshot'][$field] = $stateSha;
    }
    $manifest['source_snapshot']['inventory_fingerprint'] = $jsonEvidence['inventory_fingerprint'];
    foreach (['first_rehearsal', 'repeated_rehearsal'] as $section) {
        $manifest[$section]['target_revision'] = 1;
        $manifest[$section]['target_sha256'] = $stateSha;
    }
    $manifest['schemas']['state']['schema_fingerprint'] = $schemas['state']['schema_fingerprint'];
    $manifest['schemas']['outbox']['schema_fingerprint'] = $schemas['outbox']['schema_fingerprint'];
    $evidence = (new RuntimePrimaryStagingEvidenceV2Gate($projectRoot))->verify($manifest);
    $assertTrue(($evidence['ok'] ?? false) === true, 'Activation fixture evidence v2 must be valid');

    $evidencePath = $private . '/evidence.json';
    file_put_contents($evidencePath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    chmod($evidencePath, 0600);
    $configFile = $private . '/config.php';
    file_put_contents($configFile, "<?php\nreturn [];\n");
    chmod($configFile, 0600);
    $config = $databaseConfigArray + [
        'environment' => 'staging',
        'staging_db_primary_activation' => [
            'enabled' => true,
            'expected_database_identity_fingerprint' => $databaseIdentity,
            'expected_repository_commit' => $commit,
            'evidence_file' => $evidencePath,
            'expected_evidence_fingerprint' => $evidence['evidence_fingerprint'],
            'approval_expires_at_utc' => '2026-07-20T18:20:00+00:00',
        ],
    ];
    $projectorModules = array_map(
        static fn(string $module): RuntimePrimaryStagingActivationTestModule => new RuntimePrimaryStagingActivationTestModule($module),
        $modules
    );
    $guard = new RuntimePrimaryStagingActivationGuard(
        $projectRoot,
        $config,
        $configFile,
        $storage,
        $database,
        new RuntimePrimaryAllModuleProjector($projectorModules),
        strtotime('2026-07-20T18:00:00+00:00')
    );
    $ready = $guard->assertReady();
    $assertTrue(($ready['activation_allowed'] ?? false) === true, 'Exact live staging state must pass activation readiness');
    $assertTrue(($ready['read_only_audit'] ?? false) === true, 'Activation readiness must use read-only audit');
    $assertTrue(($ready['projected_modules'] ?? []) === $modules, 'Activation readiness must verify all nine modules');
    $assertTrue(($ready['queue']['completed_event_count'] ?? 0) === 1, 'Activation readiness must prove a contiguous completed queue');
    $assertTrue($database->executeCalls === 0, 'Activation guard must not mutate staging DB');
    $assertTrue($storage->transactionCalls === 0, 'Activation guard must not open JSON write transactions');
    foreach ($projectorModules as $module) {
        $assertTrue($module->projectCalls === 0, 'Activation guard must never invoke module projection');
        $assertTrue($module->auditCalls === 1, 'Activation guard must audit each module once');
    }

    $database->eventRow['status'] = 'failed';
    $assertThrows(static fn() => $guard->assertReady(), 'clean completed evidenced revision');
    $database->eventRow['status'] = 'completed';

    $database->queueRows[] = ['status' => 'pending', 'event_count' => 1, 'min_revision' => 2, 'max_revision' => 2];
    $assertThrows(static fn() => $guard->assertReady(), 'non-completed event: pending');
    array_pop($database->queueRows);

    $database->invalidOutboxType = true;
    $assertThrows(static fn() => $guard->assertReady(), 'outbox schema type is invalid: status');
    $database->invalidOutboxType = false;

    $storage->snapshot['system']['sequence'] = 2;
    $assertThrows(static fn() => $guard->assertReady(), 'json rollback source does not match');
    $storage->snapshot['system']['sequence'] = 1;

    $staleConfig = $config;
    $staleManifest = $manifest;
    $staleManifest['generated_at_utc'] = '2026-07-20T10:00:00+00:00';
    file_put_contents($evidencePath, json_encode($staleManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    chmod($evidencePath, 0600);
    $staleEvidence = (new RuntimePrimaryStagingEvidenceV2Gate($projectRoot))->verify($staleManifest);
    $staleConfig['staging_db_primary_activation']['expected_evidence_fingerprint'] = $staleEvidence['evidence_fingerprint'];
    $staleGuard = new RuntimePrimaryStagingActivationGuard(
        $projectRoot,
        $staleConfig,
        $configFile,
        $storage,
        $database,
        new RuntimePrimaryAllModuleProjector(array_map(
            static fn(string $module): RuntimePrimaryStagingActivationTestModule => new RuntimePrimaryStagingActivationTestModule($module),
            $modules
        )),
        strtotime('2026-07-20T18:00:00+00:00')
    );
    $assertThrows(static fn() => $staleGuard->assertReady(), 'older than six hours');
} finally {
    putenv('MGW_REHEARSAL_COMMIT_SHA');
    $remove($fixture);
}

fwrite(STDOUT, "RuntimePrimaryStagingActivationGuardTest passed: {$assertions} assertions.\n");
