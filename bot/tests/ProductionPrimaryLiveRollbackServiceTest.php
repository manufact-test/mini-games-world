<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/runtime/ProductionPrimaryLiveRollbackDependencies.php';

final class ProductionLiveRollbackTestDatabase implements DatabaseConnectionInterface
{
    public int $transactionCount = 0;
    public int $executeCount = 0;
    public array $stateRow;
    public array $outboxRows;

    public function __construct(array $snapshot, int $revision)
    {
        $stateJson = self::canonicalJson($snapshot);
        $stateSha = hash('sha256', $stateJson);
        $this->stateRow = [
            'singleton_id' => 1,
            'revision' => $revision,
            'state_json' => $stateJson,
            'state_sha256' => $stateSha,
            'created_at_utc' => '2026-07-23T15:00:00+00:00',
            'updated_at_utc' => '2026-07-23T16:00:00+00:00',
        ];
        $this->outboxRows = [[
            'status' => 'completed',
            'event_count' => $revision,
            'min_revision' => 1,
            'max_revision' => $revision,
        ]];
    }

    public function driver(): string { return 'mysql'; }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeCount++;
        throw new RuntimeException('Live rollback test forbids SQL writes.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'where singleton_id = 1 for update')) {
            return [$this->stateRow];
        }
        if (str_contains($normalized, 'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE)
            && str_contains($normalized, 'group by status')) {
            return $this->outboxRows;
        }
        throw new RuntimeException('Unexpected live rollback test query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        if (trim($sql) === 'SELECT 1') return 1;
        throw new RuntimeException('Unexpected live rollback scalar query.');
    }

    public function transaction(callable $callback): mixed
    {
        $this->transactionCount++;
        return $callback($this);
    }

    private static function canonicalJson(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
            return $item;
        };
        return json_encode(
            $canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}

final class ProductionLiveRollbackTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public int $calls = 0;
    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        return [
            'ok' => true,
            'parity_ok' => true,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => hash(
                'sha256',
                'live-rollback-audit|' . $stateRevision . '|' . $stateSha256
            ),
        ];
    }
}

$assertions = 0;
$assertTrue = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Test directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Test file could not be removed.');
};
$writePrivate = static function (string $path, string $raw): void {
    $written = file_put_contents($path, $raw, LOCK_EX);
    if ($written !== strlen($raw) || !chmod($path, 0600)) {
        throw new RuntimeException('Test private file could not be written.');
    }
};
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
};

