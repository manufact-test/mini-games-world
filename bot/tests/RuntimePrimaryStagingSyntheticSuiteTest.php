<?php
declare(strict_types=1);

if (!function_exists('now_iso')) {
    function now_iso(): string { return gmdate(DATE_ATOM); }
}
if (!function_exists('make_id')) {
    function make_id(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(8)); }
}
if (!function_exists('clean_string')) {
    function clean_string(mixed $value, int $maxLength = 255): string
    {
        return substr(trim((string)$value), 0, $maxLength);
    }
}

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/bot/storage/contracts/StorageTransactionInterface.php';
require $projectRoot . '/bot/storage/contracts/StorageAdapterInterface.php';
require $projectRoot . '/bot/database/DatabaseConnectionInterface.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStateSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxSchemaInstaller.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryProjectionOutboxWriter.php';
require $projectRoot . '/bot/runtime/DatabasePrimaryStateStorageAdapter.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingStorageResolution.php';
require $projectRoot . '/bot/runtime/RuntimePrimarySyntheticRollback.php';
require $projectRoot . '/bot/services/UserService.php';
require $projectRoot . '/bot/services/NotificationService.php';
require $projectRoot . '/bot/services/WeeklyMatchEconomyService.php';
require $projectRoot . '/bot/runtime/RuntimePrimaryStagingSyntheticSuite.php';

final class RuntimePrimaryStagingSyntheticSuiteTestDatabase implements DatabaseConnectionInterface
{
    public array $stateRow;
    public array $queueRows;
    public bool $driftAfterRollback = false;
    public int $executeCalls = 0;

    public function __construct(array $snapshot, string $stateSha)
    {
        $this->stateRow = [
            'singleton_id' => 1,
            'revision' => 3,
            'state_json' => self::canonicalJson($snapshot),
            'state_sha256' => $stateSha,
            'created_at_utc' => '2026-07-20T16:00:00+00:00',
            'updated_at_utc' => '2026-07-20T17:00:00+00:00',
        ];
        $this->queueRows = [[
            'status' => 'completed',
            'event_count' => 3,
            'min_revision' => 1,
            'max_revision' => 3,
        ]];
    }

    public function driver(): string { return 'mysql'; }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeCalls++;
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'update mgw_runtime_primary_state')) {
            $this->stateRow['revision'] = (int)$parameters['next_revision'];
            $this->stateRow['state_json'] = (string)$parameters['state_json'];
            $this->stateRow['state_sha256'] = (string)$parameters['state_sha256'];
            $this->stateRow['updated_at_utc'] = (string)$parameters['updated_at_utc'];
            return 1;
        }
        if (str_starts_with($normalized, 'insert into mgw_runtime_primary_projection_outbox')) {
            throw new RuntimeException('Synthetic rollback must occur before an outbox insert.');
        }
        throw new RuntimeException('Unexpected synthetic suite mutation: ' . $normalized);
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? '');
        if (str_starts_with($normalized, 'select singleton_id, revision, state_json, state_sha256')) {
            return [$this->stateRow];
        }
        if (str_starts_with($normalized, 'select status, count(*) as event_count,')) {
            return $this->queueRows;
        }
        throw new RuntimeException('Unexpected synthetic suite query: ' . $normalized);
    }

    public function fetchValue(string $sql, array $parameters = []): mixed { return null; }

    public function transaction(callable $callback): mixed
    {
        $beforeState = $this->stateRow;
        $beforeQueue = $this->queueRows;
        try {
            return $callback($this);
        } catch (Throwable $error) {
            $this->stateRow = $beforeState;
            $this->queueRows = $beforeQueue;
            if ($this->driftAfterRollback) {
                $this->stateRow['revision'] = (int)$beforeState['revision'] + 1;
            }
            throw $error;
        }
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
$canonicalJson = static function (array $value): string {
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        foreach ($item as $key => $child) $item[$key] = $canonicalize($child);
        return $item;
    };
    return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};

