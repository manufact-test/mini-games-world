<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require_once $projectRoot . '/bot/runtime/RuntimePrimaryProjectionAuditorInterface.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportGate.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportVerifier.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackExportService.php';
require_once $projectRoot . '/ops/backup/BackupManager.php';
require_once $projectRoot . '/bot/runtime/ProductionPrimaryRollbackRestoreService.php';

final class ProductionRollbackExportTestDatabase implements DatabaseConnectionInterface
{
    public int $transactionCount = 0;
    public int $executeCount = 0;
    public array $stateRow;
    public array $outboxRows;

    public function __construct(array $snapshot, int $revision)
    {
        $stateJson = self::canonicalJson($snapshot);
        $this->stateRow = [
            'singleton_id' => 1,
            'revision' => $revision,
            'state_json' => $stateJson,
            'state_sha256' => hash('sha256', $stateJson),
            'created_at_utc' => '2026-07-23T14:00:00+00:00',
            'updated_at_utc' => '2026-07-23T15:00:00+00:00',
        ];
        $this->outboxRows = [[
            'status' => 'completed',
            'event_count' => $revision,
            'min_revision' => 1,
            'max_revision' => $revision,
        ]];
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeCount++;
        throw new RuntimeException('Rollback export must not execute SQL writes.');
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_contains($normalized, 'from ' . RuntimePrimaryStateSchemaInstaller::TABLE)
            && str_contains($normalized, 'for update')) {
            return [$this->stateRow];
        }
        if (str_contains(
            $normalized,
            'from ' . RuntimePrimaryProjectionOutboxSchemaInstaller::TABLE
        ) && str_contains($normalized, 'group by status')) {
            return $this->outboxRows;
        }
        throw new RuntimeException('Unexpected rollback export test query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed
    {
        throw new RuntimeException('Rollback export test does not use scalar queries.');
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

final class ProductionRollbackExportTestAuditor implements RuntimePrimaryProjectionAuditorInterface
{
    public int $calls = 0;
    public bool $parity = true;

    public function auditOnly(array $snapshot, int $stateRevision, string $stateSha256): array
    {
        $this->calls++;
        return [
            'ok' => $this->parity,
            'parity_ok' => $this->parity,
            'read_only' => true,
            'state_revision' => $stateRevision,
            'state_sha256' => $stateSha256,
            'projected_modules' => [
                'accounts', 'realtime', 'economy', 'notifications', 'invites',
                'history', 'shop', 'payments', 'weekly_bonus',
            ],
            'all_module_fingerprint' => hash(
                'sha256',
                'rollback-audit|' . $stateRevision . '|' . $stateSha256
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
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
        );
    }
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
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (!is_array($items)) throw new RuntimeException('Test directory could not be listed.');
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $remove($path . '/' . $name);
        }
        if (!rmdir($path)) throw new RuntimeException('Test directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('Test file could not be removed.');
};
$mode = static function (string $path): int {
    clearstatcache(true, $path);
    $value = fileperms($path);
    return is_int($value) ? ($value & 0777) : -1;
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

$root = sys_get_temp_dir() . '/mgw-production-rollback-export-' . bin2hex(random_bytes(6));
$deployedProject = $root . '/public_html';
$liveJson = $root . '/live-json';
$outputRoot = $root . '/rollback-exports';
$backupRoot = $root . '/backup-manager-root';
$restoreTarget = $root . '/isolated-restore';

try {
    foreach ([$root, $deployedProject, $liveJson, $outputRoot, $backupRoot] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Test directory could not be created.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Test directory permissions could not be secured.');
        }
    }
    file_put_contents($deployedProject . '/index.php', '<?php echo "test";');
    file_put_contents($liveJson . '/users.json', '[]');

    $snapshot = [
        'users' => [[
            'id' => 'tg_100',
            'balance_match' => 73,
            'balance_gold' => 12,
        ]],
        'games' => [[
            'id' => 'game_100',
            'status' => 'finished',
            'winner_id' => 'tg_100',
        ]],
        'queue' => [],
        'transactions' => [[
            'id' => 'tx_100',
            'user_id' => 'tg_100',
            'amount' => 12,
        ]],
        'support' => [],
        'shop_orders' => [],
        'payments' => [],
        'notifications' => [[
            'id' => 'notification_100',
            'user_id' => 'tg_100',
            'title' => 'Готово',
        ]],
        'invites' => [],
        'system' => [
            'fees_match' => 4,
            'fees_gold' => 2,
        ],
    ];
    $revision = 3;
    $stateSha = hash('sha256', $canonicalJson($snapshot));
    $database = new ProductionRollbackExportTestDatabase($snapshot, $revision);
    $auditor = new ProductionRollbackExportTestAuditor();
    $verifier = new ProductionPrimaryRollbackExportVerifier();

    $plan = str_repeat('a', 64);
    $source = str_repeat('b', 64);
    $databaseIdentity = str_repeat('c', 64);
    $outputFingerprint = hash('sha256', realpath($outputRoot) ?: $outputRoot);
    $modules = array_fill_keys([
        'accounts', 'realtime', 'invites', 'notifications', 'economy',
        'history', 'shop', 'payments', 'weekly_bonus',
    ], true);
    $config = [
        'environment' => 'production',
        'storage_driver' => 'json',
        'feature_flags' => [
            'maintenance_mode' => true,
            'financial_read_only' => true,
            'database_runtime' => [
                'enabled' => true,
                'production_activated' => true,
                'activation_build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
                'activation_plan_fingerprint' => $plan,
                'activation_source_fingerprint' => $source,
                'rollback_driver' => 'json',
                'modules' => $modules,
            ],
        ],
    ];
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
    $authorization = [
        'contract_version' => ProductionPrimaryRollbackExportGate::CONTRACT_VERSION,
        'authorized' => true,
        'environment' => 'production',
        'build' => ProductionPrimaryRollbackExportGate::ACTIVATION_BUILD,
        'request_id' => str_repeat('d', 32),
        'requested_at_utc' => '2026-07-23T14:59:00+00:00',
        'expires_at_utc' => '2026-07-23T15:09:00+00:00',
        'expected_state_revision' => $revision,
        'expected_state_sha256' => $stateSha,
        'database_identity_fingerprint' => $databaseIdentity,
        'activation_plan_fingerprint' => $plan,
        'activation_source_fingerprint' => $source,
        'output_root_fingerprint' => $outputFingerprint,
        'reason' => 'Controlled test rollback export.',
    ];
    $now = (new DateTimeImmutable('2026-07-23T15:00:00+00:00'))->getTimestamp();
    $gate = (new ProductionPrimaryRollbackExportGate())->inspect(
        $config,
        $cutover,
        $authorization,
        $databaseIdentity,
        $outputFingerprint,
        $now
    );
    $assertSame(true, $gate['ready'], 'End-to-end rollback gate must pass');

    $service = new ProductionPrimaryRollbackExportService(
        $database,
        $auditor,
        $verifier
    );
    $result = $service->export(
        realpath($deployedProject) ?: $deployedProject,
        realpath($outputRoot) ?: $outputRoot,
        $gate
    );
    $assertSame(true, $result['ok'], 'Rollback export must succeed');
    $assertSame($revision, $result['state_revision'], 'Export revision must match DB state');
    $assertSame($stateSha, $result['state_sha256'], 'Export state hash must match DB state');
    $assertSame(false, $result['database_write_executed'], 'Export must report no DB writes');
    $assertSame(false, $result['live_json_changed'], 'Export must not touch live JSON');
    $assertSame(1, $database->transactionCount, 'Export must use one outer DB transaction');
    $assertSame(0, $database->executeCount, 'Export must execute no SQL writes');
    $assertSame(1, $auditor->calls, 'Export must run one all-module parity audit');

    $exportDir = $outputRoot . '/rollback-' . str_repeat('d', 32);
    $assertTrue(is_dir($exportDir), 'Final rollback export directory must exist');
    $assertSame(0700, $mode($exportDir), 'Rollback export directory must be private');
    $assertSame(0700, $mode($exportDir . '/data'), 'Rollback data directory must be private');
    $assertSame(0600, $mode($exportDir . '/manifest.json'), 'Rollback manifest must be private');
    $assertSame(0600, $mode($exportDir . '/data/users.json'), 'Rollback data file must be private');

    $verified = $verifier->verify($exportDir);
    $assertSame(true, $verified['ok'], 'Created rollback export must verify');
    $assertSame(14, $verified['verified_files'], 'Verifier must enforce exact export file set');
    $assertSame($stateSha, $verified['state_sha256'], 'Verifier must reconstruct exact DB state hash');
    $assertSame(true, $verified['backup_manager_compatible'], 'Export must be BackupManager-compatible');

    $backupManager = new BackupManager(
        realpath($deployedProject) ?: $deployedProject,
        realpath($liveJson) ?: $liveJson,
        realpath($backupRoot) ?: $backupRoot,
        null,
        7,
        7,
        false
    );
    $backupVerified = $backupManager->verify($exportDir);
    $assertSame(true, $backupVerified['ok'], 'Existing BackupManager must verify DB rollback export');
    $assertSame(
        $verified['snapshot_sha256'],
        $backupVerified['snapshot_sha256'],
        'BackupManager and rollback verifier must agree on snapshot identity'
    );

    $restoreService = new ProductionPrimaryRollbackRestoreService(
        $backupManager,
        $verifier
    );
    $restored = $restoreService->restoreIsolated($exportDir, $restoreTarget);
    $assertSame(true, $restored['target_ready'], 'Isolated rollback restore must be ready');
    $assertSame(false, $restored['target_live'], 'Rollback restore target must remain isolated');
    $assertSame($stateSha, $restored['state_sha256'], 'Restored JSON must match DB state hash');
    $assertSame(0700, $mode($restoreTarget), 'Isolated restore directory must be private');
    $assertSame(0600, $mode($restoreTarget . '/users.json'), 'Restored user file must be private');
    $restoredUsers = json_decode(
        file_get_contents($restoreTarget . '/users.json') ?: '[]',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $assertSame($snapshot['users'], $restoredUsers, 'Restored users must match DB snapshot exactly');
    $assertSame('[]', trim(file_get_contents($liveJson . '/users.json') ?: ''), 'Live JSON must remain unchanged');

    file_put_contents($exportDir . '/data/users.json', "[]\n", LOCK_EX);
    chmod($exportDir . '/data/users.json', 0600);
    $assertThrows(
        static fn() => $verifier->verify($exportDir),
        'checksum mismatch'
    );
} finally {
    $remove($root);
}

fwrite(
    STDOUT,
    "ProductionPrimaryRollbackExportServiceTest passed: {$assertions} assertions.\n"
);