$root = sys_get_temp_dir() . '/mgw-live-rollback-service-' . bin2hex(random_bytes(6));
$project = $root . '/public_html';
$private = $root . '/private';
$live = $root . '/live-json';
$exportRoot = $root . '/exports';
try {
    foreach ([$root, $project, $private, $live, $exportRoot] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Test directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Test directory permissions could not be secured.');
        }
    }
    file_put_contents($project . '/index.php', '<?php echo "test";');

    $oldSnapshot = [
        'users' => [['id' => 'old_user']],
        'games' => [], 'queue' => [], 'transactions' => [], 'support' => [],
        'shop_orders' => [], 'payments' => [], 'notifications' => [], 'invites' => [],
        'system' => ['fees_match' => 1, 'fees_gold' => 1],
    ];
    $newSnapshot = [
        'users' => [[
            'id' => 'current_user',
            'balance_match' => 80,
            'balance_gold' => 14,
        ]],
        'games' => [[
            'id' => 'game_live_1',
            'status' => 'finished',
            'winner_id' => 'current_user',
        ]],
        'queue' => [],
        'transactions' => [['id' => 'tx_live_1', 'amount' => 14]],
        'support' => [], 'shop_orders' => [], 'payments' => [],
        'notifications' => [['id' => 'notice_live_1']],
        'invites' => [],
        'system' => ['fees_match' => 5, 'fees_gold' => 3],
    ];
    $dataFiles = [
        'users' => 'users.json', 'games' => 'games.json', 'queue' => 'queue.json',
        'transactions' => 'transactions.json', 'support' => 'support.json',
        'shop_orders' => 'shop_orders.json', 'payments' => 'payments.json',
        'notifications' => 'notifications.json', 'invites' => 'invites.json',
        'system' => 'system.json',
    ];
    foreach ($dataFiles as $key => $file) {
        $writePrivate(
            $live . '/' . $file,
            json_encode(
                $oldSnapshot[$key],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        );
    }
    $writePrivate($live . '/app.lock', '');

    $revision = 4;
    $stateSha = hash('sha256', $canonicalJson($newSnapshot));
    $database = new ProductionLiveRollbackTestDatabase($newSnapshot, $revision);
    $auditor = new ProductionLiveRollbackTestAuditor();
    $verifier = new ProductionPrimaryRollbackExportVerifier();
    $requestId = str_repeat('a', 32);
    $plan = str_repeat('b', 64);
    $source = str_repeat('c', 64);
    $databaseIdentity = str_repeat('d', 64);
    $exportGate = [
        'ready' => true,
        'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
        'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'request_id' => $requestId,
        'expected_state_revision' => $revision,
        'expected_state_sha256' => $stateSha,
        'database_identity_fingerprint' => $databaseIdentity,
        'activation_plan_fingerprint' => $plan,
        'activation_source_fingerprint' => $source,
        'output_root_fingerprint' => hash('sha256', realpath($exportRoot) ?: $exportRoot),
        'reason_fingerprint' => hash('sha256', 'End-to-end test export.'),
        'authorization_expires_at_utc' => '2026-07-23T17:00:00+00:00',
    ];
    $exportResult = (new ProductionPrimaryRollbackExportService(
        $database,
        $auditor,
        $verifier
    ))->export(
        realpath($project) ?: $project,
        realpath($exportRoot) ?: $exportRoot,
        $exportGate
    );
    $exportDir = $exportRoot . '/rollback-' . $requestId;
    $artifact = (new ProductionPrimaryRollbackArtifactIdentity($verifier))->inspect($exportDir);
    $assertSame($stateSha, $artifact['state_sha256'], 'Export artifact must match current DB state');

    $modules = array_fill_keys([
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ], true);
    $runtimeFile = $private . '/runtime.php';
    $runtime = [
        'maintenance_mode' => true,
        'financial_read_only' => true,
        'database_runtime' => [
            'enabled' => true,
            'production_activated' => true,
            'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
            'activation_plan_fingerprint' => $plan,
            'activation_source_fingerprint' => $source,
            'activated_at_utc' => '2026-07-23T16:00:00+00:00',
            'rollback_driver' => 'json',
            'modules' => $modules,
        ],
    ];
    $writePrivate(
        $runtimeFile,
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($runtime, true) . ";\n"
    );
    $cutoverFile = $private . '/production-cutover.json';
    $cutover = [
        'state' => 'completed',
        'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'plan_fingerprint' => $plan,
        'source_fingerprint' => $source,
        'runtime_backup_present' => true,
        'database_runtime_published' => true,
        'json_write_block_active' => false,
        'rollback_driver' => 'json',
    ];
    $writePrivate(
        $cutoverFile,
        json_encode($cutover, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );

    $config = [
        'environment' => 'production',
        'storage_driver' => 'json',
        'data_dir' => realpath($live) ?: $live,
        'database' => [
            'enabled' => true,
            'driver' => 'mysql',
            'host' => 'test-db.internal',
            'port' => 3306,
            'name' => 'mgw_test',
            'user' => 'mgw_test',
            'password' => 'test-password',
            'charset' => 'utf8mb4',
        ],
        'feature_flags' => $runtime,
    ];
    $runtimeWriter = new ProductionPrimaryRuntimeOverlayWriter($runtimeFile);
    $stateStore = new ProductionPrimaryLiveRollbackStateStore($private, $cutoverFile);
    $gate = [
        'ready' => true,
        'contract_version' => ProductionPrimaryLiveRollbackGate::CONTRACT_VERSION,
        'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'request_id' => $requestId,
        'expected_state_revision' => $revision,
        'expected_state_sha256' => $stateSha,
        'expected_snapshot_sha256' => $artifact['snapshot_sha256'],
        'expected_backup_id' => $artifact['backup_id'],
        'database_identity_fingerprint' => $databaseIdentity,
        'activation_plan_fingerprint' => $plan,
        'activation_source_fingerprint' => $source,
        'export_directory_fingerprint' => $artifact['export_directory_fingerprint'],
        'live_data_directory_fingerprint' => hash('sha256', realpath($live) ?: $live),
        'runtime_config_fingerprint' => $runtimeWriter->fingerprint(),
        'cutover_state_fingerprint' => $stateStore->cutoverFingerprint(),
    ];
    $backupManager = new BackupManager(
        realpath($project) ?: $project,
        realpath($live) ?: $live,
        realpath($exportRoot) ?: $exportRoot,
        null,
        7,
        7,
        false
    );
    $restore = new ProductionPrimaryRollbackRestoreService($backupManager, $verifier);
    $service = new ProductionPrimaryLiveRollbackService(
        $database,
        $auditor,
        new ProductionPrimaryRollbackArtifactIdentity($verifier),
        $verifier,
        $restore,
        $runtimeWriter,
        $stateStore,
        realpath($private) ?: $private
    );
    $result = $service->execute(
        realpath($exportDir) ?: $exportDir,
        realpath($live) ?: $live,
        $config,
        $gate
    );

    $assertSame(true, $result['ok'], 'Live rollback must complete');
    $assertSame(false, $result['database_route_enabled'], 'Live rollback must disable DB route');
    $assertSame(false, $result['json_write_block_active'], 'Live rollback must release JSON seal');
    $assertSame(false, $result['maintenance_enabled'], 'Live rollback must release maintenance');
    $assertSame(false, $result['financial_read_only'], 'Live rollback must release financial read-only');
    $assertSame(true, $result['previous_json_retained'], 'Live rollback must retain previous JSON');
    $assertSame(false, $result['database_write_executed'], 'Live rollback must execute no SQL writes');
    $assertSame(0, $database->executeCount, 'Fake database must observe zero SQL writes');
    $assertSame(2, $database->transactionCount, 'Export and live rollback must each use one DB transaction');
    $assertSame(2, $auditor->calls, 'Export and final rollback must each audit projections once');

    $liveUsers = json_decode(file_get_contents($live . '/users.json') ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    $assertSame(
        $canonicalJson($newSnapshot['users']),
        $canonicalJson($liveUsers),
        'Live users must match fresh DB export'
    );
    $previousDir = dirname($live) . '/.mgw-json-before-live-rollback-' . $requestId;
    $assertTrue(is_dir($previousDir), 'Previous JSON directory must remain available');
    $previousUsers = json_decode(
        file_get_contents($previousDir . '/users.json') ?: '[]',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $assertSame($oldSnapshot['users'], $previousUsers, 'Previous JSON must remain unchanged');
    $assertTrue(!file_exists($live . '/.cutover-write-block'), 'Live JSON write block must be absent');

    $releasedRuntime = $runtimeWriter->load();
    $assertSame(false, $releasedRuntime['database_runtime']['enabled'], 'Released runtime must disable DB');
    $assertSame(false, $releasedRuntime['database_runtime']['production_activated'], 'Released activation marker must be false');
    $assertSame(false, $releasedRuntime['maintenance_mode'], 'Released runtime maintenance must be false');
    $assertSame('completed', $releasedRuntime['production_live_rollback']['state'], 'Runtime rollback marker must complete');
    $finalCutover = json_decode(file_get_contents($cutoverFile) ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $assertSame('rolled_back', $finalCutover['state'], 'Cutover state must be rolled_back');
    $assertSame(false, $finalCutover['database_runtime_published'], 'Cutover DB route marker must be false');
    $assertSame('completed', $stateStore->recovery()['state'], 'Recovery state must complete');

    $second = $service->execute(
        realpath($exportDir) ?: $exportDir,
        realpath($live) ?: $live,
        $config,
        $gate
    );
    $assertSame(true, $second['idempotent'], 'Completed live rollback must be idempotent');
    $assertSame(false, $second['production_changed'], 'Idempotent verification must not change production');
} finally {
    $remove($root);
}

fwrite(STDOUT, "ProductionPrimaryLiveRollbackServiceTest passed: {$assertions} assertions.\n");