$snapshot = [
    'users' => [], 'games' => [], 'queue' => [], 'invites' => [],
    'notifications' => [], 'transactions' => [], 'shop_orders' => [],
    'payments' => [], 'support' => [], 'system' => ['sequence' => 1],
];
$stateSha = hash('sha256', $canonicalJson($snapshot));
$database = new RuntimePrimaryStagingSyntheticSuiteTestDatabase($snapshot, $stateSha);
$storage = new DatabasePrimaryStateStorageAdapter(
    $database,
    new RuntimePrimaryProjectionOutboxWriter()
);
$status = $storage->status();
$resolution = new RuntimePrimaryStagingStorageResolution($storage, [
    'activation_allowed' => true,
    'read_only_audit' => true,
    'drift_check_passed' => true,
    'state_revision' => 3,
    'state_sha256' => $stateSha,
    'repository_commit' => str_repeat('a', 40),
    'database_identity_fingerprint' => str_repeat('b', 64),
    'evidence_fingerprint' => str_repeat('c', 64),
    'all_module_fingerprint' => str_repeat('d', 64),
], $status);
$config = [
    'environment' => 'staging',
    'initial_match_coins' => 100,
    'initial_gold_coins' => 25,
    'weekly_match_bonus_amount' => 50,
    'weekly_match_min_completed' => 3,
    'weekly_match_timezone' => 'Europe/Moscow',
    'weekly_match_start_at' => '2026-07-13 12:00:00',
    'admin_ids' => [],
    'shop_min_order' => 1000,
];

$suite = new RuntimePrimaryStagingSyntheticSuite($config, $resolution, $database);
$result = $suite->run();
$assertTrue(($result['ok'] ?? false) === true, 'Synthetic suite must pass');
$assertTrue(($result['action'] ?? '') === 'synthetic_suite_passed_and_rolled_back', 'Synthetic suite must report rollback success');
$assertTrue(($result['transaction_rolled_back'] ?? false) === true, 'Synthetic transaction must roll back');
$assertTrue(($result['state_unchanged'] ?? false) === true, 'State status must remain unchanged');
$assertTrue(($result['snapshot_unchanged'] ?? false) === true, 'State snapshot must remain unchanged');
$assertTrue(($result['outbox_unchanged'] ?? false) === true, 'Outbox must remain unchanged');
$assertTrue(($result['scenario']['synthetic_user_count'] ?? 0) === 2, 'Synthetic suite must exercise two users');
$assertTrue(($result['scenario']['synthetic_notification_count'] ?? 0) >= 3, 'Synthetic suite must exercise notifications');
$assertTrue(($result['scenario']['synthetic_transaction_count'] ?? 0) === 1, 'Synthetic suite must exercise welcome economy transaction');
$assertTrue($database->executeCalls === 0, 'Rollback signal must occur before state or outbox writes');
$assertTrue(($result['application_entrypoint_routed'] ?? true) === false, 'Synthetic suite must not route real entrypoints');
$assertTrue(($result['production_changed'] ?? true) === false, 'Synthetic suite must not claim production mutation');

$driftDatabase = new RuntimePrimaryStagingSyntheticSuiteTestDatabase($snapshot, $stateSha);
$driftDatabase->driftAfterRollback = true;
$driftStorage = new DatabasePrimaryStateStorageAdapter(
    $driftDatabase,
    new RuntimePrimaryProjectionOutboxWriter()
);
$driftResolution = new RuntimePrimaryStagingStorageResolution(
    $driftStorage,
    [
        'activation_allowed' => true,
        'read_only_audit' => true,
        'drift_check_passed' => true,
        'state_revision' => 3,
        'state_sha256' => $stateSha,
    ],
    $driftStorage->status()
);
$assertThrows(
    static fn() => (new RuntimePrimaryStagingSyntheticSuite(
        $config,
        $driftResolution,
        $driftDatabase
    ))->run(),
    'state status changed after synthetic rollback'
);

$productionConfig = $config;
$productionConfig['environment'] = 'production';
$assertThrows(
    static fn() => new RuntimePrimaryStagingSyntheticSuite(
        $productionConfig,
        $resolution,
        $database
    ),
    'staging-only'
);

fwrite(STDOUT, "RuntimePrimaryStagingSyntheticSuiteTest passed: {$assertions} assertions.\n");
